<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionPool;
use Flytachi\Winter\CPool\PoolPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Timer;

/**
 * Closing a pool must survive being called outside a coroutine.
 *
 * The `workerExit` handler releases the pool's housekeeping timer — a repeating timer
 * keeps the reactor from draining, and the worker is then force-killed with
 * "worker exit timeout". That handler runs **while the reactor is winding down**, which
 * is not a coroutine context, and `Channel::pop()` is a coroutine API:
 *
 *     Swoole\Error: API must be called in the coroutine
 *       ConnectionPool.php(149): Swoole\Coroutine\Channel->pop(0.001)
 *       PpaConnectionPool::shutdown()
 *       WinterApplication::{closure:serveHttp()}      ← workerExit
 *
 * It only fires when the pool still holds an idle connection, which is why an
 * application that never queries never sees it — and why a real one always does.
 *
 * Draining is skipped there rather than made to work: the process is terminating, so the
 * kernel closes the sockets either way, and every database treats a dropped client as
 * routine. What must not be skipped is the timer.
 */
final class CloseOutsideCoroutineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs Swoole.');
        }
    }

    /**
     * A leaked repeating timer would hang PHP's own shutdown (the reactor waits for it),
     * taking the whole suite with it. The assertions run first, so this only decides how
     * a failure is reported, not whether it is caught.
     */
    protected function tearDown(): void
    {
        Timer::clearAll();
    }

    /** Fills the pool inside a coroutine, then hands it back for the caller to close. */
    private function pooledWithIdleConnection(MockFactory $factory): ConnectionPool
    {
        $pool = null;

        // Housekeeping off explicitly: this case is about the drain, and the pool has to
        // come back out of the coroutine still open. A housekeeping timer — which the
        // default policy arms on the first borrow — would keep the reactor alive and
        // `Coroutine\run()` would never return. That is the same property the case below
        // is about, only there it is the subject rather than an obstacle.
        \Swoole\Coroutine\run(static function () use ($factory, &$pool): void {
            $pool = new ConnectionPool(
                $factory,
                new PoolPolicy(maximumPoolSize: 4, keepaliveTime: 0.0, idleTimeout: 0.0),
            );
            $pool->release($pool->borrow());
        });

        return $pool;
    }

    public function test_closing_a_populated_pool_outside_a_coroutine_does_not_throw(): void
    {
        $factory = new MockFactory();
        $pool = $this->pooledWithIdleConnection($factory);

        self::assertSame(1, $pool->stats()['idle'], 'The pool must hold something to drain.');

        $pool->close();

        $this->addToAssertionCount(1);
    }

    /**
     * The timer is the whole reason `workerExit` calls this, so it has to be gone even
     * when the drain is skipped.
     */
    public function test_the_housekeeper_is_released_even_when_the_drain_is_skipped(): void
    {
        $pool = new ConnectionPool(
            new MockFactory(),
            new PoolPolicy(maximumPoolSize: 4, housekeepingInterval: 1.0, keepaliveTime: 1.0),
        );

        // Armed the way the first borrow arms it. It cannot be armed inside a coroutine
        // here: a repeating timer keeps the reactor alive, so `Coroutine\run()` would
        // never return — which is the same property that makes clearing it the whole
        // point of closing on worker exit.
        new ReflectionMethod(ConnectionPool::class, 'ensureHousekeeper')->invoke($pool);

        $timer = new ReflectionProperty(ConnectionPool::class, 'timerId');
        $timerId = $timer->getValue($pool);
        self::assertNotNull($timerId, 'A housekeeping timer is expected.');

        $pool->close();

        self::assertNull($timer->getValue($pool));
        self::assertFalse(Timer::exists($timerId), 'The Swoole timer itself must be gone.');
    }

    /**
     * Inside a coroutine the drain still happens — skipping it is the exit path's
     * concession, not the pool's normal behaviour.
     */
    public function test_inside_a_coroutine_the_connections_are_still_closed(): void
    {
        $factory = new MockFactory();

        \Swoole\Coroutine\run(static function () use ($factory): void {
            $pool = new ConnectionPool($factory, new PoolPolicy(maximumPoolSize: 4));
            $pool->release($pool->borrow());
            $pool->release($pool->borrow());
            $pool->close();
        });

        self::assertSame($factory->created, $factory->closed, 'Every connection made was closed.');
    }

    public function test_closing_an_empty_pool_outside_a_coroutine_is_harmless(): void
    {
        $factory = new MockFactory();
        $pool = new ConnectionPool($factory, new PoolPolicy(maximumPoolSize: 4));

        $pool->close();
        $pool->close();

        $this->addToAssertionCount(1);
    }
}
