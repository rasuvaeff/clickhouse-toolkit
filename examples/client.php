<?php

declare(strict_types=1);

use SimPod\ClickHouseClient\Format\JsonEachRow;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server (see examples/README.md).

$client = example_client();

/** @var \SimPod\ClickHouseClient\Output\JsonEachRow<array{version: string}> $output */
$output = $client->select('SELECT version() AS version', new JsonEachRow());

printf("Connected to ClickHouse %s\n", $output->data[0]['version'] ?? 'unknown');
