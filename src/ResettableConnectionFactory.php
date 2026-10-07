<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool;

/**
 * A {@see ConnectionFactory} whose connections carry session state that must not
 * travel from one borrower to the next — HikariCP's `resetConnectionState`.
 *
 * A pooled connection outlives the unit of work that used it. Whatever that work left
 * open on it — a transaction above all — is inherited by the next borrower: its writes
 * land inside someone else's transaction and are lost when that one is rolled back, and
 * its own `beginTransaction()` fails with "already active". The liveness probe does not
 * catch this: `SELECT 1` answers fine inside an open transaction.
 *
 * {@see ConnectionPool::release()} calls {@see reset()} before a connection goes back
 * to the idle set. It is a separate interface rather than a fourth method of
 * {@see ConnectionFactory} so that an adapter with nothing to reset needs no change.
 *
 * @link https://winterframe.net/packages/cpool/writing-an-adapter Writing an adapter
 */
interface ResettableConnectionFactory extends ConnectionFactory
{
    /**
     * Brings a returned connection back to a clean state (e.g. rolls back a transaction
     * left open). `true` → the connection is clean and goes back to the pool; `false` →
     * it could not be cleaned and the pool retires it. A thrown exception counts as
     * `false`.
     *
     * Called on every release, so the clean case must be cheap — answer it from state
     * the client already holds rather than from a round trip.
     */
    public function reset(object $connection): bool;
}
