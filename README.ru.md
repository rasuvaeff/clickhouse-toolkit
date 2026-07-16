# ClickHouse Toolkit

[English version](README.md)

Лёгкий framework-agnostic toolkit для ClickHouse и PHP 8.3+: client factory,
parameterized SQL query builder, immutable data readers, batch writer,
migrations и DDL helpers.

## Требования

PHP 8.3+ и зависимости из `composer.json`; HTTP-клиент предоставляется через
PSR interfaces, concrete client не требуется.

## Установка

```bash
composer require rasuvaeff/clickhouse-toolkit
```

## Quick start

```php
$qb = new ClickHouseQueryBuilder(allowedFields: ['id', 'status'], fieldTypes: ['id' => T::UInt64]);
$where = $qb->buildWhere(new Equals('status', 'active'));
$sql = $qb->buildSelect(table: 'events', where: $where->sql, limit: 20);
```

## Компоненты

`ClickHouseConfig` и `ClickHouseClientFactory` создают PSR-18 client.
`ClickHouseQueryBuilder`, `WhereClause` и visitors превращают `yiisoft/data`
filters в parameterized SQL. `ClickHouseDataReader` работает с paginators,
`ClickHouseKeysetReader` stream'ит большие наборы bounded memory,
`ClickHouseBatchWriter` буферизует inserts. Для schema operations доступны
`ClickHouseTableBuilder`, `ClickHousePartitionManager`, `ClickHouseMutationBuilder`,
`ClickHouseMigrationRunner` и `ClickHouseMigrationGenerator`.

## Dependency injection

Передайте `ClickHouseConfig`, PSR-18 client и optional PSR-17 factories через
ваш container. Полные Yii3 recipes и configuration examples: [README.md](README.md).

## Security notes

Все user values должны быть bound parameters `{pN:Type}`. Identifiers и type
tokens проверяйте через `Identifier::assert()`/`assertType()` или allow-list.
Unknown user filters намеренно silently dropped; обязательный ACL/tenant filter
задавайте через `withMandatoryFilter()`. Raw SQL допустим только в trusted
context, а его values всё равно передавайте через params.

## Что намеренно не включено

Пакет не реализует ORM, schema introspection, connection pooling или
application authorization: эти responsibilities остаются у приложения.

## Примеры

См. [examples/](examples/) и полную API-справку в [README.md](README.md).

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Integration suite требует реальный ClickHouse; команды приведены в `AGENTS.md`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
