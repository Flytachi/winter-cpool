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
  └─ loop (bounded by MAX_RETIRE_LOOPS)
       ├─ acquire()               idle entry → else create if under ceiling → else wait
       │    └─ nothing within connectionTimeout → PoolException::exhausted()
       ├─ expired? or probe failed? → discard, try again
       └─ stamp lastUsedAt, return the entry
```

Two details worth internalising:

**Creating is preferred over waiting.** `acquire()` only waits on the channel once the pool is
at `maximumPoolSize`. Below the ceiling a borrower opens a new connection rather than queueing
behind someone else's.

**The retire loop is bounded.** Each failed probe discards the entry and tries again; after
`MAX_RETIRE_LOOPS` attempts the pool gives up with `PoolException::unusable()`. That is a
different diagnosis from `exhausted()`: *unusable* says the connections themselves are bad,
*exhausted* says they are all busy.

In practice `unusable()` is hard to reach, and understanding why explains the probe rule.
Below the ceiling a discarded entry is replaced by a **freshly created** one — and a fresh
entry has `lastUsedAt = now`, so `needsProbe()` is false and it is handed out without
validation. Measured: `create=1, probe=0` on the first borrow. So a dead database usually
surfaces as a failed query on a new connection, not as `unusable()`; that exception belongs to
a pool sitting at its ceiling whose idle entries keep failing.

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
