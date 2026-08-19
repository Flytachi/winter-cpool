<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

/**
 * A pooled connection plus its lifecycle metadata. `lastUsedAt` (mutable) drives
 * the idle-gated liveness probe; `expiresAt` drives maxLifetime rotation.
 */
final class PoolEntry
{
    /**
     * @param object $resource The wrapped connection (opaque to the pool).
     * @param float $createdAt Monotonic seconds when the connection was opened.
     * @param float $lastUsedAt Monotonic seconds of the last borrow/return.
     * @param float|null $expiresAt Monotonic seconds when maxLifetime elapses (`null` = never).
     */
    public function __construct(
        public readonly object $resource,
        public readonly float $createdAt,
        public float $lastUsedAt,
        public readonly ?float $expiresAt,
    ) {
    }
}
