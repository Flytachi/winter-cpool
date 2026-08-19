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
 *   - **connectionTimeout** — a borrow waits at most {@see PoolPolicy::$connectionTimeout}
 *     for a free slot, then fails fast with {@see PoolException}.
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
    /** Retire-and-retry attempts in borrow() before giving up (dead connection storm). */
    private const int MAX_RETIRE_LOOPS = 3;

    /** Idle connections waiting to be borrowed. */
    private ?Channel $idle = null;

    /** Connections opened (idle in the channel + borrowed out). */
    private int $total = 0;

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
     * maximumPoolSize, or wait connectionTimeout for a release. Expired or dead
     * connections are retired and a fresh one is obtained.
     *
     * @throws PoolException On exhaustion, connect failure, or repeated dead borrows.
     */
    public function borrow(): PoolEntry
    {
        $this->ensureHousekeeper();
        for ($attempt = 0; $attempt <= self::MAX_RETIRE_LOOPS; ++$attempt) {
            $entry = $this->acquire();
            if ($entry === null) {
                throw PoolException::exhausted($this->policy->connectionTimeout);
            }
            if ($this->isExpired($entry) || ($this->needsProbe($entry) && !$this->probe($entry))) {
                $this->discard($entry);
                continue;
            }
            $entry->lastUsedAt = $this->now();
            return $entry;
        }

        throw PoolException::unusable(self::MAX_RETIRE_LOOPS + 1);
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

    /** Gets an idle connection, grows the pool, or waits for a release. */
    private function acquire(): ?PoolEntry
    {
        if ($this->idle !== null && $this->idle->length() > 0) {
            $entry = $this->idle->pop(0.001);
            if ($entry instanceof PoolEntry) {
                return $entry;
            }
        }
        if ($this->total < $this->policy->maximumPoolSize) {
            return $this->make();
        }
        $entry = $this->idle?->pop($this->policy->connectionTimeout);
        return $entry instanceof PoolEntry ? $entry : null;
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
