<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * A HikariCP-inspired connection pool for Swoole coroutines.
 *
 * Unlike a plain `Swoole\ConnectionPool` (a dumb channel), this pool actively keeps
 * its connections usable so it survives a database outage the way FPM did for free
 * (a fresh connection per request):
 *
 *   - **idle-gated validation** — on borrow, a connection idle longer than
 *     {@see PoolPolicy::$aliveBypassWindow} is probed ({@see ConnectionFactory::validate()});
 *     a dead one is retired and a fresh one opened. Hot connections skip the probe →
 *     zero overhead on healthy traffic.
 *   - **maxLifetime rotation** — a connection older than {@see PoolPolicy::$maxLifetime}
 *     (jittered) is retired before it can go stale server-side.
 *   - **connectionTimeout** — the deadline for a whole borrow: it keeps discarding what
 *     it finds and reaching for a live connection until that budget is spent, then fails
 *     fast with {@see PoolException}.
 *   - **create backoff** — after an open that fails (or yields a connection that cannot
 *     answer), opening is held back for a doubling interval, so a server in trouble is
 *     not met with a connect storm.
 *
 * The pool is driver-agnostic: it drives a {@see ConnectionFactory} (create / validate
 * / close), so the same pool serves DB (CDO) and Redis alike.
 *
 * Must run inside a Swoole coroutine (it uses a coroutine Channel).
 *
 * @link https://winterframe.net/packages/cpool/api-reference#connectionpool-final-class ConnectionPool reference
 */
final class ConnectionPool
{
    /** First penalty after a failed open, in seconds — short, so a blip costs nothing. */
    private const float CREATE_BACKOFF_MIN = 0.01;

    /** Ceiling the penalty doubles up to, in seconds. */
    private const float CREATE_BACKOFF_MAX = 5.0;

    /** Idle connections waiting to be borrowed. */
    private ?Channel $idle = null;

    /** Connections opened (idle in the channel + borrowed out). */
    private int $total = 0;

    /** Monotonic instant before which no borrower may open a connection (`0` = free). */
    private float $createBlockedUntil = 0.0;

    /** Current penalty length, doubled per consecutive failure. */
    private float $createBackoff = 0.0;

    /** Why opening last failed, so the borrow that gives up can name the real cause. */
    private ?Throwable $lastCreateError = null;

    /** Swoole timer id of the background housekeeper, or null when not armed. */
    private ?int $timerId = null;

    /** @var Closure(): float Monotonic seconds source (test seam). */
    private readonly Closure $clock;

    /**
     * @param ConnectionFactory $factory Opens / probes / closes the pooled resource.
     * @param PoolPolicy $policy Sizing and lifecycle tuning.
     * @param (Closure(): float)|null $clock Monotonic clock override for tests;
     *   defaults to `hrtime(true) / 1e9`.
     */
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly PoolPolicy $policy = new PoolPolicy(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
        $this->idle  = new Channel($this->policy->maximumPoolSize);
    }

    /**
     * Borrows a live connection: reuse an idle one (idle-gated probe), grow up to
     * maximumPoolSize, or wait for a release. Expired or dead connections are retired
     * and a fresh one is obtained.
     *
     * The loop is bounded by time, not by a number of tries. A pool that has been idle
     * while the server (or a firewall) dropped its sockets holds nothing but corpses,
     * and burying them is not a failure — it is the work. Counting each one against a
     * fixed retry budget made a full pool of dead connections fail the borrow one step
     * short of opening the fresh connection that was about to succeed; with the deadline,
     * `connectionTimeout` is what says when to stop.
     *
     * @throws PoolException Exhaustion ({@see PoolException::exhausted()}), a failed open
     *   ({@see PoolException::connectFailed()}), or a window spent on dead connections
     *   ({@see PoolException::unusable()}).
     */
    public function borrow(): PoolEntry
    {
        $this->ensureHousekeeper();

        $deadline = $this->now() + $this->policy->connectionTimeout;
        $retired  = 0;

        do {
            [$entry, $fresh] = $this->acquire($deadline);
            if ($entry === null) {
                throw $this->unavailable($retired);
            }
            if ($this->isExpired($entry) || ($this->needsProbe($entry) && !$this->probe($entry))) {
                $this->discard($entry);
                ++$retired;
                // Opened and unusable in the same breath: the server takes sockets but
                // does not serve on them (a database still starting, a cache still
                // loading). Opening the next one immediately is a connect storm against
                // something already in trouble — so creation is penalised instead.
                if ($fresh) {
                    $this->penaliseCreate(null);
                }
                continue;
            }
            if ($fresh) {
                $this->rewardCreate();
            }
            $entry->lastUsedAt = $this->now();
            return $entry;
        } while ($this->now() < $deadline);

        throw $this->unavailable($retired);
    }

