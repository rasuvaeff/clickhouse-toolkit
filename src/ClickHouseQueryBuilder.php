<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

/**
 * Translates {@see \Yiisoft\Data\Reader} filters and sort into parameterized
 * ClickHouse SQL.
 *
 * Security: only fields present in {@see $allowedFields} are emitted in WHERE
 * and ORDER BY; filters or sort criteria referencing other fields are dropped.
 * Comparison values are passed as ClickHouse bound parameters with unique keys,
 * so the same field may appear multiple times without parameter collisions.
 *
 * WARNING: disallowed and unsupported filters are silently dropped (they widen
 * the result set, not narrow it). This is intended for *user-supplied* filters
 * where dropping an unknown field is the safe default. Do NOT use it to enforce
 * mandatory access constraints (tenant/owner/ACL): a typo or an unsupported
 * filter type would silently expose more rows. Apply such constraints as a
 * separate, hard-coded WHERE that is always present.
 *
 * @api
 */
final readonly class ClickHouseQueryBuilder
{
    private ClickHouseFilterVisitor $visitor;

    /**
     * @param list<string> $allowedFields Fields allowed in WHERE / ORDER BY.
     * @param array<string, string> $fieldTypes Field => ClickHouse parameter type (default "String"),
     *     e.g. "UInt64", "DateTime", "Array(UInt64)". Validated; not user input.
     * @param string $defaultSort A trusted raw ORDER BY fragment (e.g. "id DESC") used when no
     *     sort criteria are given. Not validated — never build it from untrusted input.
     * @param FilterInterface|null $mandatoryFilter Always-applied filter (e.g. tenant/ACL),
     *     AND-combined with the user filter and NOT subject to the allow-list. Trusted.
     * @param string|null $serverTimezone IANA timezone name (e.g. "UTC", "Europe/Moscow").
     *     When set, DateTimeInterface filter values are converted to this timezone before
     *     formatting as `Y-m-d H:i:s`. Null = use each DateTime object's own timezone.
     * @param ClickHouseFilterVisitor|null $customVisitor Custom visitor for SQL generation.
     *     When null, a {@see ClickHouseSqlFilterVisitor} is created automatically using
     *     $allowedFields, $fieldTypes and $serverTimezone.
     *
     * @throws \InvalidArgumentException on a malformed field identifier or type token.
     */
    public function __construct(
        private array $allowedFields,
        private array $fieldTypes = [],
        private string $defaultSort = 'id DESC',
        private ?FilterInterface $mandatoryFilter = null,
        private ?string $serverTimezone = null,
        private ?ClickHouseFilterVisitor $customVisitor = null,
    ) {
        foreach ($this->allowedFields as $field) {
            Identifier::assert($field);
        }
        foreach ($this->fieldTypes as $type) {
            Identifier::assertType($type);
        }
        if ($this->serverTimezone === '') {
            throw new \InvalidArgumentException('serverTimezone must not be empty.');
        }

        $this->visitor = $this->customVisitor ?? new ClickHouseSqlFilterVisitor(
            $this->allowedFields,
            $this->fieldTypes,
            $this->serverTimezone !== null ? new \DateTimeZone($this->serverTimezone) : null,
        );
    }

    /**
     * @param list<string> $allowedFields
     * @param array<string, string> $fieldTypes
     */
    public static function create(array $allowedFields, array $fieldTypes = [], string $defaultSort = 'id DESC'): self
    {
        return new self($allowedFields, $fieldTypes, $defaultSort);
    }

    /**
     * Returns a copy with an additional always-applied filter (AND-combined).
     * Mandatory filters bypass the allow-list (their fields need not be in
     * {@see $allowedFields}); their identifiers are still validated. Use for
     * tenant/owner/soft-delete constraints the builder must always enforce.
     */
    public function withMandatoryFilter(FilterInterface $filter): self
    {
        $combined = $this->mandatoryFilter === null
            ? $filter
            : new AndX($this->mandatoryFilter, $filter);

        return new self($this->allowedFields, $this->fieldTypes, $this->defaultSort, $combined, $this->serverTimezone, $this->customVisitor);
    }

    public function withDefaultSort(string $defaultSort): self
    {
        return new self($this->allowedFields, $this->fieldTypes, $defaultSort, $this->mandatoryFilter, $this->serverTimezone, $this->customVisitor);
    }

    /**
     * Returns a copy with a different server timezone for DateTime normalization.
     * Null = use each DateTime object's own timezone (no conversion).
     */
    public function withServerTimezone(?string $timezone): self
    {
        return new self($this->allowedFields, $this->fieldTypes, $this->defaultSort, $this->mandatoryFilter, $timezone, $this->customVisitor);
    }

    /**
     * Returns a copy with a custom filter visitor for SQL generation.
     * When null, the default {@see ClickHouseSqlFilterVisitor} is used.
     */
    public function withVisitor(?ClickHouseFilterVisitor $visitor): self
    {
        return new self($this->allowedFields, $this->fieldTypes, $this->defaultSort, $this->mandatoryFilter, $this->serverTimezone, $visitor);
    }

    public function buildWhere(?FilterInterface $filter = null): WhereClause
    {
        $index = 0;
        $parts = [];
        $params = [];

        if ($this->mandatoryFilter !== null) {
            [$sql, $sub] = $this->visitor->dispatch($this->mandatoryFilter, $index, true);
            if ($sql !== '') {
                $parts[] = $sql;
                $params = array_merge($params, $sub);
            }
        }

        if ($filter !== null) {
            [$sql, $sub] = $this->visitor->dispatch($filter, $index, false);
            if ($sql !== '') {
                $parts[] = $sql;
                $params = array_merge($params, $sub);
            }
        }

        if ($parts === []) {
            return new WhereClause(sql: '', params: []);
        }

        $sql = count($parts) === 1 ? $parts[0] : '(' . implode(separator: ') AND (', array: $parts) . ')';

        return new WhereClause(sql: $sql, params: $params);
    }

    public function buildOrderBy(?Sort $sort): string
    {
        if (!$sort instanceof Sort) {
            return $this->defaultSort;
        }

        $parts = [];
        foreach ($sort->getCriteria() as $field => $direction) {
            if (!$this->isAllowed($field)) {
                continue;
            }
            $parts[] = $field . ' ' . ($direction === SORT_ASC ? 'ASC' : 'DESC');
        }

        if ($parts === []) {
            return $this->defaultSort;
        }

        return implode(separator: ', ', array: $parts);
    }

    /**
     * `$table` and `$columns` must be plain SQL identifiers (validated); they are
     * not escaped, so never build them from untrusted input via raw expressions.
     * `$orderBy` is expected to come from {@see buildOrderBy()} (or be trusted).
     *
     * @param list<string> $columns Explicit column projection; empty selects all columns.
     * @param int|null $limit Row limit (>= 0); null omits LIMIT/OFFSET entirely.
     * @param int $offset Row offset (>= 0).
     *
     * @throws \InvalidArgumentException on a malformed identifier or negative limit/offset.
     */
    public function buildSelect(
        string $table,
        array $columns = [],
        string $where = '',
        ?string $orderBy = null,
        ?int $limit = 20,
        int $offset = 0,
    ): string {
        Identifier::assert($table);
        foreach ($columns as $column) {
            Identifier::assert($column);
        }
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(sprintf('Limit must not be negative, got %d.', $limit));
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('Offset must not be negative, got %d.', $offset));
        }

        $sql = sprintf(
            'SELECT %s FROM %s%s ORDER BY %s',
            $columns === [] ? '*' : implode(', ', $columns),
            $table,
            $where !== '' ? ' WHERE ' . $where : '',
            $orderBy ?? $this->defaultSort,
        );

        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        return $sql;
    }

    /**
     * @throws \InvalidArgumentException on a malformed table identifier.
     */
    public function buildCount(string $table, string $where = ''): string
    {
        Identifier::assert($table);

        return sprintf(
            'SELECT count() AS cnt FROM %s%s',
            $table,
            $where !== '' ? ' WHERE ' . $where : '',
        );
    }

    /**
     * @throws \InvalidArgumentException on a malformed table or column identifier.
     */
    public function buildDistinct(string $table, string $column): string
    {
        Identifier::assert($table);
        Identifier::assert($column);

        return sprintf(
            'SELECT DISTINCT %1$s FROM %2$s ORDER BY %1$s',
            $column,
            $table,
        );
    }

    private function isAllowed(string $field): bool
    {
        return in_array(needle: $field, haystack: $this->allowedFields, strict: true);
    }
}
