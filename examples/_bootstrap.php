<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Builds a client from environment variables. Shared by the server-backed examples.
 */
function example_client(): PsrClickHouseClient
{
    $config = new ClickHouseConfig(
        host: getenv('CLICKHOUSE_HOST') ?: '127.0.0.1',
        port: (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
        database: getenv('CLICKHOUSE_DB') ?: 'default',
        username: getenv('CLICKHOUSE_USER') ?: 'default',
        password: getenv('CLICKHOUSE_PASSWORD') ?: '',
    );

    return (new ClickHouseClientFactory($config))->create();
}
