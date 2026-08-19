# 4. Runtimes

Pooling only means something when several units of work run at once. That is a property of the
runtime, and the package covers both cases without asking the caller to branch.

## Under Swoole

One worker process serves many coroutines. Each needs its own connection — a socket carries one
request/response exchange at a time, so two coroutines sharing one produce interleaved traffic
and mixed-up responses.

`ConnectionPool` is for this case. The idle set is a `Swoole\Coroutine\Channel`, so a borrower
with nothing free suspends instead of spinning, and `release()` wakes exactly one waiter.

## Everywhere else

Under FPM or a plain CLI script the process handles one unit of work at a time. There is
nothing to pool, and a pool would only add machinery.

`SingleConnection` is the same idea with that assumption baked in: one connection, opened
lazily, reused. It still honours `maxLifetime` and revalidates after idleness, so a long-lived
CLI worker does not wake up holding a socket the server closed hours ago.

```php
$single = new SingleConnection($factory);
$conn   = $single->get();      // opens on first call, revalidates when stale
$single->evict();              // drop it; the next get() opens a fresh one
```

`peek()` returns the current connection without creating or validating one — for diagnostics
that must not have side effects.

## Choosing between them

The package does not choose for you: it exposes both and lets the caller decide, because the
caller knows the runtime. A facade over the two typically looks like

```php
$conn = Runtime::isSwooleCoroutine()
    ? $pool->borrow()->resource
    : $single->get();
```

with the pooled branch also arranging the release. The Winter framework does this with a
coroutine `defer`, so application code never sees `borrow`/`release` at all.

## Fork safety

`fork()` copies file descriptors. Parent and child then hold the *same* socket, and whichever
closes it first tears down the other's connection.

So a child must forget its inherited connections, not close them:

```php
$pool->abandon();   // drop everything WITHOUT closing, stop the housekeeper
```

`close()` is the normal shutdown path — close every connection and stop the housekeeper.
`abandon()` is the fork-safe counterpart.

Both clear the housekeeping timer, and that part is mandatory in either case: a `Timer::tick`
callback holds a reference to the pool, so a pool that is merely dereferenced stays alive and
keeps maintaining connections nobody uses. That is a leak the garbage collector cannot help
with.

## Related

- [Policy](03-policy.md) — housekeeping options, off by default
- [Model](01-model.md) — the borrow path both runtimes share
