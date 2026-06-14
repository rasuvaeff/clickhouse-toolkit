<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;

require __DIR__ . '/_bootstrap.php';

// This script does not need a server: it only touches the filesystem.
// It writes into a temp directory so re-runs stay idempotent.

$migrationsPath = sys_get_temp_dir() . '/chmig-example-' . uniqid('', true);
@mkdir($migrationsPath, 0777, true);

$generator = new ClickHouseMigrationGenerator($migrationsPath);

$path1 = $generator->generate('create events table');
$path2 = $generator->generate('Add Click Index');
$path3 = $generator->generate('café Юникод v2');

echo "Created files:\n";
foreach ([$path1, $path2, $path3] as $path) {
    echo sprintf("  - %s\n", basename($path));
    echo sprintf("    contents: %s\n", addcslashes((string) file_get_contents($path), "\n"));
}

echo "\nDirectory listing (sorted — note the sequential prefix grows from 001):\n";
foreach (glob($migrationsPath . '/*.sql') as $file) {
    echo sprintf("  - %s\n", basename($file));
}

removeRecursively($migrationsPath);

function removeRecursively(string $path): void
{
    if (is_dir($path)) {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            removeRecursively($path . '/' . $entry);
        }
        rmdir($path);

        return;
    }

    if (file_exists($path)) {
        unlink($path);
    }
}
