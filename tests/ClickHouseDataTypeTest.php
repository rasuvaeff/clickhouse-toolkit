<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(T::class)]
final class ClickHouseDataTypeTest
{
    public function constants(): void
    {
        Assert::same(T::UInt64, 'UInt64');
        Assert::same(T::String, 'String');
        Assert::same(T::DateTime, 'DateTime');
    }

    public function wrappers(): void
    {
        Assert::same(T::nullable(T::String), 'Nullable(String)');
        Assert::same(T::lowCardinality(T::String), 'LowCardinality(String)');
        Assert::same(T::array(T::UInt64), 'Array(UInt64)');
        Assert::same(T::array(T::nullable(T::String)), 'Array(Nullable(String))');
        Assert::same(T::map(T::String, T::UInt64), 'Map(String, UInt64)');
        Assert::same(T::tuple(T::UInt8, T::String), 'Tuple(UInt8, String)');
    }

    public function parametric(): void
    {
        Assert::same(T::decimal(10, 2), 'Decimal(10, 2)');
        Assert::same(T::fixedString(16), 'FixedString(16)');
        Assert::same(T::dateTime64(6), 'DateTime64(6)');
        Assert::same(T::dateTime64(3, 'UTC'), "DateTime64(3, 'UTC')");
        Assert::same(T::dateTime(), 'DateTime');
        Assert::same(T::dateTime('Europe/Moscow'), "DateTime('Europe/Moscow')");
    }

    public function enums(): void
    {
        Assert::same(T::enum8(['a' => 1, 'b' => 2]), "Enum8('a' = 1, 'b' = 2)");
        Assert::same(T::enum16(['x' => 10]), "Enum16('x' = 10)");
        Assert::same(T::enum8(["o'r" => 1]), "Enum8('o\\'r' = 1)");
    }
}
