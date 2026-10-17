<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\FilterInterface;

/**
 * A closed set of aggregate projections for {@see SelectQuery}: `count()`,
 * `sum()`, `min()`, `max()`, `avg()`, `uniqExact()` and their conditional
 * `*If()` variants.
 *
 * There is no raw-expression factory on purpose: an aggregate whose text a
 * caller could spell out would be an injection point in the middle of the
 * SELECT list. Every column and alias is validated as a plain identifier at
 * construction; a conditional aggregate takes its condition as a
 * {@see FilterInterface}, rendered by the builder's filter visitor with every
 * value a bound parameter — the same machinery WHERE uses.
 *
 * @api
 */
final readonly class Aggregate
{
    private const string COUNT = 'count';
    private const string SUM = 'sum';
    private const string MIN = 'min';
    private const string MAX = 'max';
    private const string AVG = 'avg';
    private const string UNIQ_EXACT = 'uniqExact';
    private const string COUNT_IF = 'countIf';
    private const string SUM_IF = 'sumIf';
    private const string UNIQ_EXACT_IF = 'uniqExactIf';

    /**
     * @internal Rendered by {@see SelectQuery}; the closed set is enforced by
     *           the private constructor, not by trusting the caller.
     *
     * @param string $function One of the constants above.
     * @param string|null $column Validated identifier; null for `count()`.
     * @param string|null $alias Validated identifier for `AS`; null omits it.
     * @param FilterInterface|null $condition Only for the `*If` variants.
     */
    private function __construct(
        public string $function,
        public ?string $column,
        public ?string $alias,
        public ?FilterInterface $condition,
    ) {
        if ($column !== null) {
            Identifier::assert($column);
        }
        if ($alias !== null) {
            Identifier::assert($alias);
        }
    }

    public static function count(?string $alias = null): self
    {
        return new self(self::COUNT, null, $alias, null);
    }

    public static function sum(string $column, ?string $alias = null): self
    {
        return new self(self::SUM, $column, $alias, null);
    }

    public static function min(string $column, ?string $alias = null): self
    {
        return new self(self::MIN, $column, $alias, null);
    }

    public static function max(string $column, ?string $alias = null): self
    {
        return new self(self::MAX, $column, $alias, null);
    }

    public static function avg(string $column, ?string $alias = null): self
    {
        return new self(self::AVG, $column, $alias, null);
    }

    public static function uniqExact(string $column, ?string $alias = null): self
    {
        return new self(self::UNIQ_EXACT, $column, $alias, null);
    }

    /**
     * `countIf(condition)`: rows where the condition holds. The condition is
     * a filter over the same allow-list and type map WHERE uses, and every
     * value in it is a bound parameter.
     */
    public static function countIf(FilterInterface $condition, ?string $alias = null): self
    {
        return new self(self::COUNT_IF, null, $alias, $condition);
    }

    public static function sumIf(string $column, FilterInterface $condition, ?string $alias = null): self
    {
        return new self(self::SUM_IF, $column, $alias, $condition);
    }

    public static function uniqExactIf(string $column, FilterInterface $condition, ?string $alias = null): self
    {
        return new self(self::UNIQ_EXACT_IF, $column, $alias, $condition);
    }

    /**
     * The projection SQL, for the variants that take no condition — no
     * parameters, so nothing here needs the builder's placeholder machinery.
     *
     * @internal
     */
    public function toSql(): string
    {
        if ($this->condition !== null) {
            throw new \LogicException('A conditional aggregate is rendered by SelectQuery, which owns the parameter namespace.');
        }

        $sql = $this->column === null
            ? $this->function . '()'
            : $this->function . '(' . $this->column . ')';

        return $this->alias === null ? $sql : $sql . ' AS ' . $this->alias;
    }
}
