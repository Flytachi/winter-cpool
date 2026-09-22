# winter-cpool — internal reference

Technical notes for the pool itself: the state machine a connection moves through, what each
policy knob actually changes, and the reasoning behind decisions that are not obvious from the
code.

This is **not** the getting-started guide — that is the [root README](../README.md). These
pages assume you are changing the pool, writing an adapter for a new driver, or explaining to
yourself why a connection was retired.

---

## Map

| # | Page | Go here when |
|---|------|--------------|
| 01 | [Model](01-model.md) | You want the whole thing in one picture — borrow, release, evict |
| 02 | [Adapters](02-adapters.md) | Writing a `ConnectionFactory` for a driver |
| 03 | [Policy](03-policy.md) | Choosing limits, lifetimes and housekeeping |
| 04 | [Runtimes](04-runtimes.md) | Swoole vs everything else, and fork safety |

---

## Invariants these pages rely on

Break one of these and the rest stops being true.

1. **The pool knows nothing about the driver.** No PDO, no Redis, no protocol — only
   `create` / `validate` / `close`. Anything driver-shaped that appears in the pool is a bug in
   the design, not a feature.
2. **A borrowed connection belongs to exactly one borrower** until it is released. Concurrency
   safety rests entirely on this: two coroutines must never hold the same resource.
3. **Every borrow returns a live connection or throws.** A dead one is retired and replaced;
   the caller never receives a socket the pool knows to be broken.
4. **The ceiling is absolute.** `maximumPoolSize` is never exceeded — under pressure borrowers
   queue, and past `connectionTimeout` they get `PoolException::exhausted()`. Turning a limit
   into an outage of the database is worse than turning it into a queue.
5. **Housekeeping is Swoole-only, and armed by use.** The timer starts on the first borrow,
   never on construction, and `close()` must clear it — a pool nobody borrowed from costs
   nothing, and a pool that is never closed keeps the reactor alive.

---

## Routes through it

- **"Connections leak — `active` never drops"** → a borrower that does not `release()`. The
  pool cannot detect it; look for a `borrow()` without `finally`.
- **"Why did a working connection get closed?"** → `maxLifetime`, plus jitter. See
  [Policy](03-policy.md).
- **"The first request after a quiet period fails"** → the pool was holding sockets the server
  had dropped. It buries them and reopens inside `connectionTimeout`; if it still throws, read
  *which* exception — see [Model](01-model.md).
- **"Nothing is pooled at all"** → no active Swoole runtime, so `SingleConnection` is in play.
  See [Runtimes](04-runtimes.md).
- **"The pool survives a fork wrongly"** → `abandon()` vs `close()`, same page.
- **"Adding a driver"** → [Adapters](02-adapters.md); the probe is the part people get wrong.

---

## Keeping it honest

Every code sample here is meant to run as written against the current `src/`. Behaviour claims
belong in a test, and the page should say which one — the pool is the component whose failures
are hardest to reproduce by hand.
