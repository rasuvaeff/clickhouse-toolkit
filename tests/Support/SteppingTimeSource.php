<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Support;

use Rasuvaeff\Context\TimeSource;

/**
 * Moves forward by a fixed step on every read (0 = a fixed clock), so a
 * deadline can pass between two consecutive checks inside one call.
 */
final class SteppingTimeSource implements TimeSource
{
    private int $nowNs = 1_000_000_000;

    public function __construct(private readonly int $stepNs) {}

    #[\Override]
    public function nowNs(): int
    {
        $now = $this->nowNs;
        $this->nowNs += $this->stepNs;

        return $now;
    }
}
