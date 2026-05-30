<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Examples;

use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseReaderInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

/**
 * Paginated, filterable reader for the `events` table. Maps raw rows into
 * {@see EventRow} value objects. This is the shape application code typically
 * implements on top of the toolkit.
 */
final readonly class EventReader implements ClickHouseReaderInterface
{
    private const string TABLE = 'events';

    /** @var list<string> */
    private const array COLUMNS = ['id', 'type', 'user_id', 'payload', 'created_at'];

    private ClickHouseQueryBuilder $qb;

    public function __construct(private ClickHouseClient $client)
    {
        $this->qb = new ClickHouseQueryBuilder(
            allowedFields: ['id', 'type', 'user_id', 'created_at'],
            fieldTypes: [
                'id' => T::UInt64,
                'user_id' => T::UInt32,
                'created_at' => T::DateTime,
            ],
            defaultSort: 'id DESC',
        );
    }

    /**
     * @return list<EventRow>
     */
    public function findByFilters(
        ?FilterInterface $filter = null,
        ?Sort $sort = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $where = $this->qb->buildWhere($filter ?? new All());

        $sql = $this->qb->buildSelect(
            table: self::TABLE,
            columns: self::COLUMNS,
            where: $where->sql,
            orderBy: $this->qb->buildOrderBy($sort),
            limit: $limit,
            offset: $offset,
        );

        $rows = $where->isEmpty()
            ? $this->client->select($sql, new JsonEachRow())->data
            : $this->client->selectWithParams($sql, $where->params, new JsonEachRow())->data;

        return array_map(EventRow::fromArray(...), $rows);
    }

    public function countByFilters(?FilterInterface $filter = null): int
    {
        $where = $this->qb->buildWhere($filter ?? new All());
        $sql = $this->qb->buildCount(table: self::TABLE, where: $where->sql);

        $data = $where->isEmpty()
            ? $this->client->select($sql, new JsonEachRow())->data
            : $this->client->selectWithParams($sql, $where->params, new JsonEachRow())->data;

        return (int) ($data[0]['cnt'] ?? 0);
    }
}
