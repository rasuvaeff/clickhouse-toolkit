---
name: rasuvaeff-clickhouse-toolkit
description: >-
  Query, read, write and migrate ClickHouse with rasuvaeff/clickhouse-toolkit —
  ClickHouseQueryBuilder (yiisoft/data filters → parameterized SQL),
  ClickHouseDataReader, ClickHouseKeysetReader, ClickHouseBatchWriter,
  ClickHouseTableBuilder, ClickHousePartitionManager, ClickHouseMutationBuilder,
  ClickHouseMigrationRunner. Use when writing, reviewing or debugging ClickHouse
  queries, filters, pagination, inserts or migrations in a project that has this
  package installed.
---

# rasuvaeff/clickhouse-toolkit

Framework-agnostic ClickHouse toolkit built on `simpod/clickhouse-client`:
client factory, filter→SQL query builder, readers, batched writer, DDL,
partitions, mutations and file migrations. Namespace `Rasuvaeff\ClickHouseToolkit\`.

## Safety rules — verify these on every change

1. **Values are safe; identifiers are trusted.** User VALUES always become
   bound parameters (`{p0:Type}`) — never string-concat them. IDENTIFIERS
   (table, columns, allowedFields, fieldTypes) are validated as plain
   identifiers but NOT escaped — pass only hard-coded names, never user input.

2. **The allow-list is not an ACL.** Filters on unknown/disallowed fields are
   **silently dropped** — the query widens instead of failing. Enforce
   tenant/owner constraints with `withMandatoryFilter()` (always AND-combined,
   bypasses the allow-list), never via the user filter.

   ```php
   $qb = ClickHouseQueryBuilder::create(['id','status'])
       ->withMandatoryFilter(new Equals('tenant_id', $tenantId)); // correct
   $userFilter = new AndX(new Equals('tenant_id', $tenantId), …); // droppable!
   ```

3. **`orderBy` / `defaultSort` are trusted raw SQL fragments.** Never build
   them from user input — produce them with `buildOrderBy(Sort $sort)` (fields
   go through the allow-list) or use constants.

4. **`ClickHouseRawFilter` emits its SQL verbatim.** Only trusted, hard-coded
   SQL; user values go through `{name:Type}` `$params`, and those names must
   NOT clash with the builder's `p0,p1,…` or the keyset reader's `ck0,ck1,…`.

5. **`read()` materializes the whole result.** For large scans use
   `ClickHouseKeysetReader::stream()` (keyset pagination, one page in memory);
   its `keyColumns` must form a unique ascending total order — add `id` as a
   tie-breaker for non-unique sort columns.

## Canonical usage

```php
use Rasuvaeff\ClickHouseToolkit\{ClickHouseQueryBuilder, ClickHouseDataType as T};
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\{AndX, Equals, In};
use Yiisoft\Data\Reader\Sort;

$qb = new ClickHouseQueryBuilder(
    allowedFields: ['id', 'status', 'user_id', 'created_at'],
    fieldTypes: ['id' => T::UInt64, 'user_id' => T::UInt32, 'created_at' => T::DateTime],
);

$where = $qb->buildWhere(new AndX(new Equals('status', 'active'), new In('user_id', [1, 2, 3])));
$sql = $qb->buildSelect(
    table: 'events',
    columns: ['id', 'status'],
    where: $where->sql,
    orderBy: $qb->buildOrderBy(Sort::only(['created_at'])->withOrder(['created_at' => 'desc'])),
    limit: 50,
);
$rows = $client->selectWithParams($sql, $where->params, new JsonEachRow())->data;
```

## Full API

The complete reference — every filter mapping, `ClickHouseDataReader` /
`ClickHouseKeysetReader` / `ClickHouseBatchWriter` options, DDL, partitions,
mutations and migrations — ships with the package: read
`vendor/rasuvaeff/clickhouse-toolkit/llms.txt` before guessing a method name.
