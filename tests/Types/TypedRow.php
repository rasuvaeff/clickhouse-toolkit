<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Types;

/**
 * Row type used by {@see DataReaderTypes} to pin `TValue` to something narrower
 * than the `array|object` template bound.
 *
 * @internal
 */
final readonly class TypedRow
{
    public function __construct(public int $id) {}
}
