<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Types;

use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

/**
 * Compile-time regression for the generic contract of {@see ClickHouseDataReader}.
 *
 * Nothing here runs: psalm reads it. Every `withX()` returns a new reader, and
 * each must carry `TValue` over — without it psalm widens the reader back to its
 * `array|object` template bound and every `read()`/`readOne()` behind a `withX()`
 * loses its type in consuming applications.
 *
 * @internal
 */
final class DataReaderTypes
{
    /**
     * @param ClickHouseDataReader<TypedRow> $reader
     *
     * @return list<TypedRow>
     */
    public static function readAfterEveryWith(ClickHouseDataReader $reader): array
    {
        return $reader
            ->withFilter(new Equals('id', 1))
            ->withSort(Sort::only(['id']))
            ->withLimit(10)
            ->withOffset(20)
            ->read();
    }

    /**
     * @param ClickHouseDataReader<TypedRow> $reader
     */
    public static function readOneAfterWith(ClickHouseDataReader $reader): ?TypedRow
    {
        return $reader->withFilter(new Equals('id', 1))->readOne();
    }

    /**
     * @param ClickHouseDataReader<TypedRow> $reader
     *
     * @return \Traversable<int, TypedRow>
     */
    public static function iterateAfterWith(ClickHouseDataReader $reader): \Traversable
    {
        return $reader->withLimit(10)->getIterator();
    }
}
