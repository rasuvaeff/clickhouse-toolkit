<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use SimPod\ClickHouseClient\Output\Output;

/**
 * @internal
 * @implements Output<never>
 */
final class FakeOutput implements Output
{
    public function __construct(string $contents = '') {}

    public function getIterator(): \Traversable
    {
        return new \EmptyIterator();
    }

    public function count(): int
    {
        return 0;
    }
}
