<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;

/**
 * @internal
 */
final class FakeClickHouseFilterVisitor implements ClickHouseFilterVisitor
{
    public function __construct(
        private readonly array $returnValue = [],
    ) {}

    #[\Override]
    public function dispatch(string $field, mixed $value): array
    {
        return $this->returnValue;
    }
}
