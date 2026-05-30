<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Sql\Escaper;

/**
 * ClickHouse type names as constants, plus factories for parametric/nested
 * types. Types are plain strings, so these just make type definitions
 * self-documenting and typo-proof — handy for {@see ClickHouseTableBuilder}
 * columns and {@see ClickHouseQueryBuilder} field types.
 *
 * Note: composite types like Enum/DateTime-with-timezone are for column
 * definitions (TableBuilder); they are not valid as query-parameter types.
 *
 * @api
 */
final class ClickHouseDataType
{
    public const string UInt8 = 'UInt8';
    public const string UInt16 = 'UInt16';
    public const string UInt32 = 'UInt32';
    public const string UInt64 = 'UInt64';
    public const string UInt128 = 'UInt128';
    public const string UInt256 = 'UInt256';
    public const string Int8 = 'Int8';
    public const string Int16 = 'Int16';
    public const string Int32 = 'Int32';
    public const string Int64 = 'Int64';
    public const string Int128 = 'Int128';
    public const string Int256 = 'Int256';
    public const string Float32 = 'Float32';
    public const string Float64 = 'Float64';
    public const string String = 'String';
    public const string Bool = 'Bool';
    public const string UUID = 'UUID';
    public const string Date = 'Date';
    public const string Date32 = 'Date32';
    public const string DateTime = 'DateTime';
    public const string IPv4 = 'IPv4';
    public const string IPv6 = 'IPv6';

    public static function nullable(string $type): string
    {
        return sprintf('Nullable(%s)', $type);
    }

    public static function lowCardinality(string $type): string
    {
        return sprintf('LowCardinality(%s)', $type);
    }

    public static function array(string $type): string
    {
        return sprintf('Array(%s)', $type);
    }

    public static function map(string $keyType, string $valueType): string
    {
        return sprintf('Map(%s, %s)', $keyType, $valueType);
    }

    public static function tuple(string ...$types): string
    {
        return sprintf('Tuple(%s)', implode(', ', $types));
    }

    public static function decimal(int $precision, int $scale): string
    {
        return sprintf('Decimal(%d, %d)', $precision, $scale);
    }

    public static function fixedString(int $length): string
    {
        return sprintf('FixedString(%d)', $length);
    }

    public static function dateTime(?string $timezone = null): string
    {
        return $timezone === null
            ? self::DateTime
            : sprintf("DateTime('%s')", Escaper::escape($timezone));
    }

    public static function dateTime64(int $precision, ?string $timezone = null): string
    {
        return $timezone === null
            ? sprintf('DateTime64(%d)', $precision)
            : sprintf("DateTime64(%d, '%s')", $precision, Escaper::escape($timezone));
    }

    /**
     * @param array<string, int> $values Label => numeric id.
     */
    public static function enum8(array $values): string
    {
        return self::enum('Enum8', $values);
    }

    /**
     * @param array<string, int> $values Label => numeric id.
     */
    public static function enum16(array $values): string
    {
        return self::enum('Enum16', $values);
    }

    /**
     * @param array<string, int> $values
     */
    private static function enum(string $kind, array $values): string
    {
        $parts = [];
        foreach ($values as $label => $id) {
            $parts[] = sprintf("'%s' = %d", Escaper::escape($label), $id);
        }

        return sprintf('%s(%s)', $kind, implode(', ', $parts));
    }
}
