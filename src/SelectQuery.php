<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

/**
 * The statement builder around the WHERE the toolkit has always had: columns,
 * {@see Aggregate} and {@see Scalar} projections, FINAL, GROUP BY, ORDER BY
 * and a bound-parameter LIMIT — assembled without string concatenation at the
 * call site, which is where identifier validation used to stop applying (#45).
 *
 * Security, same rules as {@see ClickHouseQueryBuilder}: every identifier —
 * table, column, alias, GROUP BY field — goes through {@see Identifier::assert()},
 * projections come from closed sets ({@see Aggregate}, {@see Scalar}) that
 * cannot carry a raw expression, and every value is a bound `{name:Type}`
 * parameter. `where()` runs the same allow-list + mandatory-filter machinery
 * as {@see ClickHouseQueryBuilder::buildWhere()}: a mandatory tenant/ACL
 * filter set on the builder applies to every statement built here too.
 *
 * LIMIT and OFFSET are emitted as `{limit:UInt32}` / `{offset:UInt32}`
 * placeholders with deterministic names, so the statement text stays constant
 * and ClickHouse's query log groups repeated reads under one
 * `normalized_query_hash`. A filter parameter that has already taken the name
 * is renamed by {@see PlaceholderRemap} — the only case where the text moves.
 *
 * ORDER BY accepts fields the builder's allow-list admits plus everything
 * this query itself projects or groups by (aggregate aliases included), since
 * `GROUP BY status ORDER BY status` must not silently lose its ordering when
 * `status` never needed to be a filterable field. Criteria naming anything
 * else are dropped, exactly as in {@see ClickHouseQueryBuilder::buildOrderBy()}.
 *
 * JOIN and subqueries are out of scope by design: there is no shape for them
 * that cannot smuggle a raw expression, so they stay in a hand-written
 * constant skeleton with every value bound — trusted code only.
 *
 * @api
 */
final class SelectQuery
{
    private bool $final = false;

    /** @var list<string> */
    private array $columns = [];

    /** @var list<Aggregate> */
    private array $aggregates = [];

    /** @var list<Scalar> */
    private array $scalars = [];

    /** @var list<string> */
    private array $groups = [];

    private ?FilterInterface $filter = null;

    private ?Sort $sort = null;

    private ?int $limit = null;

    private int $offset = 0;

    /**
     * @internal Created by {@see ClickHouseQueryBuilder::select()}.
     */
    public function __construct(
        private readonly ClickHouseQueryBuilder $builder,
        private readonly string $table,
    ) {
        Identifier::assert($table);
    }

    /**
     * Appends `FINAL` after the table — the normal read for
     * ReplacingMergeTree/CollapsingMergeTree tables, where at-least-once
     * delivery leaves duplicates that only a merge would collapse.
     */
    public function final(): self
    {
        $this->final = true;

        return $this;
    }

    /**
     * @param string ...$columns Plain column identifiers, validated.
     */
    public function columns(string ...$columns): self
    {
        foreach ($columns as $column) {
            Identifier::assert($column);
            $this->columns[] = $column;
        }

        return $this;
    }

    public function aggregate(Aggregate $aggregate): self
    {
        $this->aggregates[] = $aggregate;

        return $this;
    }

    public function scalar(Scalar $scalar): self
    {
        $this->scalars[] = $scalar;

        return $this;
    }

    /**
     * The user filter: allow-listed like any WHERE this builder produces. The
     * mandatory filter set on the {@see ClickHouseQueryBuilder} always applies
     * on top of it.
     */
    public function where(?FilterInterface $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    /**
     * @param string ...$columns Plain column identifiers, validated.
     */
    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            Identifier::assert($column);
            $this->groups[] = $column;
        }

