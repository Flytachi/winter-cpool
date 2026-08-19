# Changelog

All notable changes to `flytachi/winter-cpool` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