    /** Returns a borrowed connection to the pool for reuse. */
    public function release(PoolEntry $entry): void
    {
        if ($this->idle === null) {
            $this->discard($entry);
            return;
        }
        $entry->lastUsedAt = $this->now();
        $this->idle->push($entry);
    }

    /**
     * Retires a connection (close + free the slot) instead of returning it — for a
     * connection-level failure (SQLSTATE 08xxx etc.) detected during use, so the dead
     * connection is never handed out again.
     */
    public function evict(PoolEntry $entry): void
    {
        $this->discard($entry);
    }

    /** @return array{total: int, idle: int, active: int, maximum: int} */
    public function stats(): array
    {
        $idle = $this->idle?->length() ?? 0;
        return [
            'total'   => $this->total,
            'idle'    => $idle,
            'active'  => $this->total - $idle,
            'maximum' => $this->policy->maximumPoolSize,
        ];
    }

    /**
     * Stops the housekeeper and drops every pooled connection **without closing it**
     * — the fork-safe counterpart of {@see close()}.
     *
     * A fork copies file descriptors, so a child must never close an inherited socket
     * (that would tear down the connection its parent is still using); it must simply
     * forget it and open its own. Clearing the timer is the part {@see close()} and
     * this share: a `Timer::tick` callback holds a reference to the pool, so a pool
     * merely dereferenced would stay alive and keep maintaining connections nobody
     * uses. A facade that rebuilds its pools after a fork calls this, not {@see close()}.
     */
    public function abandon(): void
    {
        $this->clearHousekeeper();
        $this->idle  = null;
        $this->total = 0;
    }

    /**
     * Stops the housekeeper, closes every idle connection and the pool itself.
     *
     * Called outside a coroutine (worker shutdown) only the housekeeper is stopped —
     * see the comment in the body for why the connections are left to the kernel.
     */
    public function close(): void
    {
        $this->clearHousekeeper();
        if ($this->idle === null) {
            return;
        }

        // Draining needs a coroutine: Channel::pop() is a coroutine API and raises a
        // *fatal* outside one — not catchable, the process dies. The caller that closes
        // outside a coroutine is Swoole's `workerExit`, which fires while the reactor is
        // already winding down; the worker then dies mid-shutdown and, under `run dev`,
        // never comes back.
        //
        // So the drain is skipped there rather than forced. The point of closing at that
        // moment is the housekeeping timer above — a live repeating timer keeps the
        // reactor from draining at all. The sockets need no ceremony: the process is
        // ending, the kernel closes them, and a dropped client is routine for a database.
        // Starting a scheduler just to say goodbye politely would risk hanging the very
        // exit this is meant to keep clean.
        if (Coroutine::getCid() > 0) {
            while ($this->idle->length() > 0) {
                $entry = $this->idle->pop(0.001);
                if ($entry instanceof PoolEntry) {
                    $this->safeClose($entry->resource);
                }
            }
        }

        $this->idle->close();
        $this->idle  = null;
        $this->total = 0;
    }

