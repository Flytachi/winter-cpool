<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\PoolException;
use Flytachi\Winter\CPool\PoolPolicy;
use Flytachi\Winter\CPool\SingleConnection;
use PHPUnit\Framework\TestCase;

/**
 * SingleConnection (the coroutine-free "pool of one" used by the FPM / non-coroutine
 * path). No Swoole needed — a controllable clock makes idle-gating and maxLifetime
 * deterministic without a live database.
 */
final class SingleConnectionTest extends TestCase
{
    public function test_first_get_opens_a_connection(): void
    {
        $f = new MockFactory();
        $c = new SingleConnection($f);

        $r = $c->get();

        self::assertSame(1, $f->created);
        self::assertSame(0, $f->validated, 'a freshly opened connection is not probed');
        self::assertSame(1, $r->id);
    }

    public function test_reuses_connection_within_bypass_window(): void
    {
        $f = new MockFactory();
        $c = new SingleConnection($f, new PoolPolicy(aliveBypassWindow: 0.5));

        $a = $c->get();
        $b = $c->get();   // immediate → idle < window → no probe

        self::assertSame($a, $b);
        self::assertSame(1, $f->created);
        self::assertSame(0, $f->validated, 'hot connection skips the probe');
    }

    public function test_probes_connection_idle_beyond_bypass_window(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $c = new SingleConnection($f, new PoolPolicy(aliveBypassWindow: 0.5), static function () use (&$time): float {
            return $time;
        });

        $a = $c->get();
        $time = 1002.0;   // idle 2s > 0.5 window
        $b = $c->get();   // → probe (alive) → reuse

        self::assertSame($a, $b);
        self::assertSame(1, $f->validated, 'idle-beyond-window connection is probed');
        self::assertSame(1, $f->created);
    }

    public function test_retires_dead_connection_and_reopens(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $c = new SingleConnection($f, new PoolPolicy(aliveBypassWindow: 0.5), static function () use (&$time): float {
            return $time;
        });

        $a = $c->get();
        $time = 1002.0;
        $f->alive = false;   // the connection has died
        $b = $c->get();      // probe fails → retire → reopen

        self::assertNotSame($a, $b);
        self::assertSame(2, $f->created);
        self::assertSame(1, $f->closed, 'the dead connection was closed');
    }

    public function test_retires_connection_past_max_lifetime(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $c = new SingleConnection(
            $f,
            new PoolPolicy(maxLifetime: 10.0, aliveBypassWindow: 0.5),
            static function () use (&$time): float {
                return $time;
            },
        );

        $a = $c->get();    // expires at 1010
        $time = 1011.0;    // past maxLifetime
        $b = $c->get();    // expired → retire → reopen

        self::assertNotSame($a, $b);
        self::assertSame(2, $f->created);
    }

    public function test_maxlifetime_zero_disables_rotation(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $c = new SingleConnection(
            $f,
            new PoolPolicy(maxLifetime: 0.0, aliveBypassWindow: 0.5),
            static function () use (&$time): float {
                return $time;
            },
        );

        $a = $c->get();
        $time = 999_999.0;   // ancient, but rotation is off
        $b = $c->get();      // idle → probe (alive) → reuse, never rotated

        self::assertSame($a, $b);
        self::assertSame(1, $f->created);
    }

    public function test_connect_failure_throws(): void
    {
        $f = new MockFactory();
        $f->failCreate = true;
        $c = new SingleConnection($f);

        $this->expectException(PoolException::class);
        $c->get();
    }

    public function test_evict_forces_reopen_on_next_get(): void
    {
        $f = new MockFactory();
        $c = new SingleConnection($f);

        $a = $c->get();
        $c->evict();
        $b = $c->get();

        self::assertNotSame($a, $b);
        self::assertSame(2, $f->created);
        self::assertSame(1, $f->closed);
    }
}
