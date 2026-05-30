<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;

#[CoversClass(T::class)]
final class ClickHouseDataTypeTest extends TestCase
{
    #[Test]
    public function constants(): void
    {
        $this->assertSame('UInt64', T::UInt64);
        $this->assertSame('String', T::String);
        $this->assertSame('DateTime', T::DateTime);
    }

    #[Test]
    public function wrappers(): void
    {
        $this->assertSame('Nullable(String)', T::nullable(T::String));
        $this->assertSame('LowCardinality(String)', T::lowCardinality(T::String));
        $this->assertSame('Array(UInt64)', T::array(T::UInt64));
        $this->assertSame('Array(Nullable(String))', T::array(T::nullable(T::String)));
        $this->assertSame('Map(String, UInt64)', T::map(T::String, T::UInt64));
        $this->assertSame('Tuple(UInt8, String)', T::tuple(T::UInt8, T::String));
    }

    #[Test]
    public function parametric(): void
    {
        $this->assertSame('Decimal(10, 2)', T::decimal(10, 2));
        $this->assertSame('FixedString(16)', T::fixedString(16));
        $this->assertSame('DateTime64(6)', T::dateTime64(6));
        $this->assertSame("DateTime64(3, 'UTC')", T::dateTime64(3, 'UTC'));
        $this->assertSame('DateTime', T::dateTime());
        $this->assertSame("DateTime('Europe/Moscow')", T::dateTime('Europe/Moscow'));
    }

    #[Test]
    public function enums(): void
    {
        $this->assertSame("Enum8('a' = 1, 'b' = 2)", T::enum8(['a' => 1, 'b' => 2]));
        $this->assertSame("Enum16('x' = 10)", T::enum16(['x' => 10]));
        $this->assertSame("Enum8('o\\'r' = 1)", T::enum8(["o'r" => 1]), 'enum labels are escaped');
    }
}
