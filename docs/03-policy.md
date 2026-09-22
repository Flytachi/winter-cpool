# 3. Policy

`PoolPolicy` is a `final readonly` value object. Every option has a default; construct it with
named arguments and pass only what you mean to change.

```php
new PoolPolicy(maximumPoolSize: 20, maxLifetime: 600.0);
PoolPolicy::default();     // all defaults
```

## Capacity

| Option | Default | Effect |
|---|---|---|
| `maximumPoolSize` | `10` | Hard ceiling on open connections |
| `connectionTimeout` | `15.0` | Deadline for a whole borrow |

These two are one decision, not two. The ceiling converts "too many connections" from a
database-side outage into an application-side queue; the timeout decides how long that queue is
allowed to grow before the request gives up.

`connectionTimeout` bounds the **borrow**, not each wait inside it. Waiting for a free
connection, retiring dead ones and opening their replacements all come out of the same budget,
and when it runs out the borrow throws — `unusable()` if it was burying dead connections,
`exhausted()` if everything was merely busy. Until this was a deadline the loop could spend
`connectionTimeout` up to four separate times, so the documented bound was not one.

Sizing rule of thumb: the ceiling is a property of **the database**, divided by the number of
workers that talk to it. Four Swoole workers against a Postgres with `max_connections = 100`,
leaving headroom for migrations and psql, is nearer `maximumPoolSize: 15` than `100`.

A pool that is permanently at its ceiling with borrowers waiting is not necessarily too small —
first check for a borrow without a matching `release()`, which shrinks the effective pool by
one every time it happens.

## Lifetime

| Option | Default | Effect |
|---|---|---|
| `maxLifetime` | `1800.0` | Connections older than this are retired on borrow |
| `maxLifetimeJitter` | `0.1` | Fraction of `maxLifetime` spread randomly per entry |

Why retire a working connection at all: proxies, load balancers and failover addresses move
underneath long-lived sockets. A connection opened an hour ago may still answer while pointing
at a server that is being drained. Recycling is how the pool follows infrastructure it cannot
see.

Jitter is not decoration. Ten connections created at boot with identical lifetimes expire in
the same second, and the application stalls while all ten reconnect. Ten percent of thirty
minutes spreads that over three minutes.

## Probing

| Option | Default | Effect |
|---|---|---|
| `aliveBypassWindow` | `0.5` | Skip validation for a connection used this recently |

Set it to `0.0` to probe on every borrow — correct, and it doubles the round trips of a busy
service. Raise it and a broken socket lives slightly longer before being noticed. The default
assumes a connection that answered half a second ago is still alive, which is true unless the
database went down in that window — in which case the query fails and the caller evicts.

## Opening after a failure

Not a knob — the pool decides this one on its own. When opening a connection fails, or the
connection opens and then cannot answer, opening is held shut for 10 ms; each consecutive
failure doubles the wait up to 5 s, and the first connection that opens and answers clears it.

It is deliberately not configurable: there is no value of "hammer a failing server harder"
worth exposing. The numbers are HikariCP's, whose background connection creator throttles
exactly this way. What it buys is visible when a server accepts sockets but does not serve on
them — a database still starting, a cache still loading its dataset: without the penalty a
single borrow opened 10 038 sockets in five milliseconds, and every concurrent request did the
same.

## Housekeeping

| Option | Default | Effect |
|---|---|---|
| `housekeepingInterval` | `30.0` | How often the sweep runs |
| `keepaliveTime` | `120.0` | Ping connections idle longer than this (`0` — off) |
| `idleTimeout` | `600.0` | Close connections idle longer than this (`0` — off) |
| `minimumIdle` | `0` | Never shrink below this many |

**Housekeeping is on by default**, and the two reasons are the same one seen from both ends.
A pool nobody swept holds its sockets forever — `maxLifetime` is checked when a connection is
borrowed, and an idle application borrows nothing, so a pool that grew during a burst at
midnight still holds every socket at dawn. And when the server or a firewall drops those
sockets meanwhile, the first request back is the one that finds out: it has to probe every
corpse and reopen before it can do its own work. `keepaliveTime` stops them dying, and
`idleTimeout` gives them back — neither costs anything a request has to wait for.

The timer is armed by the **first borrow**, not by constructing the pool, and only under
Swoole. An application that never opens a connection still pays exactly nothing. One that does
pays a 30-second tick per pool per worker, plus a ping per idle connection every two minutes.

> A pool that has served a borrow therefore holds a live timer until `close()`, and a live
> repeating timer keeps the Swoole reactor from draining. Under the framework that is already
> handled — `workerExit` closes the pools. In a script or a test, close the pool, or
> `Swoole\Coroutine\run()` will not return.

The sweep probes one connection at a time and returns each to the idle channel before taking
the next, so borrowers always find the rest waiting: only the single connection in flight is
out. A pass therefore costs one round trip per connection that is due a ping — five
connections against a 50 ms server take ~250 ms of background time and nothing of any
request's time.

Turn `keepaliveTime` off (`0.0`) when connections are cheap and the server is local — a ping
every two minutes is not free if the pool is large and the link is not. Turn `idleTimeout` off
when the pool should stay warm between bursts, and then set `minimumIdle` so the next burst
does not start from zero.

The two are opposites: keepalive keeps connections alive, idle timeout lets them go. Enabling
both is coherent (keep a warm floor, release the rest) as long as `minimumIdle` is set.

### The ordering rule

```
keepaliveTime  <  idleTimeout  <  maxLifetime  <  whatever kills idle connections upstream
```

All three are deadlines on the same connection, and each only means something while the
connection is still there to receive it:

- **`keepaliveTime ≥ maxLifetime`** — the connection is rotated before the first ping ever
  reaches it. The pings never happen.
- **`keepaliveTime ≥ idleTimeout`** (with `minimumIdle: 0`) — the connection is closed before
  the first ping reaches it. Same outcome. With a warm floor this is fine: the floor
  connections outlive `idleTimeout`, so keepalive still has work.
- **`maxLifetime ≥ the server's own idle timeout`** — the thing this is all defending against
  wins. Postgres `idle_session_timeout`, a Redis `timeout`, a NAT that forgets the flow: the
  pool has to recycle sooner than they cut, and that upper bound is outside its knowledge.
  Only the first two are checked in code.

The pool applies the first two itself: an unreachable `keepaliveTime` is dropped to `0.0` when
the policy is constructed, so `PoolPolicy::$keepaliveTime` always reads as what the housekeeper
will actually do. A setting that silently does nothing is worse than one that is plainly off —
the operator believes idle connections are being pinged while they quietly die. HikariCP takes
the same line, disabling a `keepaliveTime` that reaches `maxLifetime` (and one below 30 s).

For reference, HikariCP's own defaults sit inside this ordering: `keepaliveTime` 2 min,
`idleTimeout` 10 min, `maxLifetime` 30 min.

## Related

- [Model](01-model.md) — where each of these is consulted
- [Runtimes](04-runtimes.md) — why housekeeping is Swoole-only
