<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionPool;
use Flytachi\Winter\CPool\PoolPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A probe that does not answer — the server gone silent mid-connection, a NAT that
 * dropped the socket — is not waited for: after `validationTimeout` the connection is
 * taken for dead. Neither a borrow nor the housekeeper hangs on it.
 */
final class ValidationTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs a Swoole coroutine context.');
        }
    }

    public function test_the_default_is_three_seconds_and_zero_waits_for_good(): void
    {
        self::assertSame(3.0, (new PoolPolicy())->validationTimeout);
        self::assertSame(0.0, (new PoolPolicy(validationTimeout: -1.0))->validationTimeout);
    }

    public function test_a_borrow_does_not_wait_for_a_probe_that_hangs(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(keepaliveTime: 0.0, idleTimeout: 0.0, validationTimeout: 0.2),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $old = $pool->borrow();
            $pool->release($old);
            $time = 1010.0;                  // idle past the bypass window: probed on borrow
            $f->probeTakes = 1.0;            // ...and the probe never answers in time

            $started = microtime(true);
            $new = $pool->borrow();
            $out['took'] = microtime(true) - $started;
            $out['fresh'] = $new !== $old;
            $out['total'] = $pool->stats()['total'];
            $out['closedRightAway'] = $f->closed;
            $pool->release($new);
            \Swoole\Coroutine::sleep(1.1);   // the hung probe finally returns
            $out['closedAfter'] = $f->closed;
            $pool->close();
        });

        self::assertLessThan(0.6, $out['took'], 'the borrow waits validationTimeout, not the probe');
        self::assertTrue($out['fresh'], 'a fresh connection is opened instead');
        self::assertSame(1, $out['total'], 'the hung one no longer counts against the pool');
        self::assertSame(0, $out['closedRightAway'], 'it is not closed under the probe still using it');
        self::assertSame(1, $out['closedAfter'], 'the probe closes it when it returns');
    }

    public function test_the_housekeeper_does_not_wait_for_a_probe_that_hangs(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(keepaliveTime: 10.0, validationTimeout: 0.2),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $pool->release($pool->borrow());
            $time = 1011.0;
            $f->probeTakes = 1.0;

            $started = microtime(true);
            (new ReflectionMethod($pool, 'maintain'))->invoke($pool);
            $out['took'] = microtime(true) - $started;
            $out['total'] = $pool->stats()['total'];
            $pool->close();
            \Swoole\Coroutine::sleep(1.1);
            $out['closed'] = $f->closed;
        });

        self::assertLessThan(0.6, $out['took']);
        self::assertSame(0, $out['total'], 'the silent connection is retired');
        self::assertSame(1, $out['closed'], 'and closed once its probe returns, the pool closed or not');
    }

    public function test_a_probe_that_answers_in_time_keeps_the_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(keepaliveTime: 0.0, idleTimeout: 0.0, validationTimeout: 0.5),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $old = $pool->borrow();
            $pool->release($old);
            $time = 1010.0;
            $f->probeTakes = 0.05;
            $out['same'] = $pool->borrow() === $old;
            $out['created'] = $f->created;
            $pool->close();
        });

        self::assertTrue($out['same']);
        self::assertSame(1, $out['created']);
    }

    public function test_a_probe_that_throws_is_still_a_dead_connection(): void
    {
        $f = new class extends \stdClass implements \Flytachi\Winter\CPool\ConnectionFactory {
            public int $created = 0;
            public int $closed = 0;
            public function create(): object
            {
                return (object) ['id' => ++$this->created];
            }
            public function validate(object $connection): bool
            {
                throw new \RuntimeException('gone');
            }
            public function close(object $connection): void
            {
                ++$this->closed;
            }
        };
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(keepaliveTime: 0.0, idleTimeout: 0.0), static function () use (&$time): float {
                return $time;
            });
            $old = $pool->borrow();
            $pool->release($old);
            $time = 1010.0;
            $out['fresh'] = $pool->borrow() !== $old;
            $out['closed'] = $f->closed;
            $pool->close();
        });

        self::assertTrue($out['fresh']);
        self::assertSame(1, $out['closed']);
    }
}
