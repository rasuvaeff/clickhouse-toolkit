# ClickHouse Toolkit

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/v)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/downloads)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Build](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/clickhouse-toolkit/php)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Лёгкие, не зависящие от фреймворка утилиты ClickHouse для PHP-приложений.

```php
$qb = new ClickHouseQueryBuilder(allowedFields: ['id', 'status'], fieldTypes: ['id' => T::UInt64]);
$where = $qb->buildWhere(new Equals('status', 'active'));
$sql = $qb->buildSelect(table: 'events', where: $where->sql, limit: 20);
```

- **`ClickHouseClientFactory`** + **`ClickHouseConfig`** — создают настроенный клиент поверх любого PSR-18 HTTP-клиента (автообнаружение или внедрение; HTTP/HTTPS).
- **`ClickHouseQueryBuilder`** — превращает фильтры и сортировку [`yiisoft/data`](https://github.com/yiisoft/data) в параметризованный безопасный SQL.
- **`ClickHouseFilterVisitor`** + **`ClickHouseSqlFilterVisitor`** — расширяемый visitor для генерации SQL по типам фильтров.
- **`ClickHouseDataReader`** — иммутабельная реализация `DataReaderInterface`, готовая для пагинаторов `yiisoft/data`.
- **`ClickHouseKeysetReader`** — потоковое чтение больших выборок с ограниченным потреблением памяти через keyset-пагинацию.
- **`ClickHouseBatchWriter`** — буферизованные, пакетные вставки.
- **`ClickHouseTableBuilder`** — fluent-билдер DDL `CREATE TABLE`.
- **`ClickHousePartitionManager`** — list / drop / detach / attach / move / freeze для партиций.
- **`ClickHouseMutationBuilder`** — асинхронные `ALTER … UPDATE/DELETE` с трекингом мутаций.
- **`ClickHouseMigrationRunner`** — идемпотентные, проверяемые по контрольной сумме миграции `*.sql`.
- **`ClickHouseMigrationGenerator`** — создаёт новые файлы миграций с автоинкрементными числовыми префиксами.
- **`ClickHouseDataType`** — константы имён типов и фабрики для параметризованных/вложенных типов.

Построен поверх [`simpod/clickhouse-client`](https://github.com/simPod/clickhouse-client). Компоненты query/reader интегрируются с абстракциями чтения `yiisoft/data` и естественно встраиваются в Yii3-админки и пагинируемые API, но полный фреймворк не требуется.

> **Используете AI-ассистента?** [`llms.txt`](llms.txt) — компактный,
> самодостаточный справочник по всему публичному API плюс готовые рецепты —
> можно закинуть прямо в контекст модели. Контрибьюторам: см. [`AGENTS.md`](AGENTS.md).

## Оглавление

- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Компоненты](#компоненты)
  - [ClickHouseConfig & ClickHouseClientFactory](#clickhouseconfig--clickhouseclientfactory)
  - [ClickHouseQueryBuilder & WhereClause](#clickhousequerybuilder--whereclause)
  - [ClickHouseFilterVisitor](#clickhousefiltervisitor)
  - [ClickHouseDataReader](#clickhousedatareader)
  - [ClickHouseKeysetReader](#clickhousekeysetreader)
  - [ClickHouseBatchWriter](#clickhousebatchwriter)
  - [ClickHouseTableBuilder](#clickhousetablebuilder)
  - [ClickHousePartitionManager](#clickhousepartitionmanager)
  - [ClickHouseMutationBuilder](#clickhousemutationbuilder)
  - [ClickHouseDataType](#clickhousedatatype)
  - [ClickHouseMigrationRunner](#clickhousemigrationrunner)
  - [ClickHouseMigrationGenerator & status()](#clickhousemigrationgenerator--status)
  - [Консольные команды](#консольные-команды)
  - [Интерфейсы](#интерфейсы)
  - [Работа с часовыми поясами](#работа-с-часовыми-поясами)
- [Внедрение зависимостей](#внедрение-зависимостей)
- [Замечания по безопасности](#замечания-по-безопасности)
- [Что намеренно не включено](#что-намеренно-не-включено)
- [Примеры](#примеры)
- [Разработка](#разработка)
- [Лицензия](#лицензия)

## Требования

| Требование | Версия |
|-------------|---------|
| PHP         | `^8.3`  |
| PSR-18 HTTP-клиент + PSR-17 фабрики | любая реализация |
| ClickHouse-сервер | протестировано на 23.x – 26.x через HTTP-интерфейс (порт `8123`) |

Тулкит зависит только от интерфейсов (`psr/http-client`, `psr/http-factory`, `psr/log`, `php-http/discovery`, `simpod/clickhouse-client`, `yiisoft/data`) — **не** от конкретного HTTP-клиента. Установленный PSR-18-клиент и PSR-17-фабрики автообнаруживаются через [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html), либо вы можете внедрить свои.

## Установка

```bash
composer require rasuvaeff/clickhouse-toolkit
```

Если в проекте ещё нет PSR-18-клиента и PSR-17-фабрик, добавьте их, например:

```bash
composer require guzzlehttp/guzzle
# или: composer require symfony/http-client nyholm/psr7
```

## Быстрый старт

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

## Компоненты

### `ClickHouseConfig` & `ClickHouseClientFactory`

`ClickHouseConfig` хранит настройки соединения; `ClickHouseClientFactory` превращает их в `SimPod\ClickHouseClient\Client\PsrClickHouseClient`. HTTP-клиент и PSR-17-фабрики автообнаруживаются (или внедряются). Endpoint — абсолютный URI, собранный из конфига; аутентификация и база данных передаются через заголовки `X-ClickHouse-*` (через декоратор `AuthenticatingHttpClient`), поэтому учётные данные никогда не попадают в URL.

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

Чтобы управлять **таймаутами, повторами или TLS**, создайте собственный PSR-18-клиент и внедрите его (вместе с нужными PSR-17-фабриками):

```php
use GuzzleHttp\Client;

$factory = new ClickHouseClientFactory(
    config: new ClickHouseConfig(host: 'ch.internal', secure: true),
    httpClient: new Client(['timeout' => 10.0]),
    // requestFactory / streamFactory / uriFactory are optional (auto-discovered when null)
);
```

### `ClickHouseQueryBuilder` & `WhereClause`

Преобразует фильтры и сортировку `yiisoft/data` в параметризованный SQL для ClickHouse. Билдер — это граница безопасности: **в `WHERE` и `ORDER BY` попадают только поля из `allowedFields`**; всё остальное молча отбрасывается. Значения сравнений становятся **bound-параметрами с уникальными ключами** (`p0`, `p1`, …), поэтому одно и то же поле может встречаться многократно без коллизий.

```php
public function __construct(
    private array $allowedFields,            // list<string>
    private array $fieldTypes = [],          // field => ClickHouse type, default "String" (use ClickHouseDataType constants)
    private string $defaultSort = '', // no ORDER BY by default; pass e.g. 'id DESC' for stable pagination
    private ?FilterInterface $mandatoryFilter = null,
    private ?string $serverTimezone = null,  // IANA timezone; DateTime values are converted before formatting
) {}
```

| Метод | Возвращает | Описание |
|--------|---------|-------------|
| `buildWhere(FilterInterface $filter)` | `WhereClause` | `{sql, params}`; `sql` пуст, если ничего не совпало. |
| `buildOrderBy(?Sort $sort)` | `string` | Фрагмент ORDER BY (проверяется по allow-list) или `defaultSort`; пустая строка означает отсутствие `ORDER BY`. |
| `buildSelect(string $table, array $columns = [], string $where = '', ?string $orderBy = null, ?int $limit = 20, int $offset = 0)` | `string` | `columns` пуст → `SELECT *`; пустой порядок → без `ORDER BY`; `limit` null → без LIMIT/OFFSET. |
| `buildCount(string $table, string $where = '')` | `string` | `SELECT count() AS cnt FROM ...`. |
| `buildDistinct(string $table, string $column)` | `string` | `SELECT DISTINCT col FROM ... ORDER BY col`. |

`WhereClause` — небольшой DTO: `public string $sql`, `public array $params` и `isEmpty(): bool`.

**Поддерживаемые фильтры**

| Фильтр `yiisoft/data` | Рендерится как | Примечания |
|-----------------------|-------------|-------|
| `All`                 | пустой `WHERE` | |
| `None`                | `0` | ничего не матчит |
| `Equals`              | `field = {p0:Type}` | |
| `GreaterThan` / `GreaterThanOrEqual` | `field > / >= {p0:Type}` | |
| `LessThan` / `LessThanOrEqual` | `field < / <= {p0:Type}` | |
| `EqualsNull`          | `field IS NULL` | без параметров |
| `In`                  | `field IN ({p0:Type}, {p1:Type}, …)` | пустые значения → `0` (ничего не матчит) |
| `Between`             | `field BETWEEN {p0:Type} AND {p1:Type}` | |
| `Like`                | `field ILIKE {p0:String}` (или `LIKE`, если `caseSensitive`) | нестроковые поля оборачиваются в `toString(field)`; пустые значения отбрасываются; значение биндится с экранированием wildcards; учитывает `LikeMode` Contains/StartsWith/EndsWith |
| `Not`                 | `NOT (...)` | отбрасывается, если внутренний фильтр пуст |
| `AndX` / `OrX`        | `(a AND/OR b …)` | пустые подфильтры пропускаются |

Значения `DateTimeInterface` нормализуются в `Y-m-d H:i:s`; `bool` — в `0/1`.

**Обязательные фильтры (tenant / owner / ACL)**

Билдер fluent и иммутабельный. `withMandatoryFilter()` подключает фильтр, который применяется всегда, **AND-объединяется** с пользовательским фильтром и **обходит allow-list** (его поля не обязаны быть в `allowedFields`; идентификаторы по-прежнему валидируются). Это безопасный способ инфорсить ограничения доступа — пользовательский фильтр может только сужать выборку внутри него.

```php
$qb = ClickHouseQueryBuilder::create(['id', 'status'], ['id' => T::UInt64])
    ->withMandatoryFilter(new Equals('tenant_id', $tenantId));

$where = $qb->buildWhere($userFilter); // (tenant_id = {p0:...}) AND (<user filter>)
```

**Сырые выражения**

`ClickHouseRawFilter` — это `FilterInterface`, который эммитит сырой SQL-фрагмент для случаев, недоступных типизированным фильтрам. SQL считается доверенным (никогда не из пользовательского ввода); значения попадают в `$params` через `{name:Type}`-плейсхолдеры, имена которых не должны конфликтовать с авто-ключами билдера (`p0`, `p1`, …).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;

$where = $qb->buildWhere(new ClickHouseRawFilter('toDate(created_at) = {d:Date}', ['d' => '2024-01-01']));
```

**Полный цикл чтения + подсчёта**

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

Билдер запросов делегирует генерацию SQL visitor'у. `ClickHouseFilterVisitor` — интерфейс с методом `visit*()` для каждого типа фильтра; `ClickHouseSqlFilterVisitor` — реализация по умолчанию. Используйте `dispatch(FilterInterface $filter, int &$index, bool $trusted)` для маршрутизации любого фильтра в нужный метод.

Реализуйте `ClickHouseFilterVisitor` и внедрите через `withVisitor()`, чтобы настроить генерацию SQL:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;

$qb = ClickHouseQueryBuilder::create(['id'], ['id' => 'UInt64'])
    ->withVisitor(new MyCustomVisitor());
```

### `ClickHouseDataReader`

Иммутабельная реализация `Yiisoft\Data\Reader\DataReaderInterface` поверх таблицы ClickHouse. Фильтрация, сортировка и пагинация делегируются билдеру запросов; строки мапятся в ваш value-тип поставляемым mapper'ом. Подключается напрямую к пагинаторам `yiisoft/data` (`OffsetPaginator`, `KeysetPaginator`).

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

Реализует `read()`, `readOne()`, `count()`, `getIterator()` и иммутабельные `withFilter/withSort/withLimit/withOffset` (+ геттеры). Без лимита `read()` опускает `LIMIT` и возвращает полный результат.

> `read()` / `getIterator()` материализуют весь результат в памяти. Для перебора большой выборки с ограниченным потреблением памяти используйте `ClickHouseKeysetReader` ниже.

### `ClickHouseKeysetReader`

Стримит большую выборку с **ограниченным потреблением памяти** через keyset (seek) пагинацию. Каждая страница — обычный запрос `WHERE <key> > <last-seen> ORDER BY <key> LIMIT <pageSize>`, поэтому весь результат не грузится целиком, а глубокие страницы, в отличие от `LIMIT/OFFSET`, остаются дешёвыми (сканирование диапазона по первичному индексу вместо пропуска строк). Обязательный (tenant/ACL) фильтр и allow-list билдера применяются на каждой странице.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseKeysetReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Yiisoft\Data\Reader\Filter\Equals;

$reader = new ClickHouseKeysetReader(
    client: $client,
    table: 'events',
    queryBuilder: new ClickHouseQueryBuilder(
        allowedFields: ['id', 'status'],
        fieldTypes: ['id' => T::UInt64],
    ),
    mapper: static fn (array $row): int => (int) $row['id'],
    keyColumns: ['id' => T::UInt64],   // ordered map column => ClickHouse type
    columns: ['id', 'status'],          // key columns are added automatically
    pageSize: 1000,
    filter: new Equals('status', 'active'),
);

foreach ($reader->stream() as $id) {   // one page in memory at a time
    // ...
}
```

Ключевые столбцы **должны образовывать уникальный возрастающий total order** — для неуникального столбца сортировки добавьте уникальный tie-breaker, выраженный как кортеж столбцов, сравниваемых через кортежное сравнение ClickHouse:

```php
keyColumns: ['created_at' => T::DateTime, 'id' => T::UInt64],
// boundary: (created_at, id) > ({ck0:DateTime}, {ck1:UInt64})
```

Иначе строки, разделяющие граничный ключ, могут быть пропущены. Ключевые столбцы не должны быть nullable. Параметры границы используют зарезервированные имена `ck0`, `ck1`, … — не конфликтуйте с любым `ClickHouseRawFilter` в базовом фильтре.

### `ClickHouseBatchWriter`

Буферизует строки и вставляет их пакетами фиксированного размера. Каждая строка проецируется на объявленные столбцы (лишние ключи отбрасываются, отсутствующие → `null`), поэтому loosely-структурированные ассоциативные строки допустимы. Сбои оборачиваются в `ClickHouseWriteException`.

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

Реализует `ClickHouseWriterInterface` (`write(iterable $rows): void`).

Для high-throughput ингестии передавайте `settings` запроса ClickHouse — они применяются к каждой пакетной `INSERT`. Например, делегируйте буферизацию серверу через [асинхронные вставки](https://clickhouse.com/docs/en/optimize/asynchronous-inserts):

```php
$writer = new ClickHouseBatchWriter(
    client: $client,
    table: 'events',
    columns: ['id', 'type', 'user_id', 'created_at'],
    batchSize: 10_000,
    settings: ['async_insert' => 1, 'wait_for_async_insert' => 0],
);
```

### `ClickHouseTableBuilder`

Fluent-билдер `CREATE TABLE`. `build()` возвращает SQL; `execute()` запускает его через клиент. Имя таблицы и имена столбцов — валидируемые идентификаторы; типы столбцов, движок и выражения ORDER BY / PARTITION BY / PRIMARY KEY эммитятся as-is — DDL пишется разработчиком, поэтому считается доверенным.

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
    ->orderBy('(id, created_at)')
    ->execute();
```

`build()`/`execute()` выбрасывают исключение, если не заданы столбцы или движок.

### `ClickHousePartitionManager`

Управляет партициями MergeTree через `ALTER TABLE … PARTITION`. Операции с партициями не могут использовать bound-параметры, поэтому партиция адресуется по своему **id** (из `getPartitions()`) и эммитится как экранированный `PARTITION ID '…'`; имена таблиц и столбцов — валидируемые идентификаторы.

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

Отправляет и отслеживает мутации — `ALTER TABLE … UPDATE/DELETE`, единственный способ изменить или удалить существующие строки. Мутации асинхронны. Фрагменты `$set` и `$condition` считаются доверенными (пишутся разработчиком); пользовательские значения передавайте как bound-параметры `{name:Type}` (ClickHouse поддерживает параметры в `ALTER`).

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

Применяет файлы `*.sql` из каталога в порядке имён файлов, записывая каждый применённый файл с **контрольной суммой** в таблицу `_migrations`.

- **Идемпотент** — уже применённые файлы пропускаются.
- **Tamper-evident** — если содержимое уже применённого файла изменилось, выбрасывается `ClickHouseMigrationException` вместо молчаливого расхождения.
- **Один оператор на файл** — содержимое отправляется как один запрос (без наивного разбиения по `;`).
- **Опциональное PSR-3-логирование** — передайте `LoggerInterface`, чтобы логировать применённые/пропущенные файлы.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

$runner = new ClickHouseMigrationRunner(
    client: $client,
    migrationsPath: __DIR__ . '/migrations',
    logger: $logger, // optional PSR-3
);

$applied = $runner->run(); // list<string> of files applied this call
```

Таблица трекинга (создаётся автоматически):

```sql
CREATE TABLE IF NOT EXISTS `_migrations` (
    name String, checksum String, applied_at DateTime64(6) DEFAULT now64(6)
) ENGINE = ReplacingMergeTree(applied_at) ORDER BY name
```

Называйте файлы так, чтобы лексикографический порядок совпадал с порядком выполнения, например `001_create_events.sql`, `002_add_index.sql`.

> **Конкурентность и частичные сбои.** В ClickHouse нет транзакций, а раннер не использует распределённую блокировку: читается список применённых, затем каждый файл выполняется и записывается отдельно. Два одновременно запущенных раннера могут оба применить один и тот же ожидающий файл, а если DDL файла успешен, но вставка в `_migrations` — нет, следующий запуск повторит его. Запускайте миграции из одного deploy-шага, предпочитайте идемпотентный DDL (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`) и оборачивайте `run()` во внешнюю блокировку, если нужны более строгие гарантии.

### `ClickHouseMigrationGenerator` & `status()`

Два хелпера, дополняющих workflow миграций:

- **`ClickHouseMigrationGenerator`** создаёт новый файл миграции со следующим порядковым числовым префиксом. Это простой файловый хелпер — клиент ClickHouse не требуется.
- **`ClickHouseMigrationRunner::status()`** сообщает состояние каждого файла миграции относительно таблицы `_migrations`.

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;

$generator = new ClickHouseMigrationGenerator(__DIR__ . '/migrations');

$path = $generator->generate('add events index');
// Creates migrations/003_add_events_index.sql (003 = highest existing prefix + 1)
// with a header comment; write your DDL below the header.
```

Описание нормализуется в slug (lowercase, не-alphanumeric последовательности схлопываются в `_`, обрезается по краям). Ширина префикса соответствует самой широкой из существующих и растёт за `999` (`999` → `1000`).

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationState;

$statuses = $runner->status(); // list<ClickHouseMigrationStatus>, sorted by name

foreach ($statuses as $status) {
    // $status->name       — '001_create_events.sql'
    // $status->state      — ClickHouseMigrationState::Applied
    // $status->checksum   — sha1 of the current file (null for Missing)
    // $status->appliedAt  — stored applied_at string (null for Pending)
}
```

| Состояние | Значение |
|---|---|
| `Applied` | Файл существует, контрольная сумма совпадает с сохранённой. |
| `Pending` | Файл существует, ещё не записан в `_migrations`. |
| `Missing` | Записан в `_migrations`, но исходный файл удалён. |
| `Diverged` | Файл существует и был записан, но контрольная сумма больше не совпадает (или записаны конфликтующие контрольные суммы). |

В отличие от `run()`, `status()` никогда не выбрасывает исключение при расхождении — он сообщает об аномалии через состояние `Diverged`.

### Консольные команды

Три команды Symfony Console оборачивают миграционное API для использования в CLI. Они живут в `Rasuvaeff\ClickHouseToolkit\Command` и требуют `symfony/console` (^7.2, указан в `require`).

| Команда | Оборачивает | Описание |
|---|---|---|
| `clickhouse:migrations:generate <description>` | `ClickHouseMigrationGenerator::generate()` | Создаёт `NNN_description.sql` со следующим префиксом. Exit `2` при некорректном описании, `1` при ошибке файловой системы. |
| `clickhouse:migrations:status` | `ClickHouseMigrationRunner::status()` | Печатает таблицу миграций + счётчики состояний. Exit `1`, если есть `Missing` или `Diverged`. |
| `clickhouse:migrations:migrate` | `ClickHouseMigrationRunner::run()` | Применяет ожидающие миграции, по строке на файл. Идемпотентно. |

Зарегистрируйте их в Symfony Console `Application`:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsGenerateCommand;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsRunCommand;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsStatusCommand;
use Symfony\Component\Console\Application;

$application = new Application('clickhouse-migrations');
$application->addCommands([
    new ClickHouseMigrationsGenerateCommand(new ClickHouseMigrationGenerator($migrationsPath)),
    new ClickHouseMigrationsStatusCommand($runner),
    new ClickHouseMigrationsRunCommand($runner),
]);
$application->run();
```

Рабочий пример подключения — в [`examples/console-application.php`](examples/console-application.php).

Чтобы подключить API напрямую в Yii3, Symfony или Laravel (привязка контейнера + своя консольная команда), см. [`examples/framework-integrations.md`](examples/framework-integrations.md).

### `ClickHouseDataType`

Константы имён типов и фабрики, делающие определения типов самодокументируемыми и устойчивыми к опечаткам. Типы — обычные строки, применимые везде, где ожидается тип (`ClickHouseTableBuilder`-столбцы, типы полей `ClickHouseQueryBuilder`).

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

Составные типы (Enum, DateTime с часовым поясом) предназначены для определений столбцов, а не для типов параметров запроса.

### Интерфейсы

| Интерфейс | Метод(ы) | Назначение |
|-----------|-----------|---------|
| `ClickHouseMigrationRunnerInterface` | `run(): list<string>` | Реализован в `ClickHouseMigrationRunner`. |
| `ClickHouseWriterInterface` | `write(iterable $rows): void` | Реализован в `ClickHouseBatchWriter`. |
| `ClickHouseReaderInterface` | `findByFilters(...)`, `countByFilters(...)` | Более простой контракт чтения, чем `DataReaderInterface`; реализуйте его для каждой таблицы, когда полный reader не нужен (см. [`examples/EventReader.php`](examples/EventReader.php)). |
| `ClickHouseFilterVisitor` | `visit*()` для каждого типа фильтра | Генерация SQL для каждого типа фильтра. Реализован в `ClickHouseSqlFilterVisitor`. Внедрите свою реализацию через `withVisitor()`. |

### Работа с часовыми поясами

`ClickHouseQueryBuilder` принимает опциональный `serverTimezone` (IANA-имя, например `"UTC"`, `"Europe/Moscow"`). Если задан, значения фильтров типа `DateTimeInterface` конвертируются в этот часовой пояс перед форматированием в `Y-m-d H:i:s`. Это применяется к фильтрам, значение которых — объект `DateTimeInterface` (`Equals`, сравнения, `Between`); значения `In` — скалярные/строковые и передаются как есть. Без `serverTimezone` используется собственный часовой пояс объекта (обратная совместимость).

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

Fluent: `$qb->withServerTimezone('UTC')` возвращает новый экземпляр.

## Внедрение зависимостей

Подойдёт любой PSR-11-контейнер. Пример с определениями Yiisoft DI (Yii3):

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

См. [`examples/di-container.php`](examples/di-container.php) для рабочего подключения plain-PHP-контейнера.

## Замечания по безопасности

- **Инфорсмент allow-list.** `ClickHouseQueryBuilder` эммитит в `WHERE` и `ORDER BY` только поля из allow-list (каждая запись `allowedFields` валидируется как идентификатор при конструировании). Передавайте пользовательские объекты фильтров/сортировки напрямую — неизвестные поля отбрасываются.
- **Запрещённые пользовательские фильтры молча отбрасываются** (расширяют, а не сужают). Для обязательных tenant/owner/ACL-ограничений **не** полагайтесь на пользовательские фильтры — используйте `withMandatoryFilter()`, который применяется всегда и AND-объединяется, поэтому пользовательский фильтр может только сужать выборку внутри него.
- **Bound-параметры.** Все значения comparison/`In`/`Between`/`Like` передаются как bound-параметры ClickHouse (`{pN:Type}`) с уникальными ключами; значения никогда не конкатенируются в SQL.
- **Экранирование `Like`.** Значения `Like` экранируются по wildcards (`addcslashes($value, '%_\\')`) и биндятся как параметр — кавычка не экранируется (она в параметре, а не в SQL). Пустые значения `Like` отбрасываются. Нестроковые поля сравниваются как `toString(field) LIKE/ILIKE {pN:String}`, поэтому пользовательские фильтры не могут заставить ClickHouse отклонить числовые или date-столбцы.
- **Имена таблиц/столбцов**, передаваемые в `buildSelect`/`buildCount`/`buildDistinct`, а также проекция `columns`, **не** экранируются, но **валидируются** как простые SQL-идентификаторы (`db.table` разрешён); некорректный идентификатор выбрасывает `InvalidArgumentException`. Всё равно передавайте доверенные plain-идентификаторы — валидатор отклоняет сырые выражения (`toDate(x) AS d`), поэтому собирайте их сами.
- **Пагинация.** `buildSelect` отклоняет отрицательные `limit`/`offset` через `InvalidArgumentException`.
- **`orderBy`**, передаваемый в `buildSelect`, и непустой `defaultSort` конструктора — доверенные сырые ORDER BY-фрагменты — **не** валидируются. Используйте вывод `buildOrderBy()` (проверенный по allow-list) или захардкоженную константу; никогда не собирайте их из недоверенного ввода. Значение `defaultSort` по умолчанию пустое, поэтому обобщённые билдеры не предполагают наличия столбца `id`; задайте его явно для стабильной пагинации.
- **Токены типов в `fieldTypes`** валидируются (допуская параметризованные типы вроде `Array(Nullable(String))`), поэтому они не могут выйти за пределы плейсхолдера `{name:Type}`. Это конфигурация разработчика, а не пользовательский ввод.
- **Учётные данные** передаются в заголовках `X-ClickHouse-*`, а не в URL.

## Что намеренно не включено

- Конкретные reader'ы/writer'ы под конкретные таблицы (структуры строк зависят от приложения — используйте `ClickHouseDataReader` с mapper'ом или реализуйте `ClickHouseReaderInterface`).
- Rollback миграций / down-миграции. ClickHouse DDL (`ALTER ... DELETE`) часто необратим, поэтому rollback создаёт ложное чувство безопасности. Используйте forward-fix миграции с идемпотентным DDL.
- Connection pooling или повторы. Внедрите свой PSR-18-клиент (см. [Быстрый старт](#быстрый-старт)), если нужны таймауты, политики повторов или circuit breakers.
- Framework bootloaders/service providers (подключайте в приложении — см. [Внедрение зависимостей](#внедрение-зависимостей)).

## Примеры

Исполняемые самодостаточные примеры лежат в [`examples/`](examples/):

| Файл | Сервер? | Что показывает |
|------|:-------:|-------|
| [`query-builder.php`](examples/query-builder.php) | нет | Каждый поддерживаемый фильтр/сортировку/select/count/distinct — печатает сгенерированный SQL. |
| [`di-container.php`](examples/di-container.php) | нет | Подключение тулкита в PSR-11-контейнер. |
| [`client.php`](examples/client.php) | да | Создание клиента и выполнение запроса. |
| [`run-migrations.php`](examples/run-migrations.php) + [`migrations/`](examples/migrations) | да | Идемпотентное применение миграций `*.sql`. |
| [`generate-migration.php`](examples/generate-migration.php) | нет | Создание нового файла миграции через `ClickHouseMigrationGenerator`. |
| [`migrations-status.php`](examples/migrations-status.php) | да | Отчёт о состоянии миграций через `ClickHouseMigrationRunner::status()`. |
| [`console-application.php`](examples/console-application.php) | да | Подключение трёх команд Symfony Console в `Application`. |
| [`batch-writer.php`](examples/batch-writer.php) | да | Пакетные вставки через `ClickHouseBatchWriter`. |
| [`reader.php`](examples/reader.php) + [`EventReader.php`](examples/EventReader.php) | да | Реализация `ClickHouseReaderInterface` с маппингом строк. |
| [`data-reader.php`](examples/data-reader.php) | да | Иммутабельный `ClickHouseDataReader` (готов для пагинаторов). |

Как их запускать — см. [`examples/README.md`](examples/README.md).

## Разработка

```bash
composer install
composer build       # validate + normalize + require-checker + cs + psalm + testo
composer test        # testo Unit + Integration suites
composer cs:fix      # apply php-cs-fixer
composer psalm       # static analysis (errorLevel=1)
```

Интеграционные тесты в `tests/Integration/` гоняются end-to-end против реального сервера и пропускаются, если не установлена переменная `CLICKHOUSE_HOST`:

```bash
CLICKHOUSE_HOST=127.0.0.1 CLICKHOUSE_PASSWORD=… vendor/bin/testo --suite=Integration
```

CI запускает `composer build` на PHP 8.3, 8.4 и 8.5.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
