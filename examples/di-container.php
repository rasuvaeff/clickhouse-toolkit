<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunnerInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Offline: builds the container and resolves services. It does NOT open a
// connection (the client is only created lazily and never used here), so this
// runs without a server.

/**
 * Minimal PSR-11-style container good enough to demonstrate wiring.
 * In a real app use yiisoft/di, PHP-DI, Symfony DI, etc.
 */
$definitions = [
    ClickHouseClientFactory::class => static fn (): ClickHouseClientFactory => new ClickHouseClientFactory(
        new ClickHouseConfig(
            host: getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
            port: (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            database: getenv('CLICKHOUSE_DB') ?: 'default',
            username: getenv('CLICKHOUSE_USER') ?: 'default',
            password: getenv('CLICKHOUSE_PASSWORD') ?: '',
        ),
    ),

    PsrClickHouseClient::class => static fn (Closure $get): PsrClickHouseClient => $get(ClickHouseClientFactory::class)->create(),

    // Toolkit classes type-hint the ClickHouseClient interface.
    ClickHouseClient::class => static fn (Closure $get): ClickHouseClient => $get(PsrClickHouseClient::class),

    ClickHouseMigrationRunnerInterface::class => static fn (Closure $get): ClickHouseMigrationRunner => new ClickHouseMigrationRunner(
        client: $get(ClickHouseClient::class),
        migrationsPath: __DIR__ . '/migrations',
    ),
];

$cache = [];
$get = static function (string $id) use (&$get, &$cache, $definitions): object {
    return $cache[$id] ??= ($definitions[$id])($get);
};

$runner = $get(ClickHouseMigrationRunnerInterface::class);

printf("Resolved %s\n", $runner::class);
printf("Implements %s: %s\n", ClickHouseMigrationRunnerInterface::class, $runner instanceof ClickHouseMigrationRunnerInterface ? 'yes' : 'no');
