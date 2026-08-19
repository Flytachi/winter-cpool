<?php

declare(strict_types=1);

namespace Flytachi\Winter\CPool\Tests\Unit;

use Flytachi\Winter\CPool\ConnectionFactory;

/**
 * A scriptable {@see ConnectionFactory} for pool tests: it counts create/validate/close
 * and exposes toggles — `alive` (what a probe returns) and `failCreate` (make opening
 * throw) — so idle-gating, retirement and connect-failure paths are deterministic
 * without a live database.
 */
final class MockFactory implements ConnectionFactory
{
    public int $created = 0;
    public int $closed = 0;
    public int $validated = 0;
    public bool $alive = true;
    public bool $failCreate = false;

    public function create(): object
    {
        if ($this->failCreate) {
            throw new \RuntimeException('connect refused');
        }
        ++$this->created;
        return (object) ['id' => $this->created];
    }

    public function validate(object $connection): bool
    {
        ++$this->validated;
        return $this->alive;
    }

    public function close(object $connection): void
    {
        ++$this->closed;
    }
}
