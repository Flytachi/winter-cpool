<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

use RuntimeException;
use Throwable;

/**
 * Thrown when the pool cannot hand out a usable connection — exhaustion within
 * {@see PoolPolicy::$connectionTimeout}, a failed open, or repeated dead borrows.
 *
 * @link https://winterframe.net/packages/cpool/api-reference#poolexception-final-class PoolException reference
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

    /**
     * No live connection within the borrow's window — the connections themselves are
     * failing, not merely busy.
     *
     * The number is of connections **thrown away**, not of tries: a borrow keeps
     * discarding what it finds until it reaches a live connection or runs out of time,
     * and how many corpses it buried is what tells an operator what happened.
     *
     * @param int $retired Dead connections discarded before giving up.
     * @param float $timeout The borrow window, from {@see PoolPolicy::$connectionTimeout}.
     */
    public static function unusable(int $retired, float $timeout): self
    {
        return new self(sprintf(
            'ConnectionPool: discarded %d dead connection%s within %.3gs without reaching a live one'
            . ' — the connections are failing, not merely busy.',
            $retired,
            $retired === 1 ? '' : 's',
            $timeout,
        ));
    }
}
