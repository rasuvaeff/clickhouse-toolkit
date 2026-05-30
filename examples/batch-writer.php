<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server (run run-migrations.php first to create `events`).

$client = example_client();

$writer = new ClickHouseBatchWriter(
    client: $client,
    table: 'events',
    columns: ['id', 'type', 'user_id', 'payload', 'created_at'],
    batchSize: 1000,
);

// A generator keeps memory flat — rows are flushed in batches of 1000.
$rows = (static function (): iterable {
    for ($i = 1; $i <= 2500; $i++) {
        yield [
            'id' => $i,
            'type' => $i % 2 === 0 ? 'click' : 'view',
            'user_id' => 1000 + $i,
            'payload' => '{}',
            'created_at' => '2024-01-01 00:00:00',
            // Extra keys are dropped; missing declared columns are filled with null.
        ];
    }
})();

$writer->write($rows);

echo "Inserted 2500 rows in batches of 1000.\n";
