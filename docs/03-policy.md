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
| `keepaliveTime` | `0.0` | Ping connections idle longer than this (`0` — off) |
| `idleTimeout` | `0.0` | Close connections idle longer than this (`0` — off) |
| `minimumIdle` | `0` | Never shrink below this many |

`housekeepingEnabled()` is false unless `keepaliveTime` or `idleTimeout` is set, and the timer
is only armed under Swoole. **With the defaults there is no timer at all** — the pool costs
nothing when idle.

Turn on `keepaliveTime` when a firewall or database drops idle connections: a periodic ping
keeps them from silently dying between requests. Turn on `idleTimeout` when traffic is spiky
and holding the peak-time pool open all night is wasteful — with `minimumIdle` as the floor so
the next burst does not start from zero.

The two are opposites: keepalive keeps connections alive, idle timeout lets them go. Enabling
both is coherent (keep a warm floor, release the rest) as long as `minimumIdle` is set.

## Related

- [Model](01-model.md) — where each of these is consulted
- [Runtimes](04-runtimes.md) — why housekeeping is Swoole-only
