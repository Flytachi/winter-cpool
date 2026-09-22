<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

/**
 * Immutable pool tuning — the HikariCP-style knobs. A connection pool trades
 * per-request freshness for reuse, so it needs explicit policy to stay resilient:
 * how many connections, how long to wait, when to retire, and when to re-check
 * liveness.
 *
 * ```
 * $policy = new PoolPolicy(maximumPoolSize: 20, maxLifetime: 600);
 * $policy = PoolPolicy::default();
 * ```
 *
 * @link https://winterframe.net/packages/cpool/policy Policy: every knob and how to pick values
 */
final readonly class PoolPolicy
{
    /**
     * Keepalive as the pool will actually apply it: the requested value, or `0.0` when
     * the connection would be gone before the first ping ever reached it.
     *
     * Ordering is what makes keepalive work — `keepaliveTime < idleTimeout < maxLifetime`.
     * Above `maxLifetime` the connection is rotated first; above `idleTimeout` (with no
     * warm floor to survive it) it is closed first. Either way the pings never happen,
     * and a setting that silently does nothing is worse than one that is plainly off:
     * the operator believes idle connections are being kept alive while they quietly die.
     * HikariCP takes the same line, disabling a `keepaliveTime` that reaches `maxLifetime`.
     */
    public float $keepaliveTime;

    /**
     * @param int $maximumPoolSize Upper bound on connections held per pool.
     * @param float $connectionTimeout Seconds to wait for a free connection before
     *   failing with {@see PoolException} (HikariCP `connectionTimeout`).
     * @param float $maxLifetime Seconds after which a connection is retired and
     *   reopened regardless of health, so none grows stale (`0` = never).
     * @param float $aliveBypassWindow Seconds: a connection idle for less than this
     *   skips the liveness probe on borrow — hot connections pay nothing (HikariCP
     *   `aliveBypassWindow`, default 500ms).
     * @param float $maxLifetimeJitter Fraction `0..1` of random spread applied to
     *   `maxLifetime` so connections don't all expire at the same instant.
     * @param float $housekeepingInterval Seconds between background {@see ConnectionPool}
     *   maintenance passes. Only relevant when a maintenance knob below is enabled.
     * @param float $keepaliveTime Seconds: the housekeeper proactively probes idle
     *   connections idle at least this long, retiring dead ones **before** a borrow
     *   sees them (and keeping idle-killing proxies/firewalls from dropping them).
     *   `0` = disabled (HikariCP `keepaliveTime`). Swoole only. Kept only while the
     *   ordering leaves it something to ping — see {@see $keepaliveTime}.
     * @param float $idleTimeout Seconds: the housekeeper closes connections idle at
     *   least this long, shrinking the pool down to `minimumIdle`. `0` = never shrink
     *   (HikariCP `idleTimeout`). Swoole only.
     * @param int $minimumIdle Warm floor the housekeeper maintains — it reopens
     *   connections up to this count and never shrinks below it. `0` = fully lazy
     *   (HikariCP `minimumIdle`). Swoole only.
     */
    public function __construct(
        public int $maximumPoolSize = 10,
        public float $connectionTimeout = 15.0,
        public float $maxLifetime = 1800.0,
        public float $aliveBypassWindow = 0.5,
        public float $maxLifetimeJitter = 0.1,
        public float $housekeepingInterval = 30.0,
        float $keepaliveTime = 120.0,
        public float $idleTimeout = 600.0,
        public int $minimumIdle = 0,
    ) {
        $this->keepaliveTime = self::reachableKeepalive(
            $keepaliveTime,
            $maxLifetime,
            $idleTimeout,
            $minimumIdle,
        );
    }

    /**
     * Drops a keepalive the connection will not live to receive.
     *
     * A warm floor changes the second answer: with `minimumIdle > 0` the connections at
     * the floor are never closed by `idleTimeout`, so they sit idle indefinitely and
     * keepalive is exactly what they need — whatever the two numbers are relative to
     * each other.
     */
    private static function reachableKeepalive(
        float $keepalive,
        float $maxLifetime,
        float $idleTimeout,
        int $minimumIdle,
    ): float {
        if ($keepalive <= 0.0) {
            return 0.0;
        }
        if ($maxLifetime > 0.0 && $keepalive >= $maxLifetime) {
            return 0.0;
        }
        if ($idleTimeout > 0.0 && $minimumIdle === 0 && $keepalive >= $idleTimeout) {
            return 0.0;
        }

        return $keepalive;
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Whether any background maintenance is enabled — the pool arms its housekeeping
     * timer only when this is true, so an unconfigured pool pays nothing.
     */
    public function housekeepingEnabled(): bool
    {
        return $this->keepaliveTime > 0.0 || $this->idleTimeout > 0.0 || $this->minimumIdle > 0;
    }
}
