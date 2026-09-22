# 1. Model

The pool is a bounded set of connections plus a queue of idle ones. Everything else —
lifetimes, probes, housekeeping — is bookkeeping around those two facts.

## The pieces

| Type | Role |
|---|---|
| `ConnectionFactory` | the only driver-aware part: `create` / `validate` / `close` |
| `PoolEntry` | one connection plus its timestamps (`createdAt`, `lastUsedAt`, `expiresAt`) |
| `ConnectionPool` | the pool proper — borrow, release, evict, housekeeping |
| `SingleConnection` | same contract for runtimes with no concurrency |
| `PoolPolicy` | immutable settings |
| `PoolException` | the only exception, with three named constructors |

The idle set is a `Swoole\Coroutine\Channel`. That is what makes waiting cheap: a borrower with
no free connection suspends on the channel instead of spinning, and `release()` wakes exactly
one waiter.

## What `borrow()` does

```
borrow()
  ├─ ensureHousekeeper()          arm the sweep timer if the policy asks for one
  └─ loop until the deadline      deadline = now + connectionTimeout
       ├─ acquire()               idle entry → else open one (unless penalised) → else wait
       │    └─ nothing came free in what is left of the budget → PoolException
       ├─ expired? or probe failed? → discard, count it, go round again
       │    └─ and if this borrow is what opened it → penalise opening
       └─ stamp lastUsedAt, return the entry
```

Three details worth internalising:

**Creating is preferred over waiting.** `acquire()` only waits on the channel once the pool is
at `maximumPoolSize`. Below the ceiling a borrower opens a new connection rather than queueing
behind someone else's.

**The loop is bounded by time, not by tries.** `connectionTimeout` is the deadline for the
whole borrow — every wait inside it gets only what is left. Discarding a dead entry is not a
failed attempt; it is the work. A pool that sat idle while the server (or a firewall) dropped
its sockets holds nothing but corpses, and burying five of them before opening a live
connection is a correct borrow, not a broken one.

> This is where the pool used to be wrong. The retire loop was bounded by a fixed budget of
> four tries, on the reasoning that a discarded entry is replaced by a freshly created one so
> the budget would never run out. It does not hold: `acquire()` prefers the idle channel over
> creating, so while corpses remain it keeps drawing corpses. A pool of four or more dead
> connections therefore failed the borrow *one step short* of the fresh connection that was
> about to succeed — reliably, on the first request after any idle period long enough for the
> sockets to die. HikariCP bounds the same loop by its `connectionTimeout` and carries no
> attempt counter; so does this pool now.

**Opening is penalised after it fails.** When `create()` throws — or succeeds and hands back a
connection that cannot answer — opening is held shut for a doubling interval (10 ms, then
20, 40 … up to 5 s), cleared by the first connection that opens and answers. A server that
takes sockets but does not serve on them (PostgreSQL still starting, Redis still loading its
dataset) would otherwise be met with a fresh socket per loop iteration per request: measured
at 10 038 opens in five milliseconds before this existed. The penalty is pool-wide, not
per-borrow, because a per-borrow budget still lets every concurrent request open its own.

HikariCP solves this by never opening a connection on the borrower's thread at all — creation
belongs to a background executor with exactly this backoff. That shape needs a permanently
warm pool, which is the wrong trade here: `minimumIdle` defaults to `0`, and under Swoole a
warm pool multiplies by `worker_num`. So the borrower still opens its own connection, and the
throttle is what came across.

## Which exception means what

| Exception | Meaning | Typical cause |
|---|---|---|
| `exhausted()` | every connection is busy | the pool is too small, or a borrow is missing its `release()` |
| `connectFailed()` | the connection could not be opened, carrying the driver's own message | server down, DNS, credentials, TLS |
| `unusable()` | the window was spent on connections that would not answer | the server is reachable but not serving, or sockets die faster than they can be replaced |

The three are deliberately distinct: "all busy" and "all broken" call for opposite reactions,
and an operator told to raise `maximumPoolSize` while the database refuses to serve is being
sent the wrong way.

## What `release()` does

Stamps `lastUsedAt` and pushes the entry back into the idle channel. Nothing else — no probe,
no close. Validation happens on the way *out*, not on the way *in*, so a connection that broke
while idle is caught by the next borrower rather than by the previous one.

If the pool has already been closed (`idle === null`), the returned entry is discarded instead.

## When a connection is probed

Not on every borrow. `needsProbe()` skips the check when the entry was used within
`aliveBypassWindow` (0.5 s by default):

```php
($now - $entry->lastUsedAt) > $policy->aliveBypassWindow
```

The reasoning: a connection that answered 200 ms ago is alive, and a `SELECT 1` before every
query would double the round trips of a busy application. A connection that has been idle for a
minute gets probed, because that is where the risk actually is.

A newly created connection is covered by the same rule — its `lastUsedAt` is the moment of
creation, so it is never probed on the borrow that created it. Probing a socket you have just
opened would only pay for the handshake twice.

## Expiry

`expiresAt` is set at creation from `maxLifetime`, with jitter applied per entry. A borrowed
entry past its expiry is discarded and replaced — never handed out. Jitter matters more than it
looks: without it a pool created at boot expires as one block, and the application stalls while
ten connections reconnect simultaneously.

## States

```
        create()                borrow()                 release()
   ∅ ──────────► idle ──────────────────► borrowed ──────────────► idle
                  │                          │
                  │ expired / probe failed   │ evict()
                  ▼                          ▼
               discard() ──── factory->close() ──── ∅
```

`evict()` exists for the caller who *knows* a connection is broken — a driver error that
implies a dead socket. Returning it via `release()` would put a known-bad connection back into
circulation, to be discovered by the next borrower.

## Related

- [Adapters](02-adapters.md) — what `validate()` must and must not do
- [Policy](03-policy.md) — every knob and what it changes
- [Runtimes](04-runtimes.md) — the no-Swoole path and fork safety
