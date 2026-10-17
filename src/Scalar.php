<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * A closed set of scalar function projections for {@see SelectQuery}. Today
 * that is {@see self::toString()} — the shape a `DateTime` column takes when
 * a read wants it as a plain string in the result row.
 *
 * Like {@see Aggregate}, there is no raw-expression factory: the column and
 * the alias are validated identifiers, so a scalar projection cannot become
 * an injection point.
 *
 * @api
 */
final readonly class Scalar
{
    private const string TO_STRING = 'toString';

    /**
     * @internal Rendered by {@see SelectQuery}; the closed set is enforced by
     *           the private constructor.
     */
    private function __construct(
        public string $function,
        public string $column,
        public ?string $alias,
    ) {
        Identifier::assert($column);
        if ($alias !== null) {
            Identifier::assert($alias);
        }
    }

    public static function toString(string $column, ?string $alias = null): self
    {
        return new self(self::TO_STRING, $column, $alias);
    }

    /**
     * @internal
     */
    public function toSql(): string
    {
        $sql = $this->function . '(' . $this->column . ')';

        return $this->alias === null ? $sql : $sql . ' AS ' . $this->alias;
    }
}
