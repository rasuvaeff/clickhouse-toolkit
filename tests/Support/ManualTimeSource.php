<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Support;

use Rasuvaeff\Context\TimeSource;

/**
 * A clock that only moves when a test says so, so a deadline can pass in the
 * middle of a call to a wrapped client.
 */
final class ManualTimeSource implements TimeSource
{
    private int $nowNs = 1_000_000_000;

    public function advanceSeconds(int $seconds): void
    {
        $this->nowNs += $seconds * 1_000_000_000;
    }

    #[\Override]
    public function nowNs(): int
    {
        return $this->nowNs;
    }
}
