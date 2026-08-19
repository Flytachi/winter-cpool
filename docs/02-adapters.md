# 2. Adapters

An adapter is the only place that knows what a connection *is*. Three methods:

```php
interface ConnectionFactory
{
    public function create(): object;
    public function validate(object $connection): bool;
    public function close(object $connection): void;
}
```

## `create()`

Opens one connection and returns it. May throw — the pool wraps whatever comes out in
`PoolException::connectFailed()`, so the caller sees one exception type regardless of driver.

Do the full handshake here: connect, authenticate, select the database, set session parameters.
A half-configured connection handed to the pool becomes a half-configured connection handed to
a random borrower later.

## `validate()` — the part people get wrong

It answers one question: *would a query on this connection work right now?*

```php
public function validate(object $connection): bool
{
    try {
        $connection->query('SELECT 1');   // Redis: $connection->ping()
        return true;
    } catch (Throwable) {
        return false;
    }
}
```

Three rules:

**Return `false` for dead — do not throw.** A thrown probe is treated as dead too, so nothing
breaks, but the intent gets muddier and the stack trace is noise.

**Keep it to one round trip.** It runs on most borrows (see the bypass window in
[Model](01-model.md)); anything expensive here is paid by every request.

**Probe the connection, not the schema.** `SELECT 1` and `PING` answer "is the socket alive".
A probe that touches a table also fails when the table is locked, and the pool will
enthusiastically retire perfectly good connections.

## `close()`

Frees the resource. Errors here are ignored by the pool — a connection being closed is already
on its way out, and a throw would only obscure the reason it is being closed.

Some drivers need nothing: PDO closes on dereference, so an empty body is honest. Others hold
an explicit handle and want `->close()`.

## A complete adapter

```php
use Flytachi\Winter\CPool\ConnectionFactory;

final readonly class RedisFactory implements ConnectionFactory
{
    public function __construct(
        private string $host,
        private int    $port = 6379,
        private string $password = '',
        private int    $database = 0,
    ) {}

    public function create(): object
    {
        $redis = new \Redis();
        $redis->connect($this->host, $this->port, 2.0);

        if ($this->password !== '') {
            $redis->auth($this->password);
        }
        $redis->select($this->database);

        return $redis;
    }

    public function validate(object $connection): bool
    {
        try {
            return $connection->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(object $connection): void
    {
        try {
            $connection->close();
        } catch (\Throwable) {
            // already gone — nothing to do
        }
    }
}
```

## What an adapter must not do

**Cache the connection.** The adapter creates; the pool owns. An adapter that memoises its
result turns a pool of ten into one connection shared ten ways — the exact bug pooling exists
to prevent.

**Assume it is called once.** `create()` runs whenever the pool grows, and after every retired
connection. Anything one-off (reading configuration, resolving a host) belongs in the
constructor.

**Hold state that belongs to a request.** The adapter outlives every borrower. A tenant id or a
user context captured in it will leak into other requests' connections.

## Related

- [Model](01-model.md) — where `validate()` sits in the borrow path
- [Policy](03-policy.md) — `aliveBypassWindow` decides how often it runs
