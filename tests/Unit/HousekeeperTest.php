<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionPool;
use Flytachi\Winter\CPool\PoolPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The background housekeeper's decision pass ({@see ConnectionPool::maintain()}),
 * driven directly (via reflection) with a controllable clock — no real timer tick,
 * so keepalive / idleTimeout / minimumIdle are deterministic. Runs inside a coroutine
 * because maintain() drains the idle Channel.
 *
 * Each case calls `$pool->close()` before the coroutine ends: the first `borrow()`
 * with maintenance enabled arms a real `Swoole\Timer::tick`, which keeps the reactor
 * alive — `close()` clears it so `Swoole\Coroutine\run` can return.
 */
final class HousekeeperTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs a Swoole coroutine context.');
        }
    }

    private static function maintain(ConnectionPool $pool): void
    {
        (new ReflectionMethod($pool, 'maintain'))->invoke($pool);
    }

    public function test_keepalive_retires_dead_idle_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(keepaliveTime: 10.0),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $pool->release($pool->borrow());   // idle, lastUsedAt = 1000
            $time = 1011.0;                     // idle 11s >= keepaliveTime
            $f->alive = false;                  // died while idle
            self::maintain($pool);
            $out = ['validated' => $f->validated, 'closed' => $f->closed, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(1, $out['validated'], 'a long-idle connection is probed');
        self::assertSame(1, $out['closed'], 'the dead connection is retired');
        self::assertSame(0, $out['stats']['total']);
    }

    public function test_keepalive_keeps_live_idle_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(keepaliveTime: 10.0), static function () use (&$time): float {
                return $time;
            });
            $pool->release($pool->borrow());
            $time = 1011.0;
            self::maintain($pool);
            $out = ['validated' => $f->validated, 'closed' => $f->closed, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(1, $out['validated']);
        self::assertSame(0, $out['closed'], 'a live connection survives');
        self::assertSame(1, $out['stats']['idle']);
    }

    public function test_keepalive_skips_hot_idle_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(keepaliveTime: 10.0), static function () use (&$time): float {
                return $time;
            });
            $pool->release($pool->borrow());
            $time = 1005.0;   // idle 5s < keepaliveTime
            self::maintain($pool);
            $out = ['validated' => $f->validated, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(0, $out['validated'], 'a recently-used connection is not probed');
        self::assertSame(1, $out['stats']['idle']);
    }

    public function test_idle_timeout_shrinks_toward_minimum_idle(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maximumPoolSize: 5, idleTimeout: 10.0, minimumIdle: 1),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $a = $pool->borrow();
            $b = $pool->borrow();
            $c = $pool->borrow();   // total = 3
            $pool->release($a);
            $pool->release($b);
            $pool->release($c);     // idle = 3, all lastUsedAt = 1000
            $time = 1011.0;         // idle 11s >= idleTimeout
            self::maintain($pool);
            $out = ['closed' => $f->closed, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(2, $out['closed'], 'shrinks 3 → minimumIdle 1');
        self::assertSame(1, $out['stats']['total']);
        self::assertSame(1, $out['stats']['idle']);
    }

    public function test_minimum_idle_tops_up_from_empty(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 5, minimumIdle: 2));
            self::maintain($pool);   // nothing idle → open the warm floor
            $out = ['created' => $f->created, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(2, $out['created']);
        self::assertSame(2, $out['stats']['idle']);
        self::assertSame(2, $out['stats']['total']);
    }

    public function test_maintain_retires_expired_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maxLifetime: 10.0, maxLifetimeJitter: 0.0, keepaliveTime: 5.0),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $pool->release($pool->borrow());   // expires at 1010
            $time = 1011.0;                     // past maxLifetime
            self::maintain($pool);
            $out = ['closed' => $f->closed, 'validated' => $f->validated, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(1, $out['closed']);
        self::assertSame(0, $out['validated'], 'expired connection is retired without a probe');
        self::assertSame(0, $out['stats']['total']);
    }

    public function test_abandon_clears_the_timer_without_closing_sockets(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(keepaliveTime: 10.0));
            $pool->release($pool->borrow());   // arms the housekeeper, 1 idle connection
            $armed = (new \ReflectionProperty(ConnectionPool::class, 'timerId'))->getValue($pool) !== null;

            $pool->abandon();

            $out = [
                'armedBefore' => $armed,
                'armedAfter'  => (new \ReflectionProperty(ConnectionPool::class, 'timerId'))->getValue($pool) !== null,
                'closed'      => $f->closed,
            ];
        });

        self::assertTrue($out['armedBefore'], 'the housekeeper arms on first borrow');
        self::assertFalse($out['armedAfter'], 'abandon() disarms it — an orphaned pool must not keep maintaining');
        self::assertSame(0, $out['closed'], 'inherited sockets are forgotten, never closed (fork safety)');
    }

    public function test_maintain_leaves_borrowed_connections_untouched(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maximumPoolSize: 5, idleTimeout: 1.0, keepaliveTime: 1.0),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $pool->borrow();
            $pool->borrow();   // both held — not in the idle channel
            $time = 1010.0;
            self::maintain($pool);
            $out = ['closed' => $f->closed, 'validated' => $f->validated, 'stats' => $pool->stats()];
            $pool->close();
        });

        self::assertSame(0, $out['closed'], 'in-use connections are never maintained');
        self::assertSame(0, $out['validated']);
        self::assertSame(2, $out['stats']['total']);
    }
}
