<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server (see examples/README.md).

$runner = new ClickHouseMigrationRunner(
    client: example_client(),
    migrationsPath: __DIR__ . '/migrations',
);

$applied = $runner->run();

if ($applied === []) {
    echo "Schema is already up to date — nothing to apply.\n";
} else {
    echo "Applied migrations:\n";
    foreach ($applied as $name) {
        echo "  - {$name}\n";
    }
}

// Running this script again will print "already up to date": run() is idempotent.
