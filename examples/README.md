# Examples

Runnable examples for `rasuvaeff/clickhouse-toolkit`.

## Setup

From the package root:

```bash
composer install
```

Each script loads `../vendor/autoload.php`, so run them from anywhere after
`composer install`.

## Connection settings

Scripts that talk to a real server read these environment variables (with
sensible defaults):

| Variable | Default |
|----------|---------|
| `CLICKHOUSE_HOST` | `127.0.0.1` |
| `CLICKHOUSE_PORT` | `8123` |
| `CLICKHOUSE_DB` | `default` |
| `CLICKHOUSE_USER` | `default` |
| `CLICKHOUSE_PASSWORD` | *(empty)* |

A quick local server:

```bash
docker run --rm -p 8123:8123 -e CLICKHOUSE_DB=app clickhouse/clickhouse-server
```

## Scripts

| Script | Needs a server? | What it does |
|--------|:---------------:|--------------|
| `query-builder.php` | no | Prints the SQL and params generated for every supported filter/sort/select/count/distinct. |
| `di-container.php` | no | Wires the toolkit into a tiny PSR-11 container and resolves the services. |
| `client.php` | yes | Builds a client and runs `SELECT version()`. |
| `run-migrations.php` | yes | Applies the `*.sql` files in `migrations/` idempotently. |
| `batch-writer.php` | yes | Inserts 2500 rows in batches with `ClickHouseBatchWriter`. |
| `reader.php` | yes | Uses `EventReader` (implements `ClickHouseReaderInterface`) to page + filter + count rows. |
| `data-reader.php` | yes | Uses `ClickHouseDataReader` (yiisoft/data `DataReaderInterface`) for immutable, paginator-ready reads. |

```bash
php examples/query-builder.php          # offline, safe to run anywhere
php examples/di-container.php           # offline
php examples/client.php
php examples/run-migrations.php
php examples/batch-writer.php
php examples/reader.php
php examples/data-reader.php
```

`EventReader.php` and `EventRow.php` are supporting classes used by `reader.php`.

## Integration tests

`tests/Integration/` runs end-to-end against a real server. It is skipped unless
`CLICKHOUSE_HOST` is set:

```bash
CLICKHOUSE_HOST=127.0.0.1 CLICKHOUSE_PASSWORD=… vendor/bin/phpunit tests/Integration
```
