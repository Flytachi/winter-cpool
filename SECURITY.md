# Security Policy

## Supported Versions

Security fixes are provided for the latest minor release line. Please upgrade to a
supported version before reporting.

| Version | Supported |
|---------|:---------:|
| 1.0.x   | ✅ |

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report suspected vulnerabilities privately to **jason.khan.x@gmail.com**, or via GitHub's
private ["Report a vulnerability"](https://github.com/flytachi/winter-cpool/security/advisories/new)
advisory form.

Include, where possible:

- affected version(s), PHP version, and whether Swoole was active;
- a minimal reproduction — the adapter, the policy, and the borrow/release sequence;
- the impact you believe it has.

You can expect an initial acknowledgement within a few days. Once confirmed, a fix and a
patched release will be prepared, and the reporter credited unless anonymity is requested.

## Security Model

This package handles no user input, parses nothing, and speaks no protocol. It hands out
connection objects it was given by an adapter. Its security surface is therefore about
**isolation and availability**, not injection.

### What the pool guarantees

**A borrowed connection belongs to exactly one borrower.** Until it is released, no other
coroutine can receive it. This is the guarantee everything else rests on: two units of work
sharing one socket means interleaved traffic and one request reading another's response.

**A returned connection is validated before reuse.** A connection idle longer than
`aliveBypassWindow` is probed on the next borrow and retired if it fails, so a dead socket
is not handed to the next caller.

**The ceiling is absolute.** `maximumPoolSize` is never exceeded. Under pressure borrowers
queue and, past `connectionTimeout`, receive `PoolException::exhausted()`. This is
deliberate: converting a burst into a queue protects the database, whereas opening
unbounded connections turns a spike in one application into an outage for everything else
using that server.

### What the caller is responsible for

**Releasing what it borrows.** The pool cannot detect a borrower that never returns its
entry; each leak permanently shrinks the pool by one until it stops serving anyone. Use
`finally`, or a facade that releases automatically at the end of a unit of work.

**Not retaining the resource after release.** Once released, the connection belongs to the
pool and may be handed to another borrower immediately. Keeping a reference — in a
singleton, a static, a closure — reintroduces exactly the sharing the pool exists to
prevent, and the resulting cross-request data exposure is silent.

**Leaving connections in a clean state.** The pool returns the object as it was given back.
An open transaction, a changed session variable or a Redis `SELECT` to another database
travels with it to the next borrower. Reset such state before releasing, or `evict()` the
connection instead of releasing it.

**Fork safety.** `fork()` duplicates file descriptors; a child that closes an inherited
socket tears down the connection its parent is still using. After forking, a child must
call `abandon()` (drop without closing), never `close()`.

### Not a trust boundary

The pool does not authenticate, authorise or encrypt anything — all of that belongs to the
adapter and the driver it wraps. Credentials live in the adapter, and this package never
logs, serialises or otherwise reproduces them.
