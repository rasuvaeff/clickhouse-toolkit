<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\FilterInterface;

/**
 * Streams a large result set with bounded memory using keyset (seek) pagination.
 *
 * Each page is a normal, buffered query — `WHERE <key> > <last-seen> ORDER BY
 * <key> LIMIT <pageSize>` — so the whole result is never materialized at once
 * and, unlike `LIMIT/OFFSET`, deep pages stay cheap (a primary-index range scan
 * instead of skipping rows). Filtering and any mandatory (tenant/ACL) filter of
 * the {@see ClickHouseQueryBuilder} are preserved on every page.
 *
 * The key columns MUST form a unique, ascending total order. For a non-unique
 * sort column, add a unique tie-breaker (e.g. `['created_at' => 'DateTime',
 * 'id' => 'UInt64']`) — otherwise rows sharing a boundary key can be skipped.
 * Key columns must be non-nullable and are added to the projection automatically
 * so the cursor can advance.
 *
 * @template TValue of array|object
 *
 * @api
 */
final readonly class ClickHouseKeysetReader
{
    /** @var list<string> */
    private array $selectColumns;

    /**
     * @param \Closure(array<string, mixed>): TValue $mapper Maps a raw row to a value.
     * @param array<string, string> $keyColumns Ordered, non-empty map of key column => ClickHouse
     *     parameter type, e.g. `['id' => 'UInt64']` or `['created_at' => 'DateTime',
     *     'id' => 'UInt64']`. Must be a unique ascending total order.
     * @param list<string> $columns Column projection; empty selects all columns. The key
     *     columns are always included regardless of this list.
     * @param int $pageSize Rows fetched per query (>= 1).
     * @param FilterInterface|null $filter Base filter, AND-combined with the keyset boundary
     *     and subject to the builder's allow-list (mandatory filters always apply on top).
     *
     * @throws \InvalidArgumentException on empty key columns, page size < 1, or a malformed
     *     table/key identifier or key type token.
     */
    public function __construct(
        private ClickHouseClient $client,
        private string $table,
        private ClickHouseQueryBuilder $queryBuilder,
        private \Closure $mapper,
        private array $keyColumns,
        array $columns = [],
        private int $pageSize = 1000,
        private ?FilterInterface $filter = null,
    ) {
        if ($this->keyColumns === []) {
            throw new \InvalidArgumentException('Key columns must not be empty.');
        }
        if ($this->pageSize < 1) {
            throw new \InvalidArgumentException('Page size must be at least 1.');
        }

        Identifier::assert($this->table);
        foreach ($this->keyColumns as $column => $type) {
            Identifier::assert($column);
            Identifier::assertType($type);
        }
        foreach ($columns as $column) {
            Identifier::assert($column);
        }

        $this->selectColumns = $columns === []
            ? []
            : array_merge($columns, array_diff(array_keys($this->keyColumns), $columns));
    }

    /**
     * Yields every matching row, one page at a time, in ascending key order.
     *
     * @return \Generator<int, TValue>
     */
    public function stream(): \Generator
    {
        $orderBy = implode(', ', array_map(
            static fn(string $column): string => $column . ' ASC',
            array_keys($this->keyColumns),
        ));

        $last = null;

        while (true) {
            $count = 0;

            foreach ($this->fetchPage($orderBy, $last) as $row) {
                $last = $row;
                ++$count;

                yield ($this->mapper)($row);
            }

            if ($count < $this->pageSize) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed>|null $last
     * @return list<array<string, mixed>>
     */
    private function fetchPage(string $orderBy, ?array $last): array
    {
        $where = $this->queryBuilder->buildWhere($this->pageFilter($last));

        $sql = $this->queryBuilder->buildSelect(
            table: $this->table,
            columns: $this->selectColumns,
            where: $where->sql,
            orderBy: $orderBy,
            limit: $this->pageSize,
            offset: 0,
        );

        $output = $where->isEmpty()
            ? $this->client->select($sql, new JsonEachRow())
            : $this->client->selectWithParams($sql, $where->params, new JsonEachRow());

        /** @var list<array<string, mixed>> $rows */
        $rows = $output->data;

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $last
     */
    private function pageFilter(?array $last): ?FilterInterface
    {
        if ($last === null) {
            return $this->filter;
        }

        $columns = array_keys($this->keyColumns);
        $placeholders = [];
        $params = [];
        $index = 0;

        foreach ($this->keyColumns as $column => $type) {
            $key = 'ck' . $index++;
            $placeholders[] = sprintf('{%s:%s}', $key, $type);
            /** @var scalar $value Key columns are scalar orderable types (contract). */
            $value = $last[$column];
            $params[$key] = $value;
        }

        $left = count($columns) === 1 ? $columns[0] : '(' . implode(', ', $columns) . ')';
        $right = count($placeholders) === 1 ? $placeholders[0] : '(' . implode(', ', $placeholders) . ')';

        $boundary = new ClickHouseRawFilter($left . ' > ' . $right, $params);

        return $this->filter instanceof FilterInterface
            ? new AndX($this->filter, $boundary)
            : $boundary;
    }
}
