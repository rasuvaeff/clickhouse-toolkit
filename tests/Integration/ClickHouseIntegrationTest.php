<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Integration;

use Rasuvaeff\ClickHouseToolkit\Aggregate;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType;
use Rasuvaeff\ClickHouseToolkit\ClickHouseKeysetReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationState;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHousePartitionManager;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseTableBuilder;
use Rasuvaeff\ClickHouseToolkit\ContextClickHouseClient;
use Rasuvaeff\ClickHouseToolkit\Scalar;
use Rasuvaeff\Context\Context;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Exception\ServerError;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\Sort;

#[Test]
#[CoversNothing]
final class ClickHouseIntegrationTest
{
    private const string TABLE = 'it_events';

    private ClickHouseClient $client;

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    #[BeforeTest]
    public function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            return;
        }

        $this->client = (new ClickHouseClientFactory(new ClickHouseConfig(
            host: $host,
            port: (int) $this->env('CLICKHOUSE_PORT', '8123'),
            database: $this->env('CLICKHOUSE_DB', 'default'),
            username: $this->env('CLICKHOUSE_USER', 'default'),
            password: $this->env('CLICKHOUSE_PASSWORD', ''),
        )))->create();

        $this->client->executeQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->client->executeQuery(sprintf(
            'CREATE TABLE %s (id UInt64, status String, name String, created_at DateTime) ENGINE = MergeTree() ORDER BY id',
            self::TABLE,
        ));

        $writer = new ClickHouseBatchWriter($this->client, self::TABLE, ['id', 'status', 'name', 'created_at'], batchSize: 2);
        $writer->write([
            ['id' => 1, 'status' => 'active', 'name' => 'alpha', 'created_at' => '2024-01-01 10:00:00'],
            ['id' => 2, 'status' => 'active', 'name' => 'bravo', 'created_at' => '2024-02-01 10:00:00'],
            ['id' => 3, 'status' => 'inactive', 'name' => 'charlie', 'created_at' => '2024-03-01 10:00:00'],
            ['id' => 4, 'status' => 'active', 'name' => 'delta', 'created_at' => '2024-04-01 10:00:00'],
            ['id' => 5, 'status' => 'inactive', 'name' => 'echo', 'created_at' => '2024-05-01 10:00:00'],
        ]);
    }

    /**
     * @return ClickHouseDataReader<array{id: int, status: string, name: string}>
     */
    private function reader(): ClickHouseDataReader
    {
        $qb = new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status', 'name', 'created_at'],
            fieldTypes: ['id' => 'UInt64', 'created_at' => 'DateTime'],
            defaultSort: 'id ASC',
        );

        return new ClickHouseDataReader(
            client: $this->client,
            table: self::TABLE,
            queryBuilder: $qb,
            mapper: static fn(array $row): array => [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'name' => (string) $row['name'],
            ],
            columns: ['id', 'status', 'name'],
        );
    }

    public function theContextBudgetStopsTheQueryOnTheServer(): void
    {
        if (!isset($this->client)) {
            return;
        }
        // A fractional budget must reach the server as is: rounding it up to
        // a whole second, or down to 0 ("unlimited"), would let this scan run.
        [$context, $controller] = Context::background()->withTimeout(0.3);
        $client = new ContextClickHouseClient($this->client, $context);
        $startedNs = hrtime(as_number: true);

        try {
            $client->select('SELECT count() FROM numbers(1000000000000)', new JsonEachRow());
        } catch (ServerError $error) {
            Assert::string($error->getMessage())->contains('TIMEOUT_EXCEEDED');
            Assert::true(hrtime(as_number: true) - $startedNs < 1_000_000_000);

            return;
        } finally {
            $controller->cancel();
        }

        throw new \LogicException('the server was expected to stop the query');
    }

    public function batchWriterAndCount(): void
    {
        if (!isset($this->client)) {
            return;
        }
        Assert::same($this->reader()->count(), 5);
    }

    public function keysetReaderStreamsAllRowsInKeyOrder(): void
    {
        if (!isset($this->client)) {
            return;
        }

        $reader = new ClickHouseKeysetReader(
            client: $this->client,
            table: self::TABLE,
            queryBuilder: new ClickHouseQueryBuilder(
                allowedFields: ['id', 'status'],
                fieldTypes: ['id' => 'UInt64'],
            ),
            mapper: static fn(array $row): int => (int) $row['id'],
            keyColumns: ['id' => 'UInt64'],
            columns: ['id'],
            pageSize: 2,
        );

        Assert::same(iterator_to_array($reader->stream(), preserve_keys: false), [1, 2, 3, 4, 5]);
    }

    public function keysetReaderHonoursBaseFilter(): void
    {
        if (!isset($this->client)) {
            return;
        }

        $reader = new ClickHouseKeysetReader(
            client: $this->client,
            table: self::TABLE,
            queryBuilder: new ClickHouseQueryBuilder(
                allowedFields: ['id', 'status'],
                fieldTypes: ['id' => 'UInt64'],
            ),
            mapper: static fn(array $row): int => (int) $row['id'],
            keyColumns: ['id' => 'UInt64'],
            columns: ['id'],
            pageSize: 2,
            filter: new Equals('status', 'active'),
        );

        Assert::same(iterator_to_array($reader->stream(), preserve_keys: false), [1, 2, 4]);
    }

    public function batchWriterAppliesAsyncInsertSettings(): void
    {
        if (!isset($this->client)) {
            return;
        }

        $this->client->executeQuery('DROP TABLE IF EXISTS it_async');
        $this->client->executeQuery('CREATE TABLE it_async (id UInt64) ENGINE = MergeTree() ORDER BY id');

        // async_insert + a synchronous wait proves the settings reach a live
        // server and the rows are durably written before the assertion runs.
        (new ClickHouseBatchWriter(
            $this->client,
            'it_async',
            ['id'],
            settings: ['async_insert' => 1, 'wait_for_async_insert' => 1],
        ))->write([['id' => 1], ['id' => 2], ['id' => 3]]);

        $output = $this->client->select('SELECT count() AS cnt FROM it_async', new JsonEachRow());
        /** @var list<array{cnt: int|string}> $data */
        $data = $output->data;

        Assert::same((int) $data[0]['cnt'], 3);
    }

    public function inFilterBindsParameters(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $reader = $this->reader()->withFilter(new In('id', [2, 4]));

        Assert::same($reader->count(), 2);
        Assert::same(array_column($reader->read(), 'id'), [2, 4]);
    }

    public function betweenFilter(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $reader = $this->reader()->withFilter(new Between('id', 2, 4));

        Assert::same(array_column($reader->read(), 'id'), [2, 3, 4]);
    }

    public function equalsAndGreaterThan(): void
    {
        if (!isset($this->client)) {
            return;
        }
        Assert::same($this->reader()->withFilter(new Equals('status', 'active'))->count(), 3);
        Assert::same(array_column($this->reader()->withFilter(new GreaterThan('id', 3))->read(), 'id'), [4, 5]);
    }

    public function ilikeMatchesCaseInsensitively(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $reader = $this->reader()->withFilter(new Like('name', 'A'));

        Assert::same(array_column($reader->read(), 'name'), ['alpha', 'bravo', 'charlie', 'delta']);
    }

    public function ilikeCastsNumericFieldsToString(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $reader = $this->reader()->withFilter(new Like('id', '1'));

        Assert::same(array_column($reader->read(), 'id'), [1]);
    }

    public function paginationLimitsAndOffsets(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $page = $this->reader()->withLimit(2)->withOffset(2)->read();

        Assert::same(array_column($page, 'id'), [3, 4]);
    }

    public function mandatoryAndRawFiltersExecute(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $qb = (new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status'],
            fieldTypes: ['id' => 'UInt64'],
            defaultSort: 'id ASC',
        ))->withMandatoryFilter(new Equals('status', 'active'));

        $where = $qb->buildWhere(new In('id', [1, 2, 3]));
        $sql = $qb->buildSelect(table: self::TABLE, columns: ['id'], where: $where->sql, limit: 100);
        $rows = $this->client->selectWithParams($sql, $where->params, new JsonEachRow())->data;
        Assert::same(array_map(static fn(array $r): int => (int) $r['id'], $rows), [1, 2]);

        $raw = $qb->buildWhere(new ClickHouseRawFilter('id >= {min:UInt64}', ['min' => 2]));
        $sql2 = $qb->buildSelect(table: self::TABLE, columns: ['id'], where: $raw->sql, limit: 100);
        $rows2 = $this->client->selectWithParams($sql2, $raw->params, new JsonEachRow())->data;
        Assert::same(array_map(static fn(array $r): int => (int) $r['id'], $rows2), [2, 4]);
    }

    /**
     * Renamed placeholders must bind on a live server (#34): the rewritten
     * `{v_0:UInt64}` token and the `v_0` HTTP parameter have to agree.
     */
    public function rawFiltersSharingAParameterNameBothBind(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $qb = (new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status'],
            fieldTypes: ['id' => 'UInt64'],
            defaultSort: 'id ASC',
        ))->withMandatoryFilter(new ClickHouseRawFilter('id <= {v:UInt64}', ['v' => 4]));

        $where = $qb->buildWhere(new AndX(
            new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
            new OrX(
                new ClickHouseRawFilter('id = {v:UInt64}', ['v' => 2]),
                new ClickHouseRawFilter('id BETWEEN {v:UInt64} AND {v_max:UInt64}', ['v' => 3, 'v_max' => 4]),
            ),
            new Equals('id', 2),
        ));
        $sql = $qb->buildSelect(table: self::TABLE, columns: ['id'], where: $where->sql, limit: 100);
        $rows = $this->client->selectWithParams($sql, $where->params, new JsonEachRow())->data;

        Assert::same(array_map(static fn(array $r): int => (int) $r['id'], $rows), [2]);
    }

    public function dataTypeFactoriesProduceValidColumnTypes(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->client->executeQuery('DROP TABLE IF EXISTS it_types');

        ClickHouseTableBuilder::create($this->client, 'it_types')
            ->column('id', ClickHouseDataType::UInt64)
            ->column('name', ClickHouseDataType::nullable(ClickHouseDataType::String))
            ->column('tags', ClickHouseDataType::array(ClickHouseDataType::String))
            ->column('ts', ClickHouseDataType::dateTime64(3))
            ->column('status', ClickHouseDataType::enum8(['active' => 1, 'inactive' => 2]))
            ->column('price', ClickHouseDataType::decimal(10, 2))
            ->engine('MergeTree()')
            ->orderBy('id')
            ->execute();

        Assert::same($this->countRows('it_types'), 0);
    }

    public function tableBuilderCreatesUsableTable(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->client->executeQuery('DROP TABLE IF EXISTS it_built');

        ClickHouseTableBuilder::create($this->client, 'it_built')
            ->ifNotExists()
            ->column('id', 'UInt64')
            ->column('name', 'String')
            ->engine('MergeTree()')
            ->partitionBy('id % 2')
            ->orderBy('id')
            ->execute();

        (new ClickHouseBatchWriter($this->client, 'it_built', ['id', 'name']))
            ->write([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

        $output = $this->client->select('SELECT count() AS cnt FROM it_built', new JsonEachRow());

        Assert::same((int) ($output->data[0]['cnt'] ?? 0), 2);
    }

    public function partitionManagerListsDropsDetachesAttaches(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->client->executeQuery('DROP TABLE IF EXISTS it_parts');
        $this->client->executeQuery('CREATE TABLE it_parts (id UInt64, p UInt8) ENGINE = MergeTree() PARTITION BY p ORDER BY id');
        (new ClickHouseBatchWriter($this->client, 'it_parts', ['id', 'p']))->write([
            ['id' => 1, 'p' => 0], ['id' => 2, 'p' => 1], ['id' => 3, 'p' => 0], ['id' => 4, 'p' => 1],
        ]);

        $manager = new ClickHousePartitionManager($this->client);

        Assert::same(count($manager->getPartitions('it_parts')), 2);

        $manager->dropPartition('it_parts', '0');
        Assert::same($this->countRows('it_parts'), 2);

        $manager->detachPartition('it_parts', '1');
        Assert::same($this->countRows('it_parts'), 0);
        $manager->attachPartition('it_parts', '1');
        Assert::same($this->countRows('it_parts'), 2);
    }

    private function countRows(string $table): int
    {
        $output = $this->client->select(sprintf('SELECT count() AS cnt FROM %s', $table), new JsonEachRow());

        return (int) ($output->data[0]['cnt'] ?? 0);
    }

    public function mutationBuilderUpdatesDeletesAndTracks(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $mb = new ClickHouseMutationBuilder($this->client);

        $mb->update(self::TABLE, 'status = {st:String}', 'id = {id:UInt64}', ['st' => 'active', 'id' => 3]);
        Assert::true($mb->waitForMutations(self::TABLE, 10.0));
        $active = $this->client->selectWithParams(
            sprintf('SELECT count() AS cnt FROM %s WHERE status = {s:String}', self::TABLE),
            ['s' => 'active'],
            new JsonEachRow(),
        );
        Assert::same((int) ($active->data[0]['cnt'] ?? 0), 4);

        $mb->delete(self::TABLE, 'id = {id:UInt64}', ['id' => 5]);
        Assert::true($mb->waitForMutations(self::TABLE, 10.0));
        Assert::same($this->countRows(self::TABLE), 4);

        $mutations = $mb->getMutations(self::TABLE);
        Assert::true(count($mutations) >= 2);
        foreach ($mutations as $m) {
            Assert::true($m['is_done']);
        }

        $mb->killMutation(self::TABLE, 'nonexistent-mutation-id');
    }

    public function batchWriterWritesToDbQualifiedTable(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->client->executeQuery('CREATE DATABASE IF NOT EXISTS it_analytics');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_analytics.qualified');
        $this->client->executeQuery('CREATE TABLE it_analytics.qualified (id UInt64) ENGINE = MergeTree() ORDER BY id');

        (new ClickHouseBatchWriter($this->client, 'it_analytics.qualified', ['id']))
            ->write([['id' => 1], ['id' => 2], ['id' => 3]]);

        $output = $this->client->select('SELECT count() AS cnt FROM it_analytics.qualified', new JsonEachRow());

        Assert::same((int) ($output->data[0]['cnt'] ?? 0), 3);
    }

    public function migrationRunnerAppliesAndIsIdempotent(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $pid = getmypid();
        $dir = sys_get_temp_dir() . '/ch_it_migrations_' . ($pid === false ? '0' : (string) $pid);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, recursive: true);
        }
        file_put_contents(
            $dir . '/001_create_it_migr.sql',
            'CREATE TABLE IF NOT EXISTS it_migr_demo (id UInt64) ENGINE = MergeTree() ORDER BY id',
        );

        $this->client->executeQuery('DROP TABLE IF EXISTS _migrations');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_migr_demo');

        $runner = new ClickHouseMigrationRunner($this->client, $dir);

        Assert::same($runner->run(), ['001_create_it_migr.sql']);
        Assert::same($runner->run(), []);

        unlink($dir . '/001_create_it_migr.sql');
        rmdir($dir);
    }

    /**
     * The bookkeeping table name is interpolated into DDL, into both aggregate
     * reads and into the INSERT target — positions that only a live server can
     * confirm (`DateTime64(6)`, `now64(6)`, `argMax`/`uniqExact`, and simpod's
     * own identifier quoting on insert).
     */
    public function migrationRunnerHonoursACustomBookkeepingTable(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $pid = getmypid();
        $dir = sys_get_temp_dir() . '/ch_it_migrations_custom_' . ($pid === false ? '0' : (string) $pid);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, recursive: true);
        }
        file_put_contents(
            $dir . '/001_create_it_custom.sql',
            'CREATE TABLE IF NOT EXISTS it_custom_demo (id UInt64) ENGINE = MergeTree() ORDER BY id',
        );

        $this->client->executeQuery('DROP TABLE IF EXISTS it_bookkeeping');
        $this->client->executeQuery('DROP TABLE IF EXISTS _migrations');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_custom_demo');

        $runner = new ClickHouseMigrationRunner(
            $this->client,
            $dir,
            migrationsTable: 'it_bookkeeping',
        );

        Assert::same($runner->run(), ['001_create_it_custom.sql']);
        Assert::same($runner->run(), []);

        $statuses = $runner->status();
        Assert::same(count($statuses), 1);
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Applied);

        $recorded = $this->client->select(
            'SELECT count() AS cnt FROM it_bookkeeping',
            new JsonEachRow(),
        );
        Assert::same((int) ($recorded->data[0]['cnt'] ?? 0), 1);

        // Nothing was written to the default name.
        $leftover = $this->client->select(
            "SELECT count() AS cnt FROM system.tables WHERE database = currentDatabase() AND name = '_migrations'",
            new JsonEachRow(),
        );
        Assert::same((int) ($leftover->data[0]['cnt'] ?? 0), 0);

        $this->client->executeQuery('DROP TABLE IF EXISTS it_bookkeeping');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_custom_demo');

        unlink($dir . '/001_create_it_custom.sql');
        rmdir($dir);
    }

    private const string RESULTS_TABLE = 'it_order_results';

    /**
     * A ReplacingMergeTree with a superseded row: order 1 was refunded after
     * being paid, and only `updated_at` — the version column — separates the
     * two. Without FINAL a totals read counts both.
     */
    private function orderResults(): void
    {
        $this->client->executeQuery('DROP TABLE IF EXISTS ' . self::RESULTS_TABLE);
        $this->client->executeQuery(sprintf(
            'CREATE TABLE %s (order_id UInt64, status String, amount_cents UInt64, occurred_at DateTime, updated_at DateTime) '
            . 'ENGINE = ReplacingMergeTree(updated_at) ORDER BY order_id',
            self::RESULTS_TABLE,
        ));

        (new ClickHouseBatchWriter($this->client, self::RESULTS_TABLE, ['order_id', 'status', 'amount_cents', 'occurred_at', 'updated_at'], batchSize: 10))
            ->write([
                ['order_id' => 1, 'status' => 'paid', 'amount_cents' => 1000, 'occurred_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'],
                ['order_id' => 2, 'status' => 'paid', 'amount_cents' => 2000, 'occurred_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00'],
                ['order_id' => 3, 'status' => 'paid', 'amount_cents' => 500, 'occurred_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:00:00'],
                ['order_id' => 1, 'status' => 'refunded', 'amount_cents' => 1000, 'occurred_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-04 10:00:00'],
            ]);
    }

    /**
     * The exact statement shape the sandbox hand-wrote before #45: columns,
     * aggregates, FINAL and GROUP BY, with every value bound.
     */
    public function selectBuilderTotalsByStatusFinalDeduplicates(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->orderResults();

        $query = (new ClickHouseQueryBuilder(allowedFields: ['status'], fieldTypes: ['status' => 'String']))
            ->select(table: self::RESULTS_TABLE)
            ->final()
            ->columns('status')
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'))
            ->groupBy('status')
            ->orderBy(Sort::only(['status'])->withOrder(['status' => 'asc']))
            ->build();

        $rows = $this->client->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        // JsonEachRow hands UInt64 back as strings; the row shape is asserted
        // field by field so the cast is visible where it happens.
        Assert::same(count($rows), 2);
        Assert::same([(string) $rows[0]['status'], (int) $rows[0]['orders'], (int) $rows[0]['amount_cents']], ['paid', 2, 2500]);
        Assert::same([(string) $rows[1]['status'], (int) $rows[1]['orders'], (int) $rows[1]['amount_cents']], ['refunded', 1, 1000]);
    }

    public function selectBuilderConditionalAggregateBindsItsValue(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->orderResults();

        $query = (new ClickHouseQueryBuilder(allowedFields: ['status'], fieldTypes: ['status' => 'String']))
            ->select(table: self::RESULTS_TABLE)
            ->final()
            ->aggregate(Aggregate::countIf(condition: new Equals('status', 'paid'), alias: 'paid_orders'))
            ->build();

        $rows = $this->client->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        Assert::same((int) ($rows[0]['paid_orders'] ?? -1), 2);
    }

    public function selectBuilderLatestReadBindsTheLimit(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->orderResults();

        $query = (new ClickHouseQueryBuilder(allowedFields: []))
            ->select(table: self::RESULTS_TABLE)
            ->final()
            ->columns('order_id')
            ->scalar(Scalar::toString(column: 'occurred_at', alias: 'occurred_at'))
            ->orderBy(Sort::only(['occurred_at'])->withOrder(['occurred_at' => 'desc']))
            ->limit(2)
            ->build();

        $rows = $this->client->selectWithParams($query->sql, $query->params, new JsonEachRow())->data;

        Assert::same(count($rows), 2);
        Assert::same((int) $rows[0]['order_id'], 3);
        Assert::same((string) $rows[0]['occurred_at'], '2026-01-03 10:00:00');
    }

    public function selectBuilderPagesThroughPlaceholders(): void
    {
        if (!isset($this->client)) {
            return;
        }
        $this->orderResults();

        $builder = new ClickHouseQueryBuilder(allowedFields: []);
        $first = $builder->select(table: self::RESULTS_TABLE)
            ->final()
            ->columns('order_id')
            ->orderBy(Sort::only(['order_id'])->withOrder(['order_id' => 'asc']))
            ->limit(2)
            ->offset(0)
            ->build();
        $second = $builder->select(table: self::RESULTS_TABLE)
            ->final()
            ->columns('order_id')
            ->orderBy(Sort::only(['order_id'])->withOrder(['order_id' => 'asc']))
            ->limit(2)
            ->offset(2)
            ->build();

        $page1 = $this->client->selectWithParams($first->sql, $first->params, new JsonEachRow())->data;
        $page2 = $this->client->selectWithParams($second->sql, $second->params, new JsonEachRow())->data;

        Assert::same(array_map(static fn(array $row): int => (int) $row['order_id'], $page1), [1, 2]);
        Assert::same(array_map(static fn(array $row): int => (int) $row['order_id'], $page2), [3]);
    }
}
