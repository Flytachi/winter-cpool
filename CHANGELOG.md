# Changelog

All notable changes to `flytachi/winter-cpool` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`borrow()` no longer gives up on a pool full of dead connections.** The retire loop was
  bounded by a fixed budget of four tries, and discarding a dead idle entry consumed one of
  them. The reasoning recorded in `docs/01-model.md` — that a discarded entry is immediately
  replaced by a freshly created one, so the budget cannot run out — does not hold: `acquire()`
  prefers the idle channel over opening a connection, so while corpses remain it keeps drawing
  corpses. A pool holding four or more connections the server had dropped (the normal state
  after any idle period long enough for sockets to die) therefore spent the entire budget
  burying them and threw `PoolException::unusable()` *one step short* of the fresh connection
  that was about to succeed. With `maximumPoolSize: 5` the first borrow after such a pause
  failed every time; with `10`, the first two did. The loop is now bounded by a deadline, the
  way HikariCP's `getConnection()` is, and carries no attempt counter.

### Added

- **Backoff on opening a connection.** After `create()` throws — or hands back a connection
  that then fails its probe — opening is held shut for 10 ms, doubling per consecutive failure
  up to 5 s, and cleared by the first connection that opens and answers. Without it, a
  deadline-bounded loop facing a server that accepts sockets but does not serve on them
  (PostgreSQL still starting, Redis still loading its dataset) opened **10 038 sockets in five
  milliseconds** — per borrow, and again for every concurrent request. The penalty is
  pool-wide rather than per-borrow, because a per-borrow budget still lets every concurrent
  request open its own socket. It is not configurable: the numbers are HikariCP's, whose
  background connection creator throttles the same way, and there is no useful setting for
  "hammer a failing server harder".

### Changed

- **`connectionTimeout` now bounds the whole borrow**, not each wait inside it. Waiting for a
  free connection, retiring dead ones and opening their replacements share one budget, and
  `acquire()` waits only for what is left of it. Previously a borrow at the ceiling could wait
  `connectionTimeout` four times over — 60 s on defaults, against a documented bound of 15.
- **A borrow that ends empty-handed now names the right incident.** `exhausted()` is reserved
  for a pool whose connections are all busy; a borrow whose window went on dead connections
  throws `unusable()`; one that could not open a connection throws `connectFailed()` carrying
  the driver's own message. An operator is no longer told to raise `maximumPoolSize` while the
  database is refusing to serve.
- **Breaking (diagnostics only):** `PoolException::unusable(int $attempts)` is now
  `unusable(int $retired, float $timeout)`, and reports connections discarded rather than tries
  attempted — `discarded 4 dead connections within 3s without reaching a live one`. Only the
  pool itself constructs it; nothing in `winter-ppa`, `winter-redis` or `winter-kernel` does.

### Notes

No application change is required to pick this up: the defaults are untouched and the public
surface is the same apart from the named constructor above. What changes is behaviour on the
first request after an idle period — where the pool used to throw, it now retires the dead
connections and opens a live one, costing that request a single reconnect.

Covered by six new tests in `tests/Unit/ConnectionPoolTest.php`: the idle-pool regression, the
opening penalty and its expiry, and one per failure diagnosis (`exhausted` / `connectFailed` /
`unusable`) — the last three branches had no coverage at all before.

## [1.0.0] - 2026-08-19

First release. The code is not new — it is extracted verbatim from
`flytachi/winter-kernel`, where it had been running as `Kernel\ConnectionPool`, so that
several packages can pool connections without either depending on the framework or
copying the implementation.

### Added

- **`ConnectionPool`** — bounded pool with borrow/release, per-entry lifetime, liveness
  probing and an optional background sweep. Idle entries wait in a
  `Swoole\Coroutine\Channel`, so a borrower with nothing free suspends rather than spins.
- **`SingleConnection`** — the same contract for runtimes without concurrency (FPM, plain
  CLI): one lazily-opened connection, still honouring `maxLifetime` and revalidation after
  idleness.
- **`ConnectionFactory`** — the adapter interface, three methods (`create`, `validate`,
  `close`). The pool knows nothing else about what it is pooling.
- **`PoolPolicy`** — immutable settings: `maximumPoolSize`, `connectionTimeout`,
  `maxLifetime` (with jitter), `aliveBypassWindow`, and the housekeeping options
  `housekeepingInterval` / `keepaliveTime` / `idleTimeout` / `minimumIdle`.
- **`PoolEntry`**, **`PoolException`** — the borrowed handle and the package's only
  exception, with three named constructors (`exhausted`, `connectFailed`, `unusable`).

### Changed from the in-kernel version

- Namespace `Flytachi\Winter\Kernel\ConnectionPool` → **`Flytachi\Winter\CPool`**.
- A docblock reference pointing at the kernel's `PpaConnectionPool` is gone: a library
  does not name its consumers.
- Four PSR-12 formatting violations inherited from the kernel are fixed.

Behaviour is unchanged — the kernel's 32 pool tests moved with the code and pass as they
were.

### Notes

`ext-swoole` is a `suggest`, not a requirement. Without it the pool degrades to
`SingleConnection`, which is the correct shape when a process serves one unit of work at
a time; the calling code does not branch.

[Unreleased]: https://github.com/flytachi/winter-cpool/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/flytachi/winter-cpool/releases/tag/v1.0.0
