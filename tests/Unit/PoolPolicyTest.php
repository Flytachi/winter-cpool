<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\PoolPolicy;
use PHPUnit\Framework\TestCase;

final class PoolPolicyTest extends TestCase
{
    public function test_defaults(): void
    {
        $p = PoolPolicy::default();

        self::assertSame(10, $p->maximumPoolSize);
        self::assertSame(15.0, $p->connectionTimeout);
        self::assertSame(1800.0, $p->maxLifetime);
        self::assertSame(0.5, $p->aliveBypassWindow);
        self::assertSame(0.1, $p->maxLifetimeJitter);
    }

    public function test_housekeeping_is_on_by_default(): void
    {
        $p = PoolPolicy::default();

        self::assertSame(120.0, $p->keepaliveTime, "HikariCP's keepalive default");
        self::assertSame(600.0, $p->idleTimeout, "HikariCP's idle timeout default");
        self::assertSame(0, $p->minimumIdle, 'but the pool stays lazy — it multiplies by worker');
        self::assertTrue($p->housekeepingEnabled(), 'a pool in use sweeps itself');
    }

    public function test_overrides(): void
    {
        $p = new PoolPolicy(maximumPoolSize: 20, maxLifetime: 0.0);

        self::assertSame(20, $p->maximumPoolSize);
        self::assertSame(0.0, $p->maxLifetime, 'maxLifetime 0 disables rotation');
    }

    public function test_keepalive_is_dropped_when_the_connection_is_rotated_first(): void
    {
        $p = new PoolPolicy(maxLifetime: 60.0, keepaliveTime: 60.0);

        self::assertSame(0.0, $p->keepaliveTime, 'a connection retired before its first ping cannot be kept alive');
    }

    public function test_keepalive_is_dropped_when_idle_timeout_closes_first(): void
    {
        $p = new PoolPolicy(keepaliveTime: 300.0, idleTimeout: 120.0);

        self::assertSame(0.0, $p->keepaliveTime, 'a connection closed before its first ping cannot be kept alive');
    }

    public function test_keepalive_survives_a_coherent_ordering(): void
    {
        $p = new PoolPolicy(maxLifetime: 1800.0, keepaliveTime: 120.0, idleTimeout: 600.0);

        self::assertSame(120.0, $p->keepaliveTime);
    }

    public function test_keepalive_survives_a_short_idle_timeout_above_a_warm_floor(): void
    {
        $p = new PoolPolicy(keepaliveTime: 300.0, idleTimeout: 120.0, minimumIdle: 2);

        self::assertSame(300.0, $p->keepaliveTime, 'the floor connections stay idle, so keepalive still has work');
    }
}
