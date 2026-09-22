<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionPool;
use Flytachi\Winter\CPool\PoolEntry;
use Flytachi\Winter\CPool\PoolException;
use Flytachi\Winter\CPool\PoolPolicy;
use PHPUnit\Framework\TestCase;

/**
 * ConnectionPool behaviour under a real Swoole coroutine, driven by a mock factory
 * and a controllable clock — so idle-gating and maxLifetime are deterministic
 * without a live database. Each case runs its logic inside Swoole\Coroutine\run and
 * captures the outcome, asserting after the scheduler returns.
 */
final class ConnectionPoolTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ConnectionPool needs a Swoole coroutine context.');
        }
    }

    public function test_borrow_opens_a_connection(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f);
            $e = $pool->borrow();
            $out = ['isEntry' => $e instanceof PoolEntry, 'created' => $f->created, 'validated' => $f->validated];
            $pool->close();
        });

        self::assertTrue($out['isEntry']);
        self::assertSame(1, $out['created']);
        self::assertSame(0, $out['validated'], 'a freshly opened connection is not probed');
    }

    public function test_reuses_idle_connection_within_bypass_window(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(aliveBypassWindow: 0.5));
            $a = $pool->borrow();
            $pool->release($a);
            $b = $pool->borrow();   // immediate → idle < window → no probe
            $out = ['same' => $a === $b, 'created' => $f->created, 'validated' => $f->validated];
            $pool->close();
        });

        self::assertTrue($out['same'], 'idle connection is reused');
        self::assertSame(1, $out['created']);
        self::assertSame(0, $out['validated'], 'hot connection skips the probe');
    }

    public function test_probes_connection_idle_beyond_bypass_window(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(aliveBypassWindow: 0.5), static function () use (&$time): float {
                return $time;
            });
            $a = $pool->borrow();
            $pool->release($a);
            $time = 1002.0;          // idle 2s > 0.5 window
            $b = $pool->borrow();    // → probe (alive) → reuse
            $out = ['same' => $a === $b, 'validated' => $f->validated, 'created' => $f->created];
            $pool->close();
        });

        self::assertTrue($out['same']);
        self::assertSame(1, $out['validated'], 'idle-beyond-window connection is probed');
        self::assertSame(1, $out['created']);
    }

    public function test_retires_dead_connection_and_opens_fresh(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(aliveBypassWindow: 0.5), static function () use (&$time): float {
                return $time;
            });
            $a = $pool->borrow();
            $pool->release($a);
            $time = 1002.0;
            $f->alive = false;       // the pooled connection has died
            $b = $pool->borrow();    // probe fails → retire $a → open fresh
            $out = ['same' => $a === $b, 'created' => $f->created, 'closed' => $f->closed];
            $pool->close();
        });

        self::assertFalse($out['same'], 'a fresh connection replaces the dead one');
        self::assertSame(2, $out['created']);
        self::assertSame(1, $out['closed'], 'the dead connection was closed');
    }

    public function test_retires_connection_past_max_lifetime(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maxLifetime: 10.0, aliveBypassWindow: 0.5, maxLifetimeJitter: 0.0),
                static function () use (&$time): float {
                    return $time;
                },
            );
            $a = $pool->borrow();    // expires at 1010
            $pool->release($a);
            $time = 1011.0;          // past maxLifetime
            $b = $pool->borrow();    // expired → retire → fresh
            $out = ['same' => $a === $b, 'created' => $f->created];
            $pool->close();
        });

        self::assertFalse($out['same']);
        self::assertSame(2, $out['created']);
    }

    public function test_borrow_after_idle_replaces_every_dead_connection(): void
    {
        $f = new MockFactory();
        $time = 1000.0;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$time, &$out): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maximumPoolSize: 5, connectionTimeout: 0.05),
                static function () use (&$time): float {
                    return $time;
                },
            );

            // Traffic: the pool grows to its ceiling, then everything returns idle.
            $held = [];
            for ($i = 0; $i < 5; ++$i) {
                $held[] = $pool->borrow();
            }
            foreach ($held as $entry) {
                $pool->release($entry);
            }

            // Ten minutes of silence, and the server dropped every socket meanwhile.
            $time += 600.0;
            $f->alive = false;

            try {
                $pool->borrow();
                $out = ['ok' => true, 'created' => $f->created, 'closed' => $f->closed];
            } catch (PoolException $e) {
                $out = ['ok' => false, 'message' => $e->getMessage()];
            }
            $pool->close();
        });

        self::assertTrue($out['ok'], $out['message'] ?? '');
        self::assertSame(6, $out['created'], 'all five corpses are discarded and a sixth connection opened');
        self::assertSame(5, $out['closed']);
    }

    public function test_exhaustion_throws_after_connection_timeout(): void
    {
        $f = new MockFactory();
        $caught = false;
        $created = 0;
        \Swoole\Coroutine\run(function () use ($f, &$caught, &$created): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 2, connectionTimeout: 0.05));
            $pool->borrow();
            $pool->borrow();          // pool full (2/2), both held
            try {
                $pool->borrow();      // no release → waits 0.05s → exhausted
            } catch (PoolException) {
                $caught = true;
            }
            $created = $f->created;
            $pool->close();
        });

        self::assertTrue($caught, 'a full pool fails fast after connectionTimeout');
        self::assertSame(2, $created);
    }

    public function test_a_stillborn_connection_stops_further_opening(): void
    {
        $f = new MockFactory();
        // Opening succeeds, the probe does not: the server is up but not serving
        // (PostgreSQL still starting, Redis still loading its dataset).
        $f->alive = false;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(
                maximumPoolSize: 5,
                connectionTimeout: 0.005,   // shorter than the penalty, which therefore outlives the borrow
                aliveBypassWindow: 0.0,     // probe on every borrow, a fresh entry included
            ));

            try {
                $pool->borrow();
            } catch (PoolException) {
            }
            $out['afterFirst'] = $f->created;

            try {
                $pool->borrow();
            } catch (PoolException) {
            }
            $out['afterSecond'] = $f->created;
            $pool->close();
        });

        self::assertSame(1, $out['afterFirst'], 'one socket is opened, then creation is penalised');
        self::assertSame(1, $out['afterSecond'], 'a borrow inside the penalty window opens no socket at all');
    }

    public function test_creation_resumes_once_the_penalty_expires(): void
    {
        $f = new MockFactory();
        $f->alive = false;
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(
                maximumPoolSize: 5,
                connectionTimeout: 0.005,
                aliveBypassWindow: 0.0,
            ));

            try {
                $pool->borrow();          // stillborn → creation penalised
            } catch (PoolException) {
            }

            $f->alive = true;             // the server started serving
            \Swoole\Coroutine::sleep(0.02);

            $entry = $pool->borrow();
            $out = ['isEntry' => $entry instanceof PoolEntry, 'created' => $f->created];
            $pool->close();
        });

        self::assertTrue($out['isEntry'], 'the pool opens again once the penalty has expired');
        self::assertSame(2, $out['created'], 'exactly one socket per attempt — no storm in between');
    }

    public function test_connect_failure_throws(): void
    {
        $f = new MockFactory();
        $f->failCreate = true;
        $caught = false;
        \Swoole\Coroutine\run(function () use ($f, &$caught): void {
            $pool = new ConnectionPool($f);
            try {
                $pool->borrow();
            } catch (PoolException) {
                $caught = true;
            }
            $pool->close();
        });

        self::assertTrue($caught);
        self::assertSame(0, $f->created);
    }

    public function test_unusable_names_the_dead_connections_it_discarded(): void
    {
        $f = new MockFactory();
        $message = '';
        \Swoole\Coroutine\run(function () use ($f, &$message): void {
            $pool = new ConnectionPool($f, new PoolPolicy(
                maximumPoolSize: 3,
                connectionTimeout: 0.01,
                aliveBypassWindow: 0.0,
            ));

            $held = [];
            for ($i = 0; $i < 3; ++$i) {
                $held[] = $pool->borrow();
            }
            foreach ($held as $entry) {
                $pool->release($entry);
            }

            $f->alive = false;   // the three idle ones died, and a fresh one is stillborn too

            try {
                $pool->borrow();
            } catch (PoolException $e) {
                $message = $e->getMessage();
            }
            $pool->close();
        });

        self::assertStringContainsString('discarded 4 dead connections', $message);
        self::assertStringNotContainsString('attempts', $message, 'the count is of corpses, not of tries');
    }

    public function test_exhaustion_is_diagnosed_apart_from_bad_connections(): void
    {
        $f = new MockFactory();
        $message = '';
        \Swoole\Coroutine\run(function () use ($f, &$message): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 2, connectionTimeout: 0.01));
            $pool->borrow();
            $pool->borrow();          // both healthy, both held
            try {
                $pool->borrow();
            } catch (PoolException $e) {
                $message = $e->getMessage();
            }
            $pool->close();
        });

        self::assertStringContainsString('no free connection', $message);
        self::assertStringNotContainsString('dead connection', $message, 'busy is not the same incident as broken');
    }

    public function test_a_penalised_pool_still_reports_the_driver_error(): void
    {
        $f = new MockFactory();
        $f->failCreate = true;
        $message = '';
        \Swoole\Coroutine\run(function () use ($f, &$message): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 2, connectionTimeout: 0.005));
            try {
                $pool->borrow();      // opening throws → creation is penalised
            } catch (PoolException) {
            }
            try {
                $pool->borrow();      // inside the penalty nothing is opened at all
            } catch (PoolException $e) {
                $message = $e->getMessage();
            }
            $pool->close();
        });

        self::assertStringContainsString('connect refused', $message, "the driver's own error, not a sizing hint");
        self::assertSame(0, $f->created, 'the penalty holds the door shut');
    }

    public function test_max_lifetime_jitter_within_bounds(): void
    {
        $f = new MockFactory();
        $expiry = null;
        \Swoole\Coroutine\run(function () use ($f, &$expiry): void {
            $pool = new ConnectionPool(
                $f,
                new PoolPolicy(maxLifetime: 100.0, maxLifetimeJitter: 0.1),
                static fn(): float => 1000.0,
            );
            $expiry = $pool->borrow()->expiresAt;
            $pool->close();
        });

        self::assertNotNull($expiry);
        self::assertGreaterThanOrEqual(1000.0 + 90.0, $expiry, 'within -10% jitter');
        self::assertLessThanOrEqual(1000.0 + 110.0, $expiry, 'within +10% jitter');
    }

    public function test_evict_retires_the_connection_and_frees_its_slot(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 1));
            $a = $pool->borrow();

            $pool->evict($a);           // died in use — retire instead of returning it

            $b = $pool->borrow();       // the freed slot allows a fresh connection
            $out = ['same' => $a === $b, 'created' => $f->created, 'closed' => $f->closed];
            $pool->close();
        });

        self::assertFalse($out['same'], 'an evicted connection is never handed out again');
        self::assertSame(2, $out['created']);
        self::assertSame(1, $out['closed']);
    }

    public function test_stats_track_total_idle_active(): void
    {
        $f = new MockFactory();
        $out = [];
        \Swoole\Coroutine\run(function () use ($f, &$out): void {
            $pool = new ConnectionPool($f, new PoolPolicy(maximumPoolSize: 5));
            $a = $pool->borrow();
            $pool->borrow();
            $out['held'] = $pool->stats();
            $pool->release($a);
            $out['oneBack'] = $pool->stats();
            $pool->close();
        });

        self::assertSame(['total' => 2, 'idle' => 0, 'active' => 2, 'maximum' => 5], $out['held']);
        self::assertSame(['total' => 2, 'idle' => 1, 'active' => 1, 'maximum' => 5], $out['oneBack']);
    }
}
