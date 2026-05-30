<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server with the `events` table and some data
// (run run-migrations.php and batch-writer.php first).

$reader = new ClickHouseDataReader(
    client: example_client(),
    table: 'events',
    queryBuilder: new ClickHouseQueryBuilder(
        allowedFields: ['id', 'type', 'user_id', 'created_at'],
        fieldTypes: ['id' => T::UInt64, 'user_id' => T::UInt32, 'created_at' => T::DateTime],
        defaultSort: 'id ASC',
    ),
    mapper: static fn (array $row): array => ['id' => (int) $row['id'], 'type' => (string) $row['type']],
    columns: ['id', 'type'],
);

// The reader is immutable: each with* call returns a new instance. This is the
// exact interface yiisoft/data paginators (OffsetPaginator/KeysetPaginator) consume.
$clicks = $reader
    ->withFilter(new Equals('type', 'click'))
    ->withSort(Sort::only(['id'])->withOrder(['id' => 'desc']))
    ->withLimit(5)
    ->withOffset(0);

printf("Total 'click' events: %d\n", $clicks->count());
echo "First page (5, id desc):\n";
foreach ($clicks->read() as $row) {
    printf("  #%d %s\n", $row['id'], $row['type']);
}
