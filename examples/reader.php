<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\Examples\EventReader;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;
use Yiisoft\Data\Reader\Sort;

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/EventRow.php';
require __DIR__ . '/EventReader.php';

// Requires a running ClickHouse server with the `events` table
// (run examples/run-migrations.php first).

$reader = new EventReader(example_client());

$filter = new GreaterThanOrEqual('user_id', 1);
$sort = Sort::only(['created_at'])->withOrder(['created_at' => 'desc']);

$total = $reader->countByFilters($filter);
$rows = $reader->findByFilters(filter: $filter, sort: $sort, limit: 10, offset: 0);

printf("Total matching events: %d\n", $total);
printf("First %d:\n", count($rows));

foreach ($rows as $event) {
    printf(
        "  #%d  %-12s user=%d  %s\n",
        $event->id,
        $event->type,
        $event->userId,
        $event->createdAt->format('Y-m-d H:i:s'),
    );
}