    // ── housekeeping ─────────────────────────────────────────────────────────────

    /**
     * Arms the background housekeeper on first borrow, once, when maintenance is
     * enabled and Swoole is present. The first borrow always runs inside a coroutine,
     * so the reactor exists to host the timer. A no-op when maintenance is off (an
     * unconfigured pool never arms a timer).
     */
    private function ensureHousekeeper(): void
    {
        if (
            $this->timerId !== null
            || !$this->policy->housekeepingEnabled()
            || !extension_loaded('swoole')
        ) {
            return;
        }
        $ms = (int) max(1000.0, $this->policy->housekeepingInterval * 1000.0);
        $this->timerId = \Swoole\Timer::tick($ms, function (): void {
            try {
                $this->maintain();
            } catch (Throwable) {
                // A maintenance pass must never kill the timer.
            }
        });
    }

    /** Disarms the housekeeping timer, if armed. */
    private function clearHousekeeper(): void
    {
        if ($this->timerId === null) {
            return;
        }
        if (extension_loaded('swoole')) {
            \Swoole\Timer::clear($this->timerId);
        }
        $this->timerId = null;
    }

    /**
     * One background maintenance pass over the idle connections: retire aged ones
     * (maxLifetime), shrink idle-too-long ones toward `minimumIdle` (idleTimeout),
     * proactively probe long-idle survivors (keepaliveTime), then top up warm
     * connections to `minimumIdle`. Borrowed-out connections are not in the channel,
     * so this never touches an in-use connection.
     */
    private function maintain(): void
    {
        if ($this->idle === null) {
            return;
        }
        $now      = $this->now();
        $count    = $this->idle->length();
        $shrinkable = max(0, $this->total - $this->policy->minimumIdle);
        $survivors = [];

        for ($i = 0; $i < $count; ++$i) {
            $entry = $this->idle->pop(0.001);
            if (!$entry instanceof PoolEntry) {
                break; // drained by a concurrent borrow
            }
            // maxLifetime — always retire; the floor is refilled by top-up below.
            if ($this->isExpired($entry)) {
                $this->discard($entry);
                continue;
            }
            // idleTimeout — shrink toward minimumIdle, within budget.
            if (
                $this->policy->idleTimeout > 0.0
                && $shrinkable > 0
                && ($now - $entry->lastUsedAt) >= $this->policy->idleTimeout
            ) {
                --$shrinkable;
                $this->discard($entry);
                continue;
            }
            // keepalive — proactive probe. Does NOT reset lastUsedAt, so idleTimeout
            // keeps measuring real application idleness.
            if (
                $this->policy->keepaliveTime > 0.0
                && ($now - $entry->lastUsedAt) >= $this->policy->keepaliveTime
                && !$this->probe($entry)
            ) {
                $this->discard($entry);
                continue;
            }
            $survivors[] = $entry;
        }

        foreach ($survivors as $entry) {
            $this->idle->push($entry);
        }

        // minimumIdle — reopen warm connections up to the floor (best-effort).
        while (
            $this->total < $this->policy->minimumIdle
            && $this->total < $this->policy->maximumPoolSize
        ) {
            try {
                $this->idle->push($this->make());
            } catch (PoolException) {
                break; // DB unreachable — retry on the next pass
            }
        }
    }

    // ── internals ──────────────────────────────────────────────────────────────

    /**
     * Gets an idle connection, grows the pool, or waits for a release — never longer
     * than the borrow's remaining time, so `connectionTimeout` bounds the whole
     * borrow rather than each wait inside it.
     *
     * @return array{0: ?PoolEntry, 1: bool} The entry — `null` when nothing came free
     *   in time — and whether this call is what opened it.
     */
    private function acquire(float $deadline): array
    {
        if ($this->idle !== null && $this->idle->length() > 0) {
            $entry = $this->idle->pop(0.001);
            if ($entry instanceof PoolEntry) {
                return [$entry, false];
            }
        }
        if ($this->total < $this->policy->maximumPoolSize && $this->canCreate()) {
            return [$this->make(), true];
        }
        $entry = $this->idle?->pop(max(0.001, $deadline - $this->now()));
        return [$entry instanceof PoolEntry ? $entry : null, false];
    }

