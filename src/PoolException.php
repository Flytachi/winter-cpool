<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

use RuntimeException;
use Throwable;

/**
 * Thrown when the pool cannot hand out a usable connection — exhaustion within
 * {@see PoolPolicy::$connectionTimeout}, a failed open, or repeated dead borrows.
 */
final class PoolException extends RuntimeException
{
    public static function exhausted(float $timeout): self
    {
        return new self(sprintf(
            'ConnectionPool: no free connection within %.3gs — raise maximumPoolSize or connectionTimeout.',
            $timeout,
        ));
    }

    public static function connectFailed(Throwable $previous): self
    {
        return new self(
            'ConnectionPool: connection failed — ' . $previous->getMessage(),
            previous: $previous,
        );
    }

    public static function unusable(int $attempts): self
    {
        return new self("ConnectionPool: could not obtain a live connection after {$attempts} attempts.");
    }
}
