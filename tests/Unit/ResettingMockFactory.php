<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ResettableConnectionFactory;

/**
 * A {@see MockFactory} twin that also resets connections on return: it counts
 * `reset()` calls and lets a test decide the verdict — `clean` (what reset returns)
 * and `resetThrows` (make reset blow up) — so every path of the return-time reset is
 * deterministic without a live database.
 */
final class ResettingMockFactory implements ResettableConnectionFactory
{
    public int $created = 0;
    public int $closed = 0;
    public int $reset = 0;
    public bool $clean = true;
    public bool $resetThrows = false;

    /**
     * Called from inside `reset()`, so a test can act at the moment a real reset would
     * be waiting on the server — a rollback or a DISCARD is a round trip.
     *
     * @var (callable(): void)|null
     */
    public $whileResetting = null;

    public function create(): object
    {
        ++$this->created;
        return (object) ['id' => $this->created];
    }

    public function validate(object $connection): bool
    {
        return true;
    }

    public function close(object $connection): void
    {
        ++$this->closed;
    }

    public function reset(object $connection): bool
    {
        ++$this->reset;
        if ($this->whileResetting !== null) {
            ($this->whileResetting)();
        }
        if ($this->resetThrows) {
            throw new \RuntimeException('reset failed');
        }
        return $this->clean;
    }
}
