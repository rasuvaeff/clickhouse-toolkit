<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\DataReaderInterface;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

/**
 * Immutable {@see DataReaderInterface} backed by a ClickHouse table. Filtering,
 * sorting and pagination are delegated to {@see ClickHouseQueryBuilder}; rows are
 * mapped to values of type TValue by the supplied mapper. Compatible with
 * yiisoft/data paginators out of the box.
 *
 * @template TValue of array|object
 *
 * @implements DataReaderInterface<int, TValue>
 *
 * @api
 */
final class ClickHouseDataReader implements DataReaderInterface
{
    private FilterInterface $filter;
    private ?Sort $sort = null;
    /** @var int<0, max>|null */
    private ?int $limit = null;
    /** @var int<0, max> */
    private int $offset = 0;

    /** @var \Closure(array<string, mixed>): TValue */
    private \Closure $mapper;

    /**
     * @param \Closure(array<string, mixed>): TValue $mapper Maps a raw row to a value.
     * @param list<string> $columns Column projection; empty selects all columns.
     */
    public function __construct(
        private readonly ClickHouseClient $client,
        private readonly string $table,
        private readonly ClickHouseQueryBuilder $queryBuilder,
        \Closure $mapper,
        private readonly array $columns = [],
    ) {
        $this->mapper = $mapper;
        $this->filter = new All();
    }

    #[\Override]
    public function withFilter(FilterInterface $filter): static
    {
        $new = clone $this;
        $new->filter = $filter;

        return $new;
    }

    #[\Override]
    public function getFilter(): FilterInterface
    {
        return $this->filter;
    }

    #[\Override]
    public function withSort(?Sort $sort): static
    {
        $new = clone $this;
        $new->sort = $sort;

        return $new;
    }

    #[\Override]
    public function getSort(): ?Sort
    {
        return $this->sort;
    }

    /**
     * @param int|null $limit
     */
    #[\Override]
    public function withLimit(?int $limit): static
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Limit must not be negative.');
        }

        $new = clone $this;
        $new->limit = $limit;

        return $new;
    }

    #[\Override]
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    #[\Override]
    public function withOffset(int $offset): static
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must not be negative.');
        }

        $new = clone $this;
        $new->offset = $offset;

        return $new;
    }

    #[\Override]
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @return list<TValue>
     */
    #[\Override]
    public function read(): array
    {
        return $this->fetch(limit: $this->limit, offset: $this->offset);
    }

    /**
     * @return TValue|null
     */
    #[\Override]
    public function readOne(): array|object|null
    {
        return $this->fetch(limit: 1, offset: $this->offset)[0] ?? null;
    }

    #[\Override]
    public function count(): int
    {
        $where = $this->queryBuilder->buildWhere($this->filter);
        $sql = $this->queryBuilder->buildCount(table: $this->table, where: $where->sql);

        $output = $where->isEmpty()
            ? $this->client->select($sql, new JsonEachRow())
            : $this->client->selectWithParams($sql, $where->params, new JsonEachRow());

        /** @var list<array<string, mixed>> $data */
        $data = $output->data;

        return max(0, (int) ($data[0]['cnt'] ?? 0));
    }

    /**
     * @return \Traversable<int, TValue>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        yield from $this->read();
    }

    /**
     * @param int<0, max>|null $limit
     * @param int<0, max> $offset
     * @return list<TValue>
     */
    private function fetch(?int $limit, int $offset): array
    {
        $where = $this->queryBuilder->buildWhere($this->filter);

        $sql = $this->queryBuilder->buildSelect(
            table: $this->table,
            columns: $this->columns,
            where: $where->sql,
            orderBy: $this->queryBuilder->buildOrderBy($this->sort),
            limit: $limit,
            offset: $offset,
        );

        $output = $where->isEmpty()
            ? $this->client->select($sql, new JsonEachRow())
            : $this->client->selectWithParams($sql, $where->params, new JsonEachRow());

        /** @var list<array<string, mixed>> $rows */
        $rows = $output->data;

        return array_map($this->mapper, $rows);
    }
}
