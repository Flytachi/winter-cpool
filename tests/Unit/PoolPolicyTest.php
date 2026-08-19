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

    public function test_overrides(): void
    {
        $p = new PoolPolicy(maximumPoolSize: 20, maxLifetime: 0.0);

        self::assertSame(20, $p->maximumPoolSize);
        self::assertSame(0.0, $p->maxLifetime, 'maxLifetime 0 disables rotation');
    }
}
