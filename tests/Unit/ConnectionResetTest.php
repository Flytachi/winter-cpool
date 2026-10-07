<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionPool;
use PHPUnit\Framework\TestCase;

/**
 * The return-time reset: a connection goes back to the pool only once its factory has
 * put it back into a clean state, and is retired when that cannot be done. Without it
 * a connection returned mid-transaction hands that transaction to the next borrower.
 */
final class ConnectionResetTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs a Swoole coroutine context.');
        }
    }

    public function test_release_resets_and_reuses_a_clean_connection(): void
    {
        $f = new ResettingMockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f);
            $a = $pool->borrow();
            $pool->release($a);
            $b = $pool->borrow();
            $out = ['same' => $a === $b, 'reset' => $f->reset, 'closed' => $f->closed];
            $pool->close();
        });

        self::assertTrue($out['same'], 'a connection reset to clean is reused');
        self::assertSame(1, $out['reset'], 'release resets exactly once');
        self::assertSame(0, $out['closed']);
    }

    public function test_release_retires_a_connection_that_cannot_be_reset(): void
    {
        $f = new ResettingMockFactory();
        $f->clean = false;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f);
            $a = $pool->borrow();
            $pool->release($a);
            $out['afterRelease'] = $pool->stats();
            $b = $pool->borrow();
            $out += ['same' => $a === $b, 'created' => $f->created, 'closed' => $f->closed];
            $pool->close();
        });

        self::assertSame(['total' => 0, 'idle' => 0, 'active' => 0, 'maximum' => 10], $out['afterRelease']);
        self::assertFalse($out['same'], 'a dirty connection is never handed out again');
        self::assertSame(2, $out['created']);
        self::assertSame(1, $out['closed']);
    }

    public function test_release_retires_a_connection_whose_reset_throws(): void
    {
        $f = new ResettingMockFactory();
        $f->resetThrows = true;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f);
            $a = $pool->borrow();
            $pool->release($a);         // must not throw out of a coroutine's defer
            $b = $pool->borrow();
            $out = ['same' => $a === $b, 'closed' => $f->closed];
            $pool->close();
        });

        self::assertFalse($out['same'], 'a throwing reset counts as "cannot be reset"');
        self::assertSame(1, $out['closed']);
    }

    public function test_evict_does_not_reset(): void
    {
        $f = new ResettingMockFactory();
        \Swoole\Coroutine\run(function () use ($f): void {
            $pool = new ConnectionPool($f);
            $pool->evict($pool->borrow());
            $pool->close();
        });

        self::assertSame(0, $f->reset, 'a dead connection is closed, not cleaned');
    }
}