        return $this;
    }

    /**
     * Sort criteria are filtered the way {@see ClickHouseQueryBuilder::buildOrderBy()}
     * filters them — fields outside the allow-list are dropped — except that
     * everything this query projects or groups by is orderable too. Null
     * (the default) emits no ORDER BY at all.
     */
    public function orderBy(?Sort $sort): self
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * @param int|null $limit Row limit (>= 0); null omits LIMIT/OFFSET.
     */
    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(sprintf('Limit must not be negative, got %d.', $limit));
        }

        $this->limit = $limit;

        return $this;
    }

    /**
     * @param int $offset Row offset (>= 0); only meaningful together with a limit.
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('Offset must not be negative, got %d.', $offset));
        }

        $this->offset = $offset;

        return $this;
    }

    /**
     * Assembles the statement. Parameter names are kept unique by
     * {@see PlaceholderRemap} across the projection conditions, the WHERE
     * fragment, and the LIMIT/OFFSET placeholders — in that order.
     */
    public function build(): ParameterizedQuery
    {
        if ($this->limit === null && $this->offset > 0) {
            throw new \InvalidArgumentException('Offset requires a limit; set one or drop the offset.');
        }

        $params = [];
        $index = 0;

        $items = $this->columns;
        foreach ($this->scalars as $scalar) {
            $items[] = $scalar->toSql();
        }
        foreach ($this->aggregates as $aggregate) {
            $items[] = $this->aggregateSql($aggregate, $params, $index);
        }

        $sql = 'SELECT ' . ($items === [] ? '*' : implode(', ', $items)) . ' FROM ' . $this->table;
        if ($this->final) {
            $sql .= ' FINAL';
        }

        $where = $this->builder->buildWhere($this->filter);
        if (!$where->isEmpty()) {
            [$fragment, $params] = PlaceholderRemap::merge($params, ' WHERE ' . $where->sql, $where->params);
            $sql .= $fragment;
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->sort !== null) {
            $orderBy = $this->builder->buildOrderBy($this->sort, $this->orderableFields());
            if ($orderBy !== '') {
                $sql .= ' ORDER BY ' . $orderBy;
            }
        }

        if ($this->limit !== null) {
            [$fragment, $params] = PlaceholderRemap::merge($params, ' LIMIT {limit:UInt32}', ['limit' => $this->limit]);
            $sql .= $fragment;

            if ($this->offset > 0) {
                [$fragment, $params] = PlaceholderRemap::merge($params, ' OFFSET {offset:UInt32}', ['offset' => $this->offset]);
                $sql .= $fragment;
            }
        }

        return new ParameterizedQuery(sql: $sql, params: $params);
    }

    /**
     * Everything this query makes orderable by name: projected columns,
     * GROUP BY fields and the aliases of aggregates and scalars.
     *
     * @return list<string>
     */
    private function orderableFields(): array
    {
        $fields = $this->groups;
        foreach ($this->columns as $column) {
            $fields[] = $column;
        }
        foreach ($this->aggregates as $aggregate) {
            if ($aggregate->alias !== null) {
                $fields[] = $aggregate->alias;
            }
        }
        foreach ($this->scalars as $scalar) {
            if ($scalar->alias !== null) {
                $fields[] = $scalar->alias;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * A conditional aggregate renders its condition through the same visitor
     * WHERE uses, so allow-listing, type mapping and parameter binding all
     * apply; the fragment is then wrapped in the aggregate's function.
     *
     * @param array<string, mixed> $params
     */
    private function aggregateSql(Aggregate $aggregate, array &$params, int &$index): string
    {
        if ($aggregate->condition === null) {
            return $aggregate->toSql();
        }

        $condition = $this->builder->renderFilter($aggregate->condition, $index);
        if ($condition->isEmpty()) {
            throw new \InvalidArgumentException(sprintf(
                'The condition of the "%s" aggregate produced no SQL: every filter in it was dropped by the allow-list. '
                . 'Add the field to allowedFields or use a mandatory filter.',
                $aggregate->function,
            ));
        }

        // countIf takes the condition alone; sumIf/uniqExactIf take the
        // column it aggregates and the condition, in that order.
        $wrapped = $aggregate->column === null
            ? $aggregate->function . '(' . $condition->sql . ')'
            : $aggregate->function . '(' . $aggregate->column . ', ' . $condition->sql . ')';

        [$inner, $params] = PlaceholderRemap::merge($params, $wrapped, $condition->params);

        return $aggregate->alias === null ? $inner : $inner . ' AS ' . $aggregate->alias;
    }
}
