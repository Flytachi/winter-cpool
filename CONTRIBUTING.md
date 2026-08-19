# Contributing to Winter CPool

Thanks for considering a contribution! This guide covers the local workflow and the
checks a pull request must pass.

## Requirements

- PHP >= 8.4
- [Composer](https://getcomposer.org/)
- **`ext-swoole`** — optional for *using* the package, required for *developing* it:
  the pool's behaviour under concurrency is covered by tests that run real
  coroutines, and those are skipped without it.

## Getting started

```bash
git clone https://github.com/flytachi/winter-cpool.git
cd winter-cpool
composer install
```

## Running the checks

```bash
composer test         # run the PHPUnit suite
composer test-detail  # run with testdox (human-readable test names)
composer cs-check     # PSR-12 via phpcs
composer cs-fix       # auto-fix what phpcbf can
```

All four must be clean before a pull request is merged.

### Always run the suite with Xdebug off

```bash
XDEBUG_MODE=off composer test
```

Xdebug's function observers do not survive coroutine stacks: once a child coroutine has
suspended and resumed, the interpreter segfaults at request shutdown — **after** the tests
have passed. The report says `OK`, the exit code says `139`, and the run looks broken when
it is not. Every `xdebug.mode` does this, `coverage` included.

## Coding standard

PSR-12, enforced by `phpcs` with the ruleset in `phpcs.xml`. Run `composer cs-fix` before
pushing; anything it cannot fix, fix by hand.

Comments explain **why**, not what. The pool is full of decisions that look arbitrary until
you know the reason — the alive-bypass window, the lifetime jitter, `abandon()` versus
`close()`. When you change one of those, the comment explaining it is part of the change.

## Tests

Behaviour claims about the pool belong in a test, not in a description. This is the
component whose failures are hardest to reproduce by hand: a leaked connection, a race
between two borrowers or a socket that dies while idle will not show up in casual use.

When fixing a bug, add the test that fails without the fix — and check that it does fail
before you apply it. A regression test that passes on the broken code is worse than none,
because it is trusted.

Concurrency is exercised with real coroutines (`Swoole\Coroutine\run`) and a mock factory
(`tests/Unit/MockFactory.php`) rather than mocks of the pool itself. Keep it that way: the
interesting failures live in the interleaving, and a mocked channel cannot produce them.

## Pull requests

- One change per pull request; keep unrelated refactoring out of it.
- Explain the reasoning in the description, not just the diff.
- Note any behaviour change explicitly, even a small one — this package sits under other
  packages, and a surprise here surfaces far away from its cause.

## Reporting bugs & security issues

Ordinary bugs: open a GitHub issue with a minimal reproduction and the runtime you saw it
on (Swoole version, PHP version, whether a coroutine was active).

Security issues: **do not** open a public issue — see [SECURITY.md](SECURITY.md).
