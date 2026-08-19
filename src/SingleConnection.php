<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

use Closure;
use Throwable;

/**
 * A "pool of one" for a single long-lived connection — the {@see ConnectionPool}
 * resilience without a coroutine Channel, for FPM requests and plain (non-coroutine)
 * long-running processes.
 *
 * It keeps one connection per instance and applies the same lifecycle maintenance on
 * every {@see get()}:
 *
 *   - **idle-gated validation** — a connection idle longer than
 *     {@see PoolPolicy::$aliveBypassWindow} is probed ({@see ConnectionFactory::validate()});
 *     a dead one is retired and reopened. A hot connection skips the probe.
 *   - **maxLifetime rotation** — a connection older than {@see PoolPolicy::$maxLifetime}
 *     is retired before it can go stale server-side.
 *
 * There is no jitter on the lifetime here (unlike the pool): a single connection per
 * process cannot form a thundering herd, and separate FPM processes open at different
 * times anyway.
 *
 * Unlike {@see ConnectionPool} this needs no coroutine — it never touches a Channel —
 * so it is safe under FPM/CLI. It is not coroutine-safe for concurrent callers; use
 * {@see ConnectionPool} when connections are shared across coroutines.
 *
 * @link https://winterframe.net/packages/cpool/api-reference#singleconnection-final-class SingleConnection reference
 */
final class SingleConnection
{
    private ?PoolEntry $entry = null;

    /** @var Closure(): float Monotonic seconds source (test seam). */
    private readonly Closure $clock;

    /**
     * @param ConnectionFactory $factory Opens / probes / closes the connection.
     * @param PoolPolicy $policy Lifecycle tuning (`maxLifetime`, `aliveBypassWindow`);
     *   sizing/timeout fields are ignored — there is only ever one connection.
     * @param (Closure(): float)|null $clock Monotonic clock override for tests;
     *   defaults to `hrtime(true) / 1e9`.
     */
    public function __construct(
        private readonly ConnectionFactory $factory,
        private readonly PoolPolicy $policy = new PoolPolicy(),
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Returns the live connection, reopening it when the current one has died
     * (idle-gated probe) or aged past maxLifetime.
     *
     * @throws PoolException On connect failure.
     */
    public function get(): object
    {
        $now = $this->now();
        if (
            $this->entry !== null
            && ($this->isExpired($this->entry, $now)
                || ($this->needsProbe($this->entry, $now) && !$this->probe($this->entry)))
        ) {
            $this->discard();
        }
        if ($this->entry === null) {
            $this->entry = $this->open($now);
        }
        $this->entry->lastUsedAt = $this->now();
        return $this->entry->resource;
    }

    /**
     * The connection currently held, without any lifecycle checks — for a caller that
     * needs to inspect it (a liveness probe after a failure) rather than use it.
     * `null` when nothing is open.
     */
    public function peek(): ?object
    {
        return $this->entry?->resource;
    }

    /**
     * Retires the current connection (close + forget) — for a connection-level failure
     * detected during use, so the next {@see get()} opens a fresh one.
     */
    public function evict(): void
    {
        $this->discard();
    }

    /** Closes the connection and forgets it. */
    public function close(): void
    {
        $this->discard();
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private function open(float $now): PoolEntry
    {
        try {
            $resource = $this->factory->create();
        } catch (Throwable $e) {
            throw PoolException::connectFailed($e);
        }
        $expiresAt = $this->policy->maxLifetime > 0.0 ? $now + $this->policy->maxLifetime : null;
        return new PoolEntry($resource, $now, $now, $expiresAt);
    }

    private function discard(): void
    {
        if ($this->entry !== null) {
            $this->safeClose($this->entry->resource);
            $this->entry = null;
        }
    }

    private function isExpired(PoolEntry $entry, float $now): bool
    {
        return $entry->expiresAt !== null && $now >= $entry->expiresAt;
    }

    private function needsProbe(PoolEntry $entry, float $now): bool
    {
        return ($now - $entry->lastUsedAt) > $this->policy->aliveBypassWindow;
    }

    private function probe(PoolEntry $entry): bool
    {
        try {
            return $this->factory->validate($entry->resource);
        } catch (Throwable) {
            return false;
        }
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
