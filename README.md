# Winter CPool

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-cpool.svg)](https://packagist.org/packages/flytachi/winter-cpool)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-cpool.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-cpool)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

📖 **[Documentation](https://winterframe.net/packages/cpool)** · [Quick start](https://winterframe.net/packages/cpool/quickstart) · [API reference](https://winterframe.net/packages/cpool/api-reference)

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

$entry = $pool->borrow();               // a live connection, or throws within connectionTimeout
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

`connectionTimeout` is the deadline for the whole borrow, not for each wait inside it. A pool
that sat idle while the server dropped its sockets holds only dead connections: the borrow
discards them one after another and opens a fresh one, and all of that comes out of the same
budget. The first request after a long pause therefore pays for one reconnect and succeeds —
it does not fail because the corpses used up a retry counter.

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
| `connectionTimeout` | `15.0` | Deadline for a whole borrow — waiting, retiring and reopening |
| `maxLifetime` | `1800.0` | A connection older than this is retired on return |
| `maxLifetimeJitter` | `0.1` | Spreads expiry so a pool does not recycle all at once |
| `aliveBypassWindow` | `0.5` | Skip the liveness probe for a connection used this recently |
| `housekeepingInterval` | `30.0` | How often the background sweep runs |
| `keepaliveTime` | `120.0` | Ping idle connections this often (`0` — off) |
| `idleTimeout` | `600.0` | Close connections idle longer than this (`0` — off) |
| `minimumIdle` | `0` | Keep at least this many warm |

Housekeeping is on by default: idle connections are pinged every two minutes so the server or
a firewall cannot drop them unnoticed, and released after ten idle minutes instead of being
held until traffic returns. The timer is armed by the first borrow — a pool nobody uses costs
nothing — and cleared by `close()`, which a long-running server calls on worker exit. In a
script, close the pool yourself: a live repeating timer keeps the Swoole reactor alive.

The three lifecycle deadlines belong in this order, and it is worth getting right:

```
keepaliveTime  <  idleTimeout  <  maxLifetime  <  whatever kills idle connections upstream
```

Each one only matters while the connection is still there to receive it — a keepalive longer
than `maxLifetime` pings a connection that was already rotated, and one longer than
`idleTimeout` pings a connection that was already closed. The pool drops an unreachable
`keepaliveTime` to `0.0` when the policy is built rather than pretending to honour it, so
`$policy->keepaliveTime` always reads as what will actually happen. See
[Policy](docs/03-policy.md) for the details and for the upper bound the pool cannot check.

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

## Documentation

The user-facing documentation lives at **[winterframe.net/packages/cpool](https://winterframe.net/packages/cpool)**
(the link picks your language; RU and EN are both complete).

**Start here**

| Page | What it answers |
|------|-----------------|
| [Introduction](https://winterframe.net/packages/cpool/intro) | Why a pool exists, and what this one deliberately does not do |
| [Installation](https://winterframe.net/packages/cpool/installation) | Requirements, and what changes with and without `ext-swoole` |
| [Quick start](https://winterframe.net/packages/cpool/quickstart) | Adapter, pool, borrow and release — a working example |

**Guides**

| Page | What it answers |
|------|-----------------|
| [Writing an adapter](https://winterframe.net/packages/cpool/writing-an-adapter) | The three methods, what `validate()` should probe, what an adapter must never do |

**Reference**

| Page | What it answers |
|------|-----------------|
| [API reference](https://winterframe.net/packages/cpool/api-reference) | Every type, method and exception |
| [Policy](https://winterframe.net/packages/cpool/policy) | Each knob, what it changes and how to pick a value |

---

## Contributing

Internal technical notes — the connection state machine, what each policy knob changes, and
the reasoning behind decisions that are not obvious from the code — live in
[`docs/`](docs/README.md). Read those before changing how a connection is borrowed or retired.

```bash
XDEBUG_MODE=off composer test   # phpunit (see CONTRIBUTING for why the env var)
composer test-detail            # phpunit --testdox
composer cs-check               # phpcs
composer cs-fix                 # phpcbf
```

- Setup, checks and the testing philosophy: [CONTRIBUTING.md](CONTRIBUTING.md)
- Changes and upgrade notes: [CHANGELOG.md](CHANGELOG.md)
- Reporting a vulnerability, and what the pool does and does not guarantee:
  [SECURITY.md](SECURITY.md)

---

## License

MIT License. See [LICENSE](LICENSE).