    /** Whether opening a connection is allowed right now, or still being penalised. */
    private function canCreate(): bool
    {
        return $this->now() >= $this->createBlockedUntil;
    }

    /**
     * Closes the door on opening for a while, doubling the wait per consecutive failure.
     *
     * The penalty is pool-wide on purpose. A per-borrow retry budget would let every
     * concurrent request open its own socket into a server that is already failing —
     * at any real request rate that is a connect storm. One failure here holds back
     * every coroutine in this worker.
     *
     * @param Throwable|null $cause The driver's own error, or `null` when the socket
     *   opened and only the probe failed.
     */
    private function penaliseCreate(?Throwable $cause): void
    {
        $this->createBackoff = min(
            self::CREATE_BACKOFF_MAX,
            max(self::CREATE_BACKOFF_MIN, $this->createBackoff * 2),
        );
        $this->createBlockedUntil = $this->now() + $this->createBackoff;
        $this->lastCreateError    = $cause;
    }

    /** A connection opened and answered — the server is serving again, so drop the penalty. */
    private function rewardCreate(): void
    {
        $this->createBackoff      = 0.0;
        $this->createBlockedUntil = 0.0;
        $this->lastCreateError    = null;
    }

    /**
     * The right diagnosis for a borrow that ends empty-handed. Connections that fail is
     * a different incident from connections that are all busy, and the two want
     * different reactions — telling an operator to raise `maximumPoolSize` while the
     * database is refusing to serve sends them the wrong way.
     */
    private function unavailable(int $retired): PoolException
    {
        if ($this->lastCreateError !== null) {
            return PoolException::connectFailed($this->lastCreateError);
        }
        if ($retired > 0 || $this->createBlockedUntil > 0.0) {
            return PoolException::unusable($retired, $this->policy->connectionTimeout);
        }
        return PoolException::exhausted($this->policy->connectionTimeout);
    }

    private function make(): PoolEntry
    {
        // Reserve the slot before the (possibly yielding) connect so concurrent
        // borrows don't over-provision past maximumPoolSize.
        ++$this->total;
        try {
            $resource = $this->factory->create();
        } catch (Throwable $e) {
            --$this->total;
            $this->penaliseCreate($e);
            throw PoolException::connectFailed($e);
        }
        $now = $this->now();
        return new PoolEntry($resource, $now, $now, $this->computeExpiry($now));
    }

    private function discard(PoolEntry $entry): void
    {
        $this->safeClose($entry->resource);
        if ($this->total > 0) {
            --$this->total;
        }
    }

    private function isExpired(PoolEntry $entry): bool
    {
        return $entry->expiresAt !== null && $this->now() >= $entry->expiresAt;
    }

    private function needsProbe(PoolEntry $entry): bool
    {
        return ($this->now() - $entry->lastUsedAt) > $this->policy->aliveBypassWindow;
    }

    private function probe(PoolEntry $entry): bool
    {
        try {
            return $this->factory->validate($entry->resource);
        } catch (Throwable) {
            return false;
        }
    }

    private function computeExpiry(float $now): ?float
    {
        if ($this->policy->maxLifetime <= 0.0) {
            return null;
        }
        $life = $this->policy->maxLifetime;
        $jit  = $this->policy->maxLifetimeJitter;
        if ($jit > 0.0) {
            $spread = $life * $jit;
            $life  += (mt_rand() / mt_getrandmax()) * 2 * $spread - $spread;
        }
        return $now + $life;
    }

    private function safeClose(object $resource): void
    {
        try {
            $this->factory->close($resource);
        } catch (Throwable) {
        }
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
