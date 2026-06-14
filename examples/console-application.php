<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsGenerateCommand;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsRunCommand;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsStatusCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/_bootstrap.php';

// Requires a running ClickHouse server (see examples/README.md).
//
// Builds a single Symfony Console Application exposing all three migration
// commands. Run it as:
//
//   php examples/console-application.php clickhouse:migrations:status
//   php examples/console-application.php clickhouse:migrations:generate "add index"
//   php examples/console-application.php clickhouse:migrations:migrate

$migrationsPath = getenv('CLICKHOUSE_MIGRATIONS_PATH') ?: __DIR__ . '/migrations';

$runner = new ClickHouseMigrationRunner(
    client: example_client(),
    migrationsPath: $migrationsPath,
);
$generator = new ClickHouseMigrationGenerator($migrationsPath);

$application = new Application('clickhouse-toolkit');
$application->addCommands([
    new ClickHouseMigrationsGenerateCommand($generator),
    new ClickHouseMigrationsStatusCommand($runner),
    new ClickHouseMigrationsRunCommand($runner),
]);

$application->run();
