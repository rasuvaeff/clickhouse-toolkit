# Инструментарий ClickHouse
[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/v)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/clickhouse-toolkit/downloads)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![Build](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/clickhouse-toolkit/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/clickhouse-toolkit/php)](https://packagist.org/packages/rasuvaeff/clickhouse-toolkit)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

 Легкие, не зависящие от платформы помощники ClickHouse для PHP-приложений. @@ЛИНИЯ@@
```php
$qb = new ClickHouseQueryBuilder(allowedFields: ['id', 'status'], fieldTypes: ['id' => T::UInt64]);
$where = $qb->buildWhere(new Equals('status', 'active'));
$sql = $qb->buildSelect(table: 'events', where: $where->sql, limit: 20);
```
- **`ClickHouseClientFactory`** + **`ClickHouseConfig`** — создайте настроенный клиент на основе любого HTTP-клиента PSR-18 (автоматически обнаруженного или внедренного; HTTP/HTTPS).
- **`ClickHouseQueryBuilder`** — turn [`yiisoft/data`](https://github.com/yiisoft/data) filters and sort into safe, parameterized SQL.
- **`ClickHouseFilterVisitor`** + **`ClickHouseSqlFilterVisitor`** — расширяемый посетитель для генерации SQL для каждого типа фильтра.
 - **`ClickHouseDataReader`** — неизменяемый `DataReaderInterface`, готовый для yiisoft/data paginators.
 - **`ClickHouseKeysetReader`** — потоковая передача больших наборов результатов в ограниченную память посредством разбиения на страницы набора ключей.
 - **`ClickHouseBatchWriter`** — буферизованные, пакетные вставки.
 - **`ClickHouseTableBuilder`** — свободный DDL для `CREATE TABLE`.
 - **`ClickHousePartitionManager`** — перечислить/удалить/отсоединить/присоединить/переместить/заморозить разделы.
 - **`ClickHouseMutationBuilder`** — асинхронный `ALTER… UPDATE/DELETE` с отслеживанием мутаций.
 - **`ClickHouseMigrationRunner`** — идемпотентные миграции `*.sql` с проверкой контрольной суммы.
 - **`ClickHouseMigrationGenerator`** — создает новые файлы миграции с автоматически увеличивающимися числовыми префиксами.
 - **`ClickHouseDataType`** — константы имен типов и фабрики для параметрических/вложенных типов. @@ЛИНИЯ@@
Built on top of [`simpod/clickhouse-client`](https://github.com/simPod/clickhouse-client). The query/reader pieces integrate with the `yiisoft/data` reader abstractions, so they slot naturally into Yii3 admin grids and paginated APIs, but nothing here requires the full framework.
> **Используете помощника по кодированию с помощью искусственного интеллекта?** [`llms.txt`](llms.txt) — это компактный
 > автономный справочник по всему общедоступному API плюс рецепты копирования и вставки —
 > поместите его в контекст модели. Авторы: см. [`AGENTS.md`](AGENTS.md). @@ЛИНИЯ@@
## Оглавление
- [Требования](#требования)
 - [Установка](#установка)
 - [Быстрый старт](#быстрый старт)
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
 - [Консольные команды](#console-commands)
 - [Интерфейсы](#interfaces)
 - [Часовой пояс обработка](#timezone-handling)
 - [Внедрение зависимостей](#dependent-injection)
 - [Примечания по безопасности](#security-notes)
 - [Что намеренно не включено](#что-намеренно-не включено)
 - [Примеры](#examples)
 - [Разработка](#development)
 - [Лицензия](#лицензия)

## Требования
| Требование | Версия |
 |-------------|---------|
 | PHP | `^8.3` |
 | HTTP-клиент PSR-18 + фабрики PSR-17 | любая реализация |
 | Сервер ClickHouse | протестировано на версиях 23.x – 26.x через интерфейс HTTP (порт `8123`) | @@ЛИНИЯ@@
The toolkit depends only on interfaces (`psr/http-client`, `psr/http-factory`, `psr/log`, `php-http/discovery`, `simpod/clickhouse-client`, `yiisoft/data`) — **not** on any concrete HTTP client. It auto-discovers an installed PSR-18 client/PSR-17 factories via [php-http/discovery](https://docs.php-http.org/en/latest/discovery.html), or you can inject your own.
## Установка
```bash
composer require rasuvaeff/clickhouse-toolkit
```
Вам также понадобится клиент PSR-18 и фабрики PSR-17, если ваш проект еще не поставляется, например:

```bash
composer require guzzlehttp/guzzle
# or: composer require symfony/http-client nyholm/psr7
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
### «ClickHouseConfig» и «ClickHouseClientFactory»
ClickHouseConfig содержит настройки соединения; ClickHouseClientFactory превращает его в SimPod\ClickHouseClient\Client\PsrClickHouseClient. HTTP-клиент и фабрики PSR-17 обнаруживаются автоматически (или внедряются). Конечная точка — это абсолютный URI, созданный на основе конфигурации; аутентификация и база данных передаются через заголовки X-ClickHouse-* (декоратор AuthenticatingHttpClient), поэтому учетные данные никогда не появляются в URL-адресе. @@ЛИНИЯ@@
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
Чтобы управлять **таймаутами, повторными попытками или TLS**, создайте свой собственный клиент PSR-18 и внедрите его (вместе с нужными вам фабриками PSR-17):

```php
use GuzzleHttp\Client;

$factory = new ClickHouseClientFactory(
    config: new ClickHouseConfig(host: 'ch.internal', secure: true),
    httpClient: new Client(['timeout' => 10.0]),
    // requestFactory / streamFactory / uriFactory are optional (auto-discovered when null)
);
```
### «ClickHouseQueryBuilder» и «WhereClause»
Преобразует фильтры `yiisoft/data` и сортирует их в параметризованный ClickHouse SQL. Построитель является границей безопасности: **только поля, присутствующие в `allowedFields`, выдаются** в `WHERE` и `ORDER BY`; все остальное молча отбрасывается. Значения сравнения становятся **связанными параметрами с уникальными ключами** (`p0`, `p1`, …), поэтому одно и то же поле может появляться несколько раз без конфликтов. @@ЛИНИЯ@@
```php
public function __construct(
    private array $allowedFields,            // list<string>
    private array $fieldTypes = [],          // field => ClickHouse type, default "String" (use ClickHouseDataType constants)
    private string $defaultSort = '', // no ORDER BY by default; pass e.g. 'id DESC' for stable pagination
    private ?FilterInterface $mandatoryFilter = null,
    private ?string $serverTimezone = null,  // IANA timezone; DateTime values are converted before formatting
) {}
```
| Метод | Возврат | Описание |
 |--------|---------|-------------|
 | `buildWhere(FilterInterface $filter)` | `WhereClause` | `{sql, параметры}`; `sql` пуст, если ничего не найдено. |
 | `buildOrderBy(?Sort $sort)` | `строка` | Фрагмент ORDER BY (проверенный список разрешений) или `defaultSort`; пустая строка означает отсутствие `ORDER BY`. |
 | `buildSelect(строка $table, массив $columns = [], строка $where = '', ?string $orderBy = null, ?int $limit = 20, int $offset = 0)` | `строка` | `columns` пусто → `SELECT *`; пустой заказ → нет `ORDER BY`; `limit` ноль → нет LIMIT/OFFSET. |
 | `buildCount(строка $table, строка $where = '')` | `строка` | `SELECT count() AS cnt FROM ...`. |
 | `buildDistinct(строка $table, строка $column)` | `строка` | `ВЫБРАТЬ ОТЛИЧНЫЙ столбец ИЗ... ЗАКАЗАТЬ ПО столбцу`. |

 `WhereClause` — это небольшой DTO: `публичная строка $sql`, `публичный массив $params` и `isEmpty(): bool`.

 **Поддерживаемые фильтры**

 | фильтр `yiisoft/data` | Отображается как | Заметки |
 |-----------------------|-------------|-------|
 | `Все` | пустое `ГДЕ` | |
 | `Нет` | `0` | ничего не соответствует |
 | `Равно` | `поле = {p0:Type}` | |
 | `GreaterThan` / `GreaterThanOrEqual` | `поле > / >= {p0:Type}` | |
 | `LessThan` / `LessThanOrEqual` | `поле < / <= {p0:Type}` | |
 | `EqualsNull` | `поле НУЛЕВОЕ` | нет параметров |
 | `В` | `поле IN ({p0:Type}, {p1:Type}, …)` | пустые значения → `0` (ничего не соответствует) |
 | `Между` | `поле МЕЖДУ {p0:Type} И {p1:Type}` | |
 | `Мне нравится` | `field ILIKE {p0:String}` (или `LIKE`, если `caseSensitive`) | нестроковые поля заключаются в `toString(field)`; пустые значения удаляются; привязка значения + экранирование подстановочными знаками; награждает LikeMode содержит/StartsWith/EndsWith |
 | `Не` | `НЕ (...)` | сбрасывается, если внутренний фильтр пуст |
 | `AndX` / `OrX` | `(a И/ИЛИ b …)` | пустые подфильтры пропускаются |

 Значения `DateTimeInterface` нормализуются к `Y-m-d H:i:s`; `bool` на `0/1`.

 **Обязательные фильтры (арендатор/владелец/ACL)**

 Конструктор свободно говорит и неизменен. `withMandatoryFilter()` присоединяет всегда применяемый фильтр
, который **объединен** с пользовательским фильтром и **обходит
 список разрешений** (его поля не обязательно должны находиться в `allowedFields`; идентификаторы по-прежнему проверяются
). Это безопасный способ обеспечить соблюдение ограничений доступа — пользовательский фильтр
 может только сужаться внутри него. @@ЛИНИЯ@@
```php
$qb = ClickHouseQueryBuilder::create(['id', 'status'], ['id' => T::UInt64])
    ->withMandatoryFilter(new Equals('tenant_id', $tenantId));

$where = $qb->buildWhere($userFilter); // (tenant_id = {p0:...}) AND (<user filter>)
```
**Необработанные выражения**

 `ClickHouseRawFilter` — это `FilterInterface`, который генерирует необработанный фрагмент SQL для вещей
, которые типизированные фильтры не могут выразить. SQL-коду доверяют (никогда не на основе пользовательского ввода); значения
 помещаются в `$params` с использованием заполнителей `{name:Type}`, имена которых не должны совпадать с автоматическими ключами компоновщика
 (`p0`, `p1`, …). @@ЛИНИЯ@@
```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;

$where = $qb->buildWhere(new ClickHouseRawFilter('toDate(created_at) = {d:Date}', ['d' => '2024-01-01']));
```
**Полный цикл чтения + подсчет**

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
Построитель запросов делегирует создание SQL посетителю. «ClickHouseFilterVisitor» — это интерфейс с методом «visit*()» для каждого типа фильтра; ClickHouseSqlFilterVisitor — реализация по умолчанию. Используйте `dispatch(FilterInterface $filter, int &$index, bool $trusted)`, чтобы направить любой фильтр к нужному методу.

 Реализуйте ClickHouseFilterVisitor и внедрите его через withVisitor(), чтобы настроить генерацию SQL:

```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;

$qb = ClickHouseQueryBuilder::create(['id'], ['id' => 'UInt64'])
    ->withVisitor(new MyCustomVisitor());
```
### `ClickHouseDataReader`
Неизменяемый `Yiisoft\Data\Reader\DataReaderInterface`, поддерживаемый таблицей ClickHouse. Фильтрация, сортировка и нумерация страниц делегируются построителю запросов; строки сопоставляются с вашим типом значения с помощью предоставленного преобразователя. Он подключается прямо к пагинаторам yiisoft/data («OffsetPaginator», «KeysetPaginator»). @@ЛИНИЯ@@
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
Реализует `read()`, `readOne()`, `count()`, `getIterator()` и неизменяемый `withFilter/withSort/withLimit/withOffset` (+ геттеры). Если ограничение не установлено, `read()` опускает `LIMIT` и возвращает полный результат.

 > `read()`/`getIterator()` материализуют весь результат в памяти. Чтобы перебрать большой набор результатов с ограниченной памятью, используйте ClickHouseKeysetReader ниже. @@ЛИНИЯ@@
### `ClickHouseKeysetReader`
Передаёт большой набор результатов с **ограниченной памятью**, используя разбиение на страницы (поиск). Каждая страница представляет собой обычный запрос — `WHERE <key> > <last-seen> ORDER BY <key> LIMIT <pageSize>`, поэтому весь результат никогда не загружается сразу и, в отличие от `LIMIT/OFFSET`, глубокие страницы остаются дешевыми (сканирование диапазона первичного индекса вместо пропуска строк). Обязательный фильтр (клиент/ACL) построителя запросов и список разрешений применяются на каждой странице. @@ЛИНИЯ@@
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
Ключевые столбцы **должны образовывать уникальный общий порядок по возрастанию** — для столбца с неуникальной сортировкой добавьте уникальный ограничитель связей, выраженный в виде кортежа столбцов по сравнению со сравнением кортежей ClickHouse:

```php
keyColumns: ['created_at' => T::DateTime, 'id' => T::UInt64],
// boundary: (created_at, id) > ({ck0:DateTime}, {ck1:UInt64})
```
В противном случае строки, имеющие общий ключ границы, могут быть пропущены. Ключевые столбцы не должны иметь значение NULL. Граничные параметры используют зарезервированные имена «ck0», «ck1»,… — не допускайте использования в них любого «ClickHouseRawFilter» в базовом фильтре. @@ЛИНИЯ@@
### `ClickHouseBatchWriter`
Буферизирует строки и вставляет их пакетами фиксированного размера. Каждая строка проецируется на объявленные столбцы (лишние ключи удалены, отсутствующие ключи → `null`), поэтому ассоциативные строки свободной формы вполне подходят. Сбои оборачиваются ClickHouseWriteException. @@ЛИНИЯ@@
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
Реализует ClickHouseWriterInterface (write(iterable $rows): void`).

 Для получения высокой пропускной способности передайте `настройки` запроса ClickHouse — они
 применяются к каждому пакету `INSERT`. Например, выгрузить буферизацию на сервер.
with [async inserts](https://clickhouse.com/docs/en/optimize/asynchronous-inserts):
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
Свободный конструктор CREATE TABLE. `build()` возвращает SQL; `execute()` запускает его через
 клиента. Имя таблицы и имена столбцов являются проверенными идентификаторами; типы столбцов
, механизм и выражения ORDER BY / PARTITION BY / PRIMARY KEY
 выдаются дословно — DDL создан разработчиками, поэтому им следует доверять. @@ЛИНИЯ@@
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
`build()`/`execute()` выбрасывается, если не были установлены ни столбцы, ни механизм. @@ЛИНИЯ@@
### `ClickHousePartitionManager`
Управляет разделами MergeTree с помощью ALTER TABLE… PARTITION. Операции
 раздела не могут использовать связанные параметры, поэтому адрес раздела осуществляется по его **id**
 (из `getPartitions()`) и выдается как экранированный `PARTITION ID '…'`; Имена таблиц и столбцов
 являются проверенными идентификаторами. @@ЛИНИЯ@@
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
Отправляет и отслеживает изменения — `ALTER TABLE… UPDATE/DELETE`, единственный способ
 изменить или удалить существующие строки. Мутации асинхронны. Фрагменты `$set` и
 `$condition` являются доверенными (авторизованы разработчиком); передавать пользовательские значения как привязанные
 параметры `{name:Type}` (ClickHouse поддерживает параметры в `{name:Type}`). @@ЛИНИЯ@@
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
Применяет файлы `*.sql` из каталога в порядке имен файлов, записывая каждый примененный файл с **контрольной суммой** в таблице `_migrations`.

 - **Идемпотент** — уже примененные файлы пропускаются.
 - **Tamper-evident** — если содержимое уже примененного файла изменилось, вместо молчаливого отклонения выдается исключение ClickHouseMigrationException.
 - **Один оператор на файл** — содержимое отправляется как один запрос (без примитивного разделения `;`).
 - **Дополнительное ведение журнала PSR-3** — передайте `LoggerInterface` для регистрации примененных/пропущенных файлов. @@ЛИНИЯ@@
```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;

$runner = new ClickHouseMigrationRunner(
    client: $client,
    migrationsPath: __DIR__ . '/migrations',
    logger: $logger, // optional PSR-3
);

$applied = $runner->run(); // list<string> of files applied this call
```
Таблица отслеживания (создается автоматически):

```sql
CREATE TABLE IF NOT EXISTS `_migrations` (
    name String, checksum String, applied_at DateTime64(6) DEFAULT now64(6)
) ENGINE = ReplacingMergeTree(applied_at) ORDER BY name
```
Назовите файлы так, чтобы лексикографический порядок соответствовал порядку выполнения, например. `001_create_events.sql`, `002_add_index.sql`.

 > **Параллелизм и частичный сбой.** В ClickHouse нет транзакций, и средство выполнения не использует распределенную блокировку: считывается список применений, затем каждый файл выполняется и записывается отдельно. Два запуска, запущенных одновременно, могут запустить один и тот же ожидающий файл, и если DDL файла успешен, а вставка `_migrations` — нет, следующий запуск повторяет его. Запускайте миграцию с одного шага развертывания, отдавайте предпочтение идемпотентному DDL («CREATE TABLE IF NOT EXISTS», «ALTER TABLE ... ADD COLUMN IF NOT EXISTS») и оберните «run()» во внешнюю блокировку, если вам нужны более надежные гарантии. @@ЛИНИЯ@@
### `ClickHouseMigrationGenerator` и `status()`
Два помощника завершают рабочий процесс миграции:

 - **`ClickHouseMigrationGenerator`** создает новый файл миграции со следующим последовательным числовым префиксом. Это простой помощник для файловой системы — клиент ClickHouse не требуется.
 - **`ClickHouseMigrationRunner::status()`** сообщает состояние каждого файла миграции относительно таблицы `_migrations`. @@ЛИНИЯ@@
```php
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;

$generator = new ClickHouseMigrationGenerator(__DIR__ . '/migrations');

$path = $generator->generate('add events index');
// Creates migrations/003_add_events_index.sql (003 = highest existing prefix + 1)
// with a header comment; write your DDL below the header.
```
Описание очищается до фрагмента (строчные, небуквенно-цифровые строки сворачиваются до `_`, обрезаются). Ширина префикса соответствует самой широкой из существующих и превышает `999` (`999` → `1000`). @@ЛИНИЯ@@
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
| Государство | Значение |
 |---|---|
 | `Прикладной` | Файл существует, контрольная сумма совпадает с сохраненной. |
 | `В ожидании` | Файл существует, но еще не записан в `_migrations`. |
 | `Пропавший без вести` | Записано в `_migrations`, но исходный файл удален. |
 | `Разошлись` | Файл существует и был записан, но контрольная сумма больше не совпадает (или были записаны конфликтующие контрольные суммы). |

 В отличие от `run()`, `status()` никогда не выдает расхождение — он выявляет аномалию через состояние `Diverged`. @@ЛИНИЯ@@
### Консольные команды
Три команды Symfony Console оборачивают API миграции для использования в CLI. Они живут в каталоге Rasuvaeff\ClickHouseToolkit\Command и требуют Symfony/console (^7.2, перечислены в require).

 | Команда | Обертывания | Описание |
 |---|---|---|
 | `clickhouse:migrations:generate <description>` | `ClickHouseMigrationGenerator::generate()` | Создает NNN_description.sql со следующим префиксом. Выход `2` при неверном описании, `1` при сбое файловой системы. |
 | `clickhouse:migrations:status` | `ClickHouseMigrationRunner::status()` | Печатает таблицу миграций + подсчет состояний. Выйдите из «1», если существуют какие-либо «Отсутствующие» или «Расходящиеся». |
 | `clickhouse:migrations:migrate` | `ClickHouseMigrationRunner::run()` | Применяет ожидающие миграции, по одной строке на файл. Идемпотент. |

 Зарегистрируйте их в своем `приложении` консоли Symfony:

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
Пример работоспособного подключения находится в [`examples/console-application.php`](examples/console-application.php).

 Чтобы подключить API напрямую к Yii3, Symfony или Laravel (привязка контейнера + ваша собственная консольная команда
), см. [`examples/framework-integrations.md`](examples/framework-integrations.md). @@ЛИНИЯ@@
### `ClickHouseDataType`
Константы и фабрики имен типов, поэтому определения типов являются самодокументируемыми и
 защищенными от опечаток. Типы представляют собой простые строки, которые можно использовать везде, где ожидается
 (столбцы ClickHouseTableBuilder, типы полей ClickHouseQueryBuilder). @@ЛИНИЯ@@
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
Составные типы (Enum, DateTime с указанием часового пояса) предназначены для определений столбцов,
, а не типов параметров запроса. @@ЛИНИЯ@@
### Интерфейсы
| Интерфейс | Метод(ы) | Цель |
 |-----------|-----------|---------|
 | `ClickHouseMigrationRunnerInterface` | `run(): список<строка>` | Реализовано ClickHouseMigrationRunner. |
 | `ClickHouseWriterInterface` | `write(iterable $rows): void` | Реализовано ClickHouseBatchWriter. |
 | `ClickHouseReaderInterface` | `findByFilters(...)`, `countByFilters(...)` | Более простой контракт чтения, чем DataReaderInterface; реализуйте его для каждой таблицы, когда вам не нужен полный модуль чтения (см. [`examples/EventReader.php`](examples/EventReader.php)). |
 | `ClickHouseFilterVisitor` | `visit*()` для каждого типа фильтра | Генерация SQL для каждого типа фильтра. Реализовано ClickHouseSqlFilterVisitor. Внедрите пользовательскую реализацию через `withVisitor()`. | @@ЛИНИЯ@@
### Обработка часового пояса
ClickHouseQueryBuilder принимает необязательный serverTimezone (имя IANA, например, UTC, Europe/Moscow). Если этот параметр установлен, значения фильтра DateTimeInterface преобразуются в этот часовой пояс перед форматированием как «Y-m-d H:i:s». Это относится к фильтрам, значением которых является объект DateTimeInterface («Равно», сравнения, «Между»); Значения `In` являются скалярными/строковыми значениями и передаются как указано. Без `serverTimezone` используется собственный часовой пояс объекта (обратная совместимость). @@ЛИНИЯ@@
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
Свободно: `$qb->withServerTimezone('UTC')` возвращает новый экземпляр. @@ЛИНИЯ@@
## Внедрение зависимостей
Любой контейнер ПСР-11 подойдет. Пример использования определений Yiisoft DI (Yii3):

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
См. [`examples/di-container.php`](examples/di-container.php) для работоспособного подключения контейнера на простом PHP. @@ЛИНИЯ@@
## Примечания по безопасности
- **Принудительное использование списка разрешенных.** ClickHouseQueryBuilder создает только поля из разрешенного списка в полях WHERE и ORDER BY (каждая записьallowedFields проверяется как идентификатор при создании). Пропускайте управляемые пользователем объекты фильтрации/сортировки напрямую — неизвестные поля удаляются.
 - **Фильтры запрещенных пользователей удаляются автоматически** (расширяются, а не сужаются). Для обязательных ограничений арендатора/владельца/ACL **не** полагайтесь на пользовательские фильтры — используйте `withMandatoryFilter()`, который всегда применяется и объединяется с помощью AND, поэтому пользовательский фильтр может только сужаться внутри него.
 - **Привязанные параметры.** Все значения сравнения/`In`/`Between`/`Like` передаются как связанные параметры ClickHouse (`{pN:Type}`) с уникальными ключами; значения никогда не объединяются в SQL.
 - **`Like` экранирование.** Значения `Like` экранируются подстановочными знаками (`addcslashes($value, '%_\\')`) и привязываются как параметр — кавычка не экранируется (она находится в параметре, а не в SQL). Пустые значения «Мне нравится» удаляются. Нестроковые поля сравниваются как `toString(field) LIKE/ILIKE {pN:String}`, поэтому пользовательские фильтры не могут заставить ClickHouse отклонять числовые столбцы или столбцы с датами.
 - **имена таблиц/столбцов**, передаваемые в `buildSelect`/`buildCount`/`buildDistinct`, а проекция `columns` **не** экранируется, но они **проверяются** как простые идентификаторы SQL (`db.table` разрешено); неверный идентификатор вызывает исключение InvalidArgumentException. По-прежнему передавайте надежные простые идентификаторы — валидатор отклоняет необработанные выражения («toDate(x) AS d»), поэтому создайте их самостоятельно.
 - **Разбиение на страницы.** `buildSelect` отклоняет отрицательное `limit`/`offset` с `InvalidArgumentException`.
 — **`orderBy`**, передаваемый в `buildSelect`, и непустой `defaultSort` конструктора являются доверенными необработанными фрагментами ORDER BY — **не** проверяемыми. Используйте вывод buildOrderBy() (проверенный списком разрешений) или жестко закодированную константу; никогда не создавайте их на основе ненадежных данных. По умолчанию `defaultSort` пуст, поэтому универсальные компоновщики не предполагают наличие столбца `id`; установите его явно для стабильной нумерации страниц.
 — токены типа **`fieldTypes`** проверяются (разрешая использование параметрических типов, таких как Array(Nullable(String))`), поэтому они не могут выйти за пределы заполнителя `{name:Type}`. Это конфигурация разработчика, а не вводимые пользователем данные.
 — **Учетные данные** передаются в заголовках `X-ClickHouse-*`, а не в URL-адресе. @@ЛИНИЯ@@
## Что намеренно не включено
— Конкретные устройства чтения/записи для конкретных таблиц (формы строк зависят от приложения — используйте ClickHouseDataReader с картографом или реализуйте ClickHouseReaderInterface).
 - Откат миграции/даун-миграции. ClickHouse DDL («ALTER… DELETE») часто необратим, поэтому откат создает ложное чувство безопасности. Вместо этого используйте миграции с упреждающим исправлением с идемпотентным DDL.
 — объединение в пул соединений или повторные попытки. Добавьте свой собственный клиент PSR-18 (см. [Быстрый старт](#quick-start)), если вам нужны таймауты, политики повторных попыток или автоматические выключатели.
 — загрузчики/поставщики услуг платформы (подключите это в своем приложении — см. [Внедрение зависимостей](#dependent-injection)). @@ЛИНИЯ@@
## Примеры
Выполняемые, автономные примеры находятся в [`examples/`](examples/):

 | Файл | Сервер? | Шоу |
 |------|:-------:|-------|
 | [`query-builder.php`](examples/query-builder.php) | нет | Каждый поддерживаемый фильтр/сортировка/выбор/подсчет/различение — печатает сгенерированный SQL. |
 | [`di-container.php`](examples/di-container.php) | нет | Подключение набора инструментов к контейнеру PSR-11. |
 | [`client.php`](examples/client.php) | да | Создание клиента и выполнение запроса. |
 | [`run-migrations.php`](примеры/run-migrations.php) + [`migrations/`](примеры/миграции) | да | Идемпотентное применение миграции `*.sql`. |
 | [`generate-migration.php`](examples/generate-migration.php) | нет | Создание нового файла миграции с помощью ClickHouseMigrationGenerator. |
 | [`migrations-status.php`](examples/migrations-status.php) | да | Отчет о состоянии миграции через ClickHouseMigrationRunner::status(). |
 | [`console-application.php`](examples/console-application.php) | да | Подключение трех консольных команд Symfony к «Приложению». |
 | [`batch-writer.php`](examples/batch-writer.php) | да | Пакетные вставки через ClickHouseBatchWriter. |
 | [`reader.php`](examples/reader.php) + [`EventReader.php`](examples/EventReader.php) | да | Реализация ClickHouseReaderInterface с сопоставлением строк. |
 | [`data-reader.php`](examples/data-reader.php) | да | Неизменяемый ClickHouseDataReader (готов для разбивки на страницы). |

 См. [`examples/README.md`](examples/README.md), чтобы узнать, как их запустить. @@ЛИНИЯ@@
## Разработка
```bash
composer install
composer build       # validate + normalize + require-checker + cs + psalm + testo
composer test        # testo Unit + Integration suites
composer cs:fix      # apply php-cs-fixer
composer psalm       # static analysis (errorLevel=1)
```
Интеграционные тесты в `tests/Integration/` выполняются сквозным образом на реальном сервере и пропускаются, если не установлен `CLICKHOUSE_HOST`:

```bash
CLICKHOUSE_HOST=127.0.0.1 CLICKHOUSE_PASSWORD=… vendor/bin/testo --suite=Integration
```
CI запускает `composer build` на PHP 8.3, 8.4 и 8.5. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
