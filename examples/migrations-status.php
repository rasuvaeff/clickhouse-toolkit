<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server (see examples/README.md).
// Run examples/run-migrations.php first so some migrations are applied.

$runner = new ClickHouseMigrationRunner(
    client: example_client(),
    migrationsPath: __DIR__ . '/migrations',
);

$statuses = $runner->status();

if ($statuses === []) {
    echo "No migration files found.\n";

    return;
}

$format = "%-32s %-10s %-42s %s\n";
echo sprintf($format, 'Migration', 'State', 'Checksum', 'Applied at');
echo sprintf($format, str_repeat('-', 32), str_repeat('-', 10), str_repeat('-', 42), str_repeat('-', 24));

foreach ($statuses as $status) {
    echo sprintf(
        $format,
        $status->name,
        $status->state->value,
        $status->checksum ?? '',
        $status->appliedAt ?? '',
    );
}

$counts = [];
foreach ($statuses as $status) {
    $counts[$status->state->value] = ($counts[$status->state->value] ?? 0) + 1;
}

echo "\nSummary: " . implode(', ', array_map(
    static fn(string $state, int $n): string => sprintf('%d %s', $n, $state),
    array_keys($counts),
    $counts,
)) . "\n";
