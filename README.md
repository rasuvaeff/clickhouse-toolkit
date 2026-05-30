# ClickHouse Toolkit

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/v)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/downloads)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Build](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![Coverage](https://codecov.io/gh/rasuvaeff/clickhouse-toolkit/branch/master/graph/badge.svg)](https://codecov.io/gh/rasuvaeff/clickhouse-toolkit)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-4.7.0.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Lightweight, framework-agnostic ClickHouse helpers for PHP applications:

- **`ClickHouseClientFactory`** + **`ClickHouseConfig`** — build a configured client over any PSR-18 HTTP client (auto-discovered or injected; HTTP/HTTPS).
- **`ClickHouseQueryBuilder`** — turn [`yiisoft/data`](https://github.com/yiisoft/data) filters and sort into safe, parameterized SQL.
- **`ClickHouseFilterVisitor`** + **`ClickHouseSqlFilterVisitor`** — extensible visitor for SQL generation per filter type.
- **`ClickHouseDataReader`** — an immutable `DataReaderInterface` ready for yiisoft/data paginators.
- **`ClickHouseBatchWriter`** — buffered, batched inserts.
- **`ClickHouseTableBuilder`** — fluent `CREATE TABLE` DDL.
- **`ClickHousePartitionManager`** — list / drop / detach / attach / move / freeze partitions.
- **`ClickHouseMutationBuilder`** — async `ALTER … UPDATE/DELETE` with mutation tracking.
- **`ClickHouseMigrationRunner`** — idempotent, checksum-verified `*.sql` migrations.
- **`ClickHouseDataType`** — type-name constants and factories for parametric/nested types.

Built on top of [`simpod/clickhouse-client`](https://github.com/simPod/clickhouse-client). The query/reader pieces integrate with the `yiisoft/data` reader abstractions, so they slot naturally into Yii3 admin grids and paginated APIs, but nothing here requires the full framework.

> **Using an AI coding assistant?** [`llms.txt`](llms.txt) is a compact,
> self-contained reference of the whole public API plus copy-paste recipes —
> drop it into the model's context. Contributors: see [`AGENTS.md`](AGENTS.md).

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Components](#components)
  - [ClickHouseConfig & ClickHouseClientFactory](#clickhouseconfig--clickhouseclientfactory)
  - [ClickHouseQueryBuilder & WhereClause](#clickhousequerybuilder--whereclause)
  - [ClickHouseFilterVisitor](#clickhousefiltervisitor)
  - [ClickHouseDataReader](#clickhousedatareader)
  - [ClickHouseBatchWriter](#clickhousebatchwriter)
  - [ClickHouseTableBuilder](#clickhousetablebuilder)
  - [ClickHousePartitionManager](#clickhousepartitionmanager)
  - [ClickHouseMutationBuilder](#clickhousemutationbuilder)
  - [ClickHouseDataType](#clickhousedatatype)
  - [ClickHouseMigrationRunner](#clickhousemigrationrunner)
  - [Interfaces](#interfaces)
  - [Timezone handling](#timezone-handling)
- [Dependency injection](#dependency-injection)
- [Security notes](#security-notes)
- [What is intentionally not included](#what-is-intentionally-not-included)
- [Examples](#examples)
- [Development](#development)
- [License](#license)

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP         | `^8.3`  |
| A PSR-18 HTTP client + PSR-17 factories | any implementation |
| ClickHouse server | tested against 23.x – 26.x over the HTTP interface (port `8123`) |

The toolkit depends only on interfaces (`psr/http-client`, `psr/http-factory`, `psr/log`, `php-http/discovery`, `simpod/clickhouse-client`, `yiisoft/data`) — **not** on any concrete HTTP client. It auto-discovers an installed PSR-18 client/PSR-17 factories via [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html), or you can inject your own.

## Installation

```bash
composer require rasuvaeff/clickhouse-toolkit
```

You also need a PSR-18 client and PSR-17 factories if your project doesn't already ship one, e.g.:

```bash
composer require guzzlehttp/guzzle
# or: composer require symfony/http-client nyholm/psr7
```

## Quick start

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Sort;

// 1. Build a client.
$client = (new ClickHouseClientFactory(new ClickHouseConfig(
    host: 'clickhouse',
    port: 8123,
    database: 'app',
    username: 'default',
    password: '',
)))->create();

// 2. Build a safe, parameterized query from user-supplied filters.
$qb = new ClickHouseQueryBuilder(
    allowedFields: ['id', 'status', 'created_at'],
    fieldTypes: ['id' => T::UInt64, 'created_at' => T::DateTime],
    defaultSort: 'id DESC',
);

$where = $qb->buildWhere(new In('status', ['active', 'pending']));
$orderBy = $qb->buildOrderBy(Sort::only(['created_at'])->withOrder(['created_at' => 'desc']));
$sql = $qb->buildSelect(table: 'events', columns: ['id', 'status'], where: $where->sql, orderBy: $orderBy, limit: 20);

// 3. Execute.
$output = $where->isEmpty()
    ? $client->select($sql, new JsonEachRow())
    : $client->selectWithParams($sql, $where->params, new JsonEachRow());

foreach ($output->data as $row) {
    // ...
}
```

## Components

### `ClickHouseConfig` & `ClickHouseClientFactory`

`ClickHouseConfig` holds connection settings; `ClickHouseClientFactory` turns it into a `SimPod\ClickHouseClient\Client\PsrClickHouseClient`. The HTTP client and PSR-17 factories are auto-discovered (or injected). The endpoint is an absolute URI built from the config; authentication and database are sent via `X-ClickHouse-*` headers (an `AuthenticatingHttpClient` decorator), so credentials never appear in the URL.

```php
final readonly class ClickHouseConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 8123,
        public string $database = 'default',
        public string $username = 'default',
        public string $password = '',
        public bool $secure = false,   // true -> https://
    ) {}

    public function baseUri(): string; // e.g. "http://127.0.0.1:8123"
}
```

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;

// Auto-discovers an installed PSR-18 client + PSR-17 factories:
$client = (new ClickHouseClientFactory(new ClickHouseConfig(
    host: 'ch.internal',
    secure: true,     // https
)))->create();

$client->executeQuery('SELECT 1');
```

To control **timeouts, retries or TLS**, build your own PSR-18 client and inject it (along with the PSR-17 factories you want):

```php
use GuzzleHttp\Client;

$factory = new ClickHouseClientFactory(
    config: new ClickHouseConfig(host: 'ch.internal', secure: true),
    httpClient: new Client(['timeout' => 10.0]),
    // requestFactory / streamFactory / uriFactory are optional (auto-discovered when null)
);
```

### `ClickHouseQueryBuilder` & `WhereClause`

Translates `yiisoft/data` filters and sort into parameterized ClickHouse SQL. The builder is the security boundary: **only fields present in `allowedFields` are emitted** in `WHERE` and `ORDER BY`; anything else is silently dropped. Comparison values become **bound parameters with unique keys** (`p0`, `p1`, …), so the same field may appear multiple times without collisions.

```php
public function __construct(
    private array $allowedFields,            // list<string>
    private array $fieldTypes = [],          // field => ClickHouse type, default "String" (use ClickHouseDataType constants)
    private string $defaultSort = 'id DESC',
    private ?FilterInterface $mandatoryFilter = null,
    private ?string $serverTimezone = null,  // IANA timezone; DateTime values are converted before formatting
) {}
```

| Method | Returns | Description |
|--------|---------|-------------|
| `buildWhere(FilterInterface $filter)` | `WhereClause` | `{sql, params}`; `sql` is empty when nothing matched. |
| `buildOrderBy(?Sort $sort)` | `string` | ORDER BY (allow-list-checked), or `defaultSort`. |
| `buildSelect(string $table, array $columns = [], string $where = '', ?string $orderBy = null, ?int $limit = 20, int $offset = 0)` | `string` | `columns` empty → `SELECT *`; `limit` null → no LIMIT/OFFSET. |
| `buildCount(string $table, string $where = '')` | `string` | `SELECT count() AS cnt FROM ...`. |
| `buildDistinct(string $table, string $column)` | `string` | `SELECT DISTINCT col FROM ... ORDER BY col`. |

`WhereClause` is a small DTO: `public string $sql`, `public array $params`, and `isEmpty(): bool`.

**Supported filters**

| `yiisoft/data` filter | Rendered as | Notes |
|-----------------------|-------------|-------|
| `All`                 | empty `WHERE` | |
| `None`                | `0` | matches nothing |
| `Equals`              | `field = {p0:Type}` | |
| `GreaterThan` / `GreaterThanOrEqual` | `field > / >= {p0:Type}` | |
| `LessThan` / `LessThanOrEqual` | `field < / <= {p0:Type}` | |
| `EqualsNull`          | `field IS NULL` | no params |
| `In`                  | `field IN ({p0:Type}, {p1:Type}, …)` | empty values → `0` (match nothing) |
| `Between`             | `field BETWEEN {p0:Type} AND {p1:Type}` | |
| `Like`                | `field ILIKE {p0:String}` (or `LIKE` if `caseSensitive`) | value bound + wildcard-escaped; honours `LikeMode` Contains/StartsWith/EndsWith |
| `Not`                 | `NOT (...)` | dropped if the inner filter is empty |
| `AndX` / `OrX`        | `(a AND/OR b …)` | empty sub-filters skipped |

`DateTimeInterface` values are normalized to `Y-m-d H:i:s`; `bool` to `0/1`.

**Mandatory filters (tenant / owner / ACL)**

The builder is fluent and immutable. `withMandatoryFilter()` attaches an
always-applied filter that is **AND-combined** with the user filter and **bypasses
the allow-list** (its fields need not be in `allowedFields`; identifiers are still
validated). This is the safe way to enforce access constraints — the user filter can
only narrow within it.

```php
$qb = ClickHouseQueryBuilder::create(['id', 'status'], ['id' => T::UInt64])
    ->withMandatoryFilter(new Equals('tenant_id', $tenantId));

$where = $qb->buildWhere($userFilter); // (tenant_id = {p0:...}) AND (<user filter>)
```

**Raw expressions**

`ClickHouseRawFilter` is a `FilterInterface` that emits a raw SQL fragment for things
the typed filters can't express. The SQL is trusted (never from user input); values
go in `$params` using `{name:Type}` placeholders whose names must not clash with the
builder's auto keys (`p0`, `p1`, …).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;

$where = $qb->buildWhere(new ClickHouseRawFilter('toDate(created_at) = {d:Date}', ['d' => '2024-01-01']));
```

**Full read + count cycle**

```php
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;

$where = $qb->buildWhere(new AndX(
    new Equals('status', 'active'),
    new GreaterThanOrEqual('user_id', 1000),
));

$selectSql = $qb->buildSelect(table: 'events', columns: ['id', 'status'], where: $where->sql, limit: 50);
$countSql  = $qb->buildCount(table: 'events', where: $where->sql);

$rows  = $client->selectWithParams($selectSql, $where->params, new JsonEachRow())->data;
$total = (int) ($client->selectWithParams($countSql, $where->params, new JsonEachRow())->data[0]['cnt'] ?? 0);
```

### `ClickHouseFilterVisitor`

The query builder delegates SQL generation to a visitor. `ClickHouseFilterVisitor` is the interface with a `visit*()` method per filter type; `ClickHouseSqlFilterVisitor` is the default implementation. Use `dispatch(FilterInterface $filter, int &$index, bool $trusted)` to route any filter to the right method.

Implement `ClickHouseFilterVisitor` and inject via `withVisitor()` to customise SQL generation:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;

$qb = ClickHouseQueryBuilder::create(['id'], ['id' => 'UInt64'])
    ->withVisitor(new MyCustomVisitor());
```

### `ClickHouseDataReader`

An immutable `Yiisoft\Data\Reader\DataReaderInterface` backed by a ClickHouse table. Filtering, sorting and pagination are delegated to the query builder; rows are mapped to your value type by a supplied mapper. It plugs straight into yiisoft/data paginators (`OffsetPaginator`, `KeysetPaginator`).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

$reader = new ClickHouseDataReader(
    client: $client,
    table: 'events',
    queryBuilder: new ClickHouseQueryBuilder(
        allowedFields: ['id', 'type', 'created_at'],
    fieldTypes: ['id' => T::UInt64, 'created_at' => T::DateTime],
        defaultSort: 'id DESC',
    ),
    mapper: static fn (array $row): array => ['id' => (int) $row['id'], 'type' => (string) $row['type']],
    columns: ['id', 'type'],
);

$page = $reader
    ->withFilter(new Equals('type', 'click'))
    ->withSort(Sort::only(['id'])->withOrder(['id' => 'desc']))
    ->withLimit(20)
    ->withOffset(40);

$total = $page->count();   // ignores limit/offset
$rows  = $page->read();    // mapped values
```

Implements `read()`, `readOne()`, `count()`, `getIterator()`, and the immutable `withFilter/withSort/withLimit/withOffset` (+ getters). With no limit set, `read()` omits `LIMIT` and returns the full result.

### `ClickHouseBatchWriter`

Buffers rows and inserts them in fixed-size batches. Each row is projected onto the declared columns (extra keys dropped, missing keys → `null`), so loosely-shaped associative rows are fine. Failures are wrapped in `ClickHouseWriteException`.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;

$writer = new ClickHouseBatchWriter(
    client: $client,
    table: 'events',
    columns: ['id', 'type', 'user_id', 'created_at'],
    batchSize: 1000,
);

$writer->write($rows); // $rows: iterable<array<string, mixed>> — a generator keeps memory flat
```

Implements `ClickHouseWriterInterface` (`write(iterable $rows): void`).

### `ClickHouseTableBuilder`

Fluent `CREATE TABLE` builder. `build()` returns the SQL; `execute()` runs it via
the client. The table name and column names are validated identifiers; column
types, the engine, and the ORDER BY / PARTITION BY / PRIMARY KEY expressions are
emitted verbatim — DDL is developer-authored, so keep them trusted.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseTableBuilder;

ClickHouseTableBuilder::create($client, 'events')
    ->ifNotExists()
    ->column('id', T::UInt64)
    ->column('created_at', T::DateTime)
    ->engine('MergeTree()')
    ->partitionBy('toYYYYMM(created_at)')
    ->primaryKey('id')
    ->orderBy('(created_at, id)')
    ->execute();
```

`build()`/`execute()` throw if no columns or no engine were set.

### `ClickHousePartitionManager`

Manages MergeTree partitions through `ALTER TABLE … PARTITION`. Partition
operations can't use bound parameters, so a partition is addressed by its **id**
(from `getPartitions()`) and emitted as an escaped `PARTITION ID '…'`; table and
column names are validated identifiers.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHousePartitionManager;

$pm = new ClickHousePartitionManager($client);

foreach ($pm->getPartitions('events') as $p) {
    // ['partition' => '202401', 'partition_id' => '202401', 'rows' => 12345, 'bytes' => 987654]
}

$pm->dropPartition('events', '202401');
$pm->detachPartition('events', '202401');
$pm->attachPartition('events', '202401');
$pm->freezePartition('events', '202401');
$pm->clearColumnInPartition('events', '202401', 'payload');
$pm->movePartition('events', 'events_archive', '202401');     // MOVE … TO TABLE
$pm->replacePartition('events', 'events_mirror', '202401');   // REPLACE … FROM
```

### `ClickHouseMutationBuilder`

Submits and tracks mutations — `ALTER TABLE … UPDATE/DELETE`, the only way to
modify or delete existing rows. Mutations are asynchronous. The `$set` and
`$condition` fragments are trusted (developer-authored); pass user values as
bound `{name:Type}` parameters (ClickHouse supports parameters in `ALTER`).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;

$mb = new ClickHouseMutationBuilder($client);

$mb->update('events', 'status = {st:String}', 'id = {id:UInt64}', ['st' => 'archived', 'id' => 42]);
$mb->delete('events', 'created_at < {cutoff:DateTime}', ['cutoff' => '2023-01-01 00:00:00']);

$mb->waitForMutations('events', timeout: 30.0); // poll system.mutations until done -> bool

foreach ($mb->getMutations('events') as $m) {
    // ['mutation_id' => '...', 'command' => '...', 'is_done' => true, 'parts_to_do' => 0, 'latest_fail_reason' => '']
}

$mb->killMutation('events', $mutationId);
```

### `ClickHouseMigrationRunner`

Applies `*.sql` files from a directory in filename order, recording each applied file with a **content checksum** in a `_migrations` table.

- **Idempotent** — already-applied files are skipped.
- **Tamper-evident** — if an already-applied file's contents changed, a `ClickHouseMigrationException` is thrown instead of silently diverging.
- **One statement per file** — contents are sent as a single query (no naive `;` splitting).
- **Optional PSR-3 logging** — pass a `LoggerInterface` to log applied/skipped files.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

$runner = new ClickHouseMigrationRunner(
    client: $client,
    migrationsPath: __DIR__ . '/migrations',
    logger: $logger, // optional PSR-3
);

$applied = $runner->run(); // list<string> of files applied this call
```

Tracking table (created automatically):

```sql
CREATE TABLE IF NOT EXISTS `_migrations` (
    name String, checksum String, applied_at DateTime64(6) DEFAULT now64(6)
) ENGINE = ReplacingMergeTree(applied_at) ORDER BY name
```

Name files so lexicographic order equals execution order, e.g. `001_create_events.sql`, `002_add_index.sql`.

> **Concurrency & partial failure.** ClickHouse has no transactions and the runner uses no distributed lock: the applied-list is read, then each file is executed and recorded separately. Two runners started at once may both run the same pending file, and if a file's DDL succeeds but the `_migrations` insert does not, the next run repeats it. Run migrations from a single deploy step, prefer idempotent DDL (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`), and wrap `run()` in an external lock if you need stronger guarantees.

### `ClickHouseDataType`

Type-name constants and factories so type definitions are self-documenting and
typo-proof. Types are plain strings, usable anywhere one is expected
(`ClickHouseTableBuilder` columns, `ClickHouseQueryBuilder` field types).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;

T::UInt64;                                  // 'UInt64'
T::nullable(T::String);                     // 'Nullable(String)'
T::array(T::nullable(T::String));           // 'Array(Nullable(String))'
T::map(T::String, T::UInt64);               // 'Map(String, UInt64)'
T::decimal(10, 2);                          // 'Decimal(10, 2)'
T::dateTime64(3, 'UTC');                    // "DateTime64(3, 'UTC')"
T::enum8(['active' => 1, 'inactive' => 2]); // "Enum8('active' = 1, 'inactive' = 2)"
```

Composite types (Enum, timezone-qualified DateTime) are for column definitions,
not query-parameter types.

### Interfaces

| Interface | Method(s) | Purpose |
|-----------|-----------|---------|
| `ClickHouseMigrationRunnerInterface` | `run(): list<string>` | Implemented by `ClickHouseMigrationRunner`. |
| `ClickHouseWriterInterface` | `write(iterable $rows): void` | Implemented by `ClickHouseBatchWriter`. |
| `ClickHouseReaderInterface` | `findByFilters(...)`, `countByFilters(...)` | A simpler reader contract than `DataReaderInterface`; implement it per table when you don't need the full reader (see [`examples/EventReader.php`](examples/EventReader.php)). |
| `ClickHouseFilterVisitor` | `visit*()` per filter type | SQL generation for each filter type. Implemented by `ClickHouseSqlFilterVisitor`. Inject a custom implementation via `withVisitor()`. |

### Timezone handling

`ClickHouseQueryBuilder` accepts an optional `serverTimezone` (IANA name, e.g. `"UTC"`, `"Europe/Moscow"`). When set, `DateTimeInterface` filter values are converted to that timezone before being formatted as `Y-m-d H:i:s`. Without it, the object's own timezone is used (backward compatible).

```php
$qb = new ClickHouseQueryBuilder(
    allowedFields: ['created_at'],
    fieldTypes: ['created_at' => T::DateTime],
    serverTimezone: 'UTC',
);

// A DateTimeImmutable in Europe/Moscow (+03:00) will be formatted as UTC.
$where = $qb->buildWhere(new Equals('created_at', new \DateTimeImmutable('2024-06-15 15:00:00+03:00')));
// params: ['p0' => '2024-06-15 12:00:00']
```

Fluent: `$qb->withServerTimezone('UTC')` returns a new instance.

## Dependency injection

Any PSR-11 container works. Example using Yiisoft DI definitions (Yii3):

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunnerInterface;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Client\PsrClickHouseClient;

return [
    ClickHouseConfig::class => static fn (): ClickHouseConfig => new ClickHouseConfig(
        host: $_ENV['CLICKHOUSE_HOST'] ?? 'clickhouse',
        port: (int) ($_ENV['CLICKHOUSE_PORT'] ?? 8123),
        database: $_ENV['CLICKHOUSE_DB'] ?? 'app',
        username: $_ENV['CLICKHOUSE_USER'] ?? 'default',
        password: $_ENV['CLICKHOUSE_PASSWORD'] ?? '',
    ),

    PsrClickHouseClient::class => static fn (ClickHouseClientFactory $f): PsrClickHouseClient => $f->create(),
    ClickHouseClient::class => PsrClickHouseClient::class, // toolkit classes type-hint the interface

    ClickHouseMigrationRunnerInterface::class => static fn (ClickHouseClient $client): ClickHouseMigrationRunner => new ClickHouseMigrationRunner(
        client: $client,
        migrationsPath: dirname(__DIR__) . '/resources/clickhouse-migrations',
    ),
];
```

See [`examples/di-container.php`](examples/di-container.php) for a runnable plain-PHP container wiring.

## Security notes

- **Allow-list enforcement.** `ClickHouseQueryBuilder` only emits allow-listed fields in `WHERE` and `ORDER BY` (each `allowedFields` entry is validated as an identifier at construction). Pass user-controlled filter/sort objects straight through — unknown fields are dropped.
- **Disallowed user filters are silently dropped** (widening, not narrowing). For mandatory tenant/owner/ACL constraints do **not** rely on user filters — use `withMandatoryFilter()`, which is always applied and AND-combined so the user filter can only narrow within it.
- **Bound parameters.** All comparison/`In`/`Between`/`Like` values are passed as ClickHouse bound parameters (`{pN:Type}`) with unique keys; values are never concatenated into SQL.
- **`Like` escaping.** `Like` values are wildcard-escaped (`addcslashes($value, '%_\\')`) and bound as a parameter — the quote is not escaped (it lives in the parameter, not the SQL).
- **Table/column names** passed to `buildSelect`/`buildCount`/`buildDistinct` and the `columns` projection are **not** escaped, but they are **validated** as plain SQL identifiers (`db.table` allowed); a malformed identifier throws `InvalidArgumentException`. Still pass trusted, plain identifiers — the validator rejects raw expressions (`toDate(x) AS d`), so build those yourself.
- **Pagination.** `buildSelect` rejects negative `limit`/`offset` with `InvalidArgumentException`.
- **`orderBy`** passed to `buildSelect`, and the constructor's `defaultSort`, are trusted raw ORDER BY fragments — **not** validated. Use `buildOrderBy()` output (allow-list-checked) or a hard-coded constant; never build them from untrusted input.
- **`fieldTypes`** type tokens are validated (allowing parametric types like `Array(Nullable(String))`) so they can't break out of the `{name:Type}` placeholder. They are developer configuration, not user input.
- **Credentials** travel in `X-ClickHouse-*` headers, not the URL.

## What is intentionally not included

- Concrete readers/writers for specific tables (row shapes are app-specific — use `ClickHouseDataReader` with a mapper, or implement `ClickHouseReaderInterface`).
- A migration generator or rollback/down migrations.
- Connection pooling or retries.
- Framework bootloaders/service providers (wire it in your app — see [Dependency injection](#dependency-injection)).

## Examples

Runnable, self-contained examples live in [`examples/`](examples/):

| File | Server? | Shows |
|------|:-------:|-------|
| [`query-builder.php`](examples/query-builder.php) | no | Every supported filter/sort/select/count/distinct — prints the generated SQL. |
| [`di-container.php`](examples/di-container.php) | no | Wiring the toolkit into a PSR-11 container. |
| [`client.php`](examples/client.php) | yes | Building a client and running a query. |
| [`run-migrations.php`](examples/run-migrations.php) + [`migrations/`](examples/migrations) | yes | Applying `*.sql` migrations idempotently. |
| [`batch-writer.php`](examples/batch-writer.php) | yes | Batched inserts via `ClickHouseBatchWriter`. |
| [`reader.php`](examples/reader.php) + [`EventReader.php`](examples/EventReader.php) | yes | A `ClickHouseReaderInterface` implementation with row mapping. |
| [`data-reader.php`](examples/data-reader.php) | yes | Immutable `ClickHouseDataReader` (paginator-ready). |

See [`examples/README.md`](examples/README.md) for how to run them.

## Development

```bash
composer install
composer build       # validate + normalize + require-checker + cs + psalm + phpunit
composer test        # phpunit only
composer cs:fix      # apply php-cs-fixer
composer psalm       # static analysis (errorLevel=1)
```

Integration tests in `tests/Integration/` run end-to-end against a real server and are skipped unless `CLICKHOUSE_HOST` is set:

```bash
CLICKHOUSE_HOST=127.0.0.1 CLICKHOUSE_PASSWORD=… vendor/bin/phpunit tests/Integration
```

CI runs `composer build` on PHP 8.3, 8.4, and 8.5.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
