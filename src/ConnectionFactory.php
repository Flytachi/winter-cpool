<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

/**
 * The adapter the pool drives to open, probe and close the pooled resource.
 *
 * The pool is driver-agnostic — it knows nothing about PDO/Redis/etc.; an adapter
 * supplies the three operations. A DB adapter opens a CDO and probes with `SELECT
 * 1`; a Redis adapter opens a `\Redis` and probes with `PING`.
 *
 * @link https://winterframe.net/packages/cpool/writing-an-adapter Writing an adapter: the three methods
 */
interface ConnectionFactory
{
    /**
     * Opens a new connection. May throw — the pool wraps failures in
     * {@see PoolException::connectFailed()}.
     */
    public function create(): object;

    /**
     * Cheap liveness probe (e.g. `SELECT 1` / `PING`). `false` → the pool retires
     * the connection and opens a fresh one. Must not throw for "dead" — return
     * `false` (the pool also treats a thrown probe as dead).
     */
    public function validate(object $connection): bool;

    /** Closes/frees the connection. Should not throw (the pool ignores errors here). */
    public function close(object $connection): void;
}
