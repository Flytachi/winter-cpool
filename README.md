# Winter CPool

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cpool.svg)](https://packagist.org/packages/flytachi/winter-cpool)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-cpool.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-cpool)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

A connection pool that knows nothing about connections.

The pool handles borrowing, returning, capacity, lifetime and liveness; **what** is being
pooled is supplied by an adapter of three methods. A database adapter opens a PDO and probes
with `SELECT 1`; a Redis adapter opens a `\Redis` and probes with `PING`. The pool cannot tell
them apart, and that is the point.

Built for long-running PHP: under Swoole it hands a separate connection to every coroutine, and
everywhere else it degrades to a single shared connection — the correct answer when a process
serves one request at a time.

---

## Installation

```bash
composer require flytachi/winter-cpool
```

Requires PHP **8.4+**. `ext-swoole` is optional: with it you get real pooling, without it the
same code keeps working through `SingleConnection`.

---

## The adapter

Everything driver-specific lives here — three methods, nothing else:

```php
use Flytachi\Winter\CPool\ConnectionFactory;

final class PdoFactory implements ConnectionFactory
{
    public function __construct(private readonly string $dsn) {}

    public function create(): object
    {
        return new PDO($this->dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function validate(object $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;           // dead → the pool retires it and opens a fresh one
        }
    }

    public function close(object $connection): void
    {
        // PDO closes on dereference; other drivers may need an explicit call
    }
}
```

`validate()` must not throw for "dead" — return `false`. A thrown probe is treated as dead too,
so a sloppy adapter still cannot corrupt the pool.

---

## Borrow and return

```php
use Flytachi\Winter\CPool\{ConnectionPool, PoolPolicy};

$pool = new ConnectionPool(new PdoFactory($dsn), new PoolPolicy(maximumPoolSize: 10));

$entry = $pool->borrow();               // waits for a free slot, up to connectionTimeout
try {
    /** @var PDO $pdo */
    $pdo = $entry->resource;
    $pdo->query('SELECT now()');
} finally {
    $pool->release($entry);             // always return it — a leaked entry shrinks the pool
}
```

`borrow()` gives back a `PoolEntry`; the connection itself is `$entry->resource`. Returning is
the caller's job, so `finally` is the shape to use — or wrap it in a facade that returns
automatically at the end of a request (which is what the Winter framework does with a
coroutine `defer`).

When every connection is busy and the pool is at its ceiling, `borrow()` waits. Past
`connectionTimeout` it throws `PoolException::exhausted()` rather than opening connection
number 10 001 — an exhausted pool is a queue, not an outage of the database.

### Outside a coroutine

Without an active Swoole runtime there is nothing to pool: a process serves one unit of work at
a time. `SingleConnection` is the same contract with that assumption baked in.

```php
use Flytachi\Winter\CPool\SingleConnection;

$single = new SingleConnection(new PdoFactory($dsn));

$pdo = $single->get();      // opens on first call
$pdo = $single->get();      // the same object, revalidated when it has been idle
```

It still honours `maxLifetime` and re-validates a connection that has been sitting idle, so a
long-lived CLI worker does not wake up holding a socket the server closed hours ago.

---

## Policy

Every knob has a default; pass only what you mean to change.

| Option | Default | What it does |
|---|---|---|
| `maximumPoolSize` | `10` | Ceiling on open connections. Beyond it, borrowers queue |
| `connectionTimeout` | `15.0` | How long a borrower waits before `exhausted()` |
| `maxLifetime` | `1800.0` | A connection older than this is retired on return |
| `maxLifetimeJitter` | `0.1` | Spreads expiry so a pool does not recycle all at once |
| `aliveBypassWindow` | `0.5` | Skip the liveness probe for a connection used this recently |
| `housekeepingInterval` | `30.0` | How often the background sweep runs |
| `keepaliveTime` | `0.0` | Ping idle connections this often (`0` — off) |
| `idleTimeout` | `0.0` | Close connections idle longer than this (`0` — off) |
| `minimumIdle` | `0` | Keep at least this many warm |

Housekeeping only runs under Swoole, and only when something enables it — a pool with the
defaults costs no timer.

---

## What you get from it

- **A hard ceiling.** A thousand concurrent requests cannot become a thousand connections;
  they queue instead, and the database is never the thing that falls over.
- **Dead connections are replaced, not returned.** Every borrow revalidates (outside the
  bypass window), so a database restart heals itself instead of poisoning a resident worker.
- **Ageing under control.** `maxLifetime` plus jitter retires connections gradually, which
  matters behind proxies and failover addresses that quietly move.
- **One implementation for both runtimes.** The calling code does not branch on Swoole.

---

## Fork safety

A `fork()` copies file descriptors. A child that closes an inherited socket tears down the
connection its parent is still using — so after forking, a child calls `abandon()`, not
`close()`:

```php
$pool->abandon();   // drop every pooled connection WITHOUT closing it, stop the housekeeper
$pool->close();     // the normal path: close everything and stop the housekeeper
```

Both clear the housekeeping timer, and that part is not optional: a `Timer::tick` callback
holds a reference to the pool, so a pool merely dereferenced would stay alive and keep
maintaining connections nobody uses.

---

## Observability

```php
$pool->stats();   // ['total' => 2, 'idle' => 2, 'active' => 0, 'maximum' => 10]
```

`active` climbing to `maximum` while borrowers wait is the signal to raise the ceiling — or to
find the code path that borrows without returning.

---

## Contributing

```bash
composer test        # phpunit
composer test-detail # phpunit --testdox
composer cs-check    # phpcs
composer cs-fix      # phpcbf
```

---

## License

MIT License. See [LICENSE](LICENSE).
