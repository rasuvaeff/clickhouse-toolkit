<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseClientFactory;
use Rasuvaeff\ClickHouseToolkit\ClickHouseConfig;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHousePartitionManager;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseTableBuilder;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Format\JsonEachRow;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\Like;

/**
 * End-to-end tests against a real ClickHouse server. Skipped unless
 * CLICKHOUSE_HOST is set. Verifies that parameter binding, IN, BETWEEN and
 * (I)LIKE actually execute on the server — string-comparison unit tests cannot.
 */
#[CoversNothing]
final class ClickHouseIntegrationTest extends TestCase
{
    private const string TABLE = 'it_events';

    private ClickHouseClient $client;

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    #[\Override]
    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('CLICKHOUSE_HOST is not set; skipping integration tests.');
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

    #[Test]
    public function batchWriterAndCount(): void
    {
        $this->assertSame(5, $this->reader()->count());
    }

    #[Test]
    public function inFilterBindsParameters(): void
    {
        $reader = $this->reader()->withFilter(new In('id', [2, 4]));

        $this->assertSame(2, $reader->count());
        $this->assertSame([2, 4], array_column($reader->read(), 'id'));
    }

    #[Test]
    public function betweenFilter(): void
    {
        $reader = $this->reader()->withFilter(new Between('id', 2, 4));

        $this->assertSame([2, 3, 4], array_column($reader->read(), 'id'));
    }

    #[Test]
    public function equalsAndGreaterThan(): void
    {
        $this->assertSame(3, $this->reader()->withFilter(new Equals('status', 'active'))->count());
        $this->assertSame([4, 5], array_column($this->reader()->withFilter(new GreaterThan('id', 3))->read(), 'id'));
    }

    #[Test]
    public function ilikeMatchesCaseInsensitively(): void
    {
        // 'A' contains-match, case-insensitive -> alpha, bravo, charlie, delta (echo has no 'a').
        $reader = $this->reader()->withFilter(new Like('name', 'A'));

        $this->assertSame(['alpha', 'bravo', 'charlie', 'delta'], array_column($reader->read(), 'name'));
    }

    #[Test]
    public function ilikeCastsNumericFieldsToString(): void
    {
        $reader = $this->reader()->withFilter(new Like('id', '1'));

        $this->assertSame([1], array_column($reader->read(), 'id'));
    }

    #[Test]
    public function paginationLimitsAndOffsets(): void
    {
        $page = $this->reader()->withLimit(2)->withOffset(2)->read();

        $this->assertSame([3, 4], array_column($page, 'id'));
    }

    #[Test]
    public function mandatoryAndRawFiltersExecute(): void
    {
        $qb = (new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status'],
            fieldTypes: ['id' => 'UInt64'],
            defaultSort: 'id ASC',
        ))->withMandatoryFilter(new Equals('status', 'active'));

        // mandatory(status=active) AND user(id IN 1,2,3) -> 1,2 (3 is inactive).
        $where = $qb->buildWhere(new In('id', [1, 2, 3]));
        $sql = $qb->buildSelect(table: self::TABLE, columns: ['id'], where: $where->sql, limit: 100);
        $rows = $this->client->selectWithParams($sql, $where->params, new JsonEachRow())->data;
        $this->assertSame([1, 2], array_map(static fn(array $r): int => (int) $r['id'], $rows));

        // mandatory still applies under a raw filter: status=active AND id>=2 -> 2,4.
        $raw = $qb->buildWhere(new ClickHouseRawFilter('id >= {min:UInt64}', ['min' => 2]));
        $sql2 = $qb->buildSelect(table: self::TABLE, columns: ['id'], where: $raw->sql, limit: 100);
        $rows2 = $this->client->selectWithParams($sql2, $raw->params, new JsonEachRow())->data;
        $this->assertSame([2, 4], array_map(static fn(array $r): int => (int) $r['id'], $rows2));
    }

    #[Test]
    public function dataTypeFactoriesProduceValidColumnTypes(): void
    {
        $this->client->executeQuery('DROP TABLE IF EXISTS it_types');

        // If any generated type string were invalid, CREATE TABLE would throw.
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

        $this->assertSame(0, $this->countRows('it_types'), 'table created with all generated types');
    }

    #[Test]
    public function tableBuilderCreatesUsableTable(): void
    {
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

        $this->assertSame(2, (int) ($output->data[0]['cnt'] ?? 0), 'TableBuilder-created table must be usable');
    }

    #[Test]
    public function partitionManagerListsDropsDetachesAttaches(): void
    {
        $this->client->executeQuery('DROP TABLE IF EXISTS it_parts');
        $this->client->executeQuery('CREATE TABLE it_parts (id UInt64, p UInt8) ENGINE = MergeTree() PARTITION BY p ORDER BY id');
        (new ClickHouseBatchWriter($this->client, 'it_parts', ['id', 'p']))->write([
            ['id' => 1, 'p' => 0], ['id' => 2, 'p' => 1], ['id' => 3, 'p' => 0], ['id' => 4, 'p' => 1],
        ]);

        $manager = new ClickHousePartitionManager($this->client);

        $this->assertCount(2, $manager->getPartitions('it_parts'), 'two partitions: p=0 and p=1');

        // Drop partition p=0 -> ids 1,3 gone, 2 rows (p=1) remain.
        $manager->dropPartition('it_parts', '0');
        $this->assertSame(2, $this->countRows('it_parts'));

        // Detach + attach p=1 round-trip.
        $manager->detachPartition('it_parts', '1');
        $this->assertSame(0, $this->countRows('it_parts'));
        $manager->attachPartition('it_parts', '1');
        $this->assertSame(2, $this->countRows('it_parts'));
    }

    private function countRows(string $table): int
    {
        $output = $this->client->select(sprintf('SELECT count() AS cnt FROM %s', $table), new JsonEachRow());

        return (int) ($output->data[0]['cnt'] ?? 0);
    }

    #[Test]
    public function mutationBuilderUpdatesDeletesAndTracks(): void
    {
        $mb = new ClickHouseMutationBuilder($this->client);

        // Initially active = ids 1,2,4 (3 rows). UPDATE id=3 -> active.
        $mb->update(self::TABLE, 'status = {st:String}', 'id = {id:UInt64}', ['st' => 'active', 'id' => 3]);
        $this->assertTrue($mb->waitForMutations(self::TABLE, 10.0), 'update mutation should finish');
        $active = $this->client->selectWithParams(
            sprintf('SELECT count() AS cnt FROM %s WHERE status = {s:String}', self::TABLE),
            ['s' => 'active'],
            new JsonEachRow(),
        );
        $this->assertSame(4, (int) ($active->data[0]['cnt'] ?? 0));

        // DELETE id=5 -> 4 rows remain.
        $mb->delete(self::TABLE, 'id = {id:UInt64}', ['id' => 5]);
        $this->assertTrue($mb->waitForMutations(self::TABLE, 10.0), 'delete mutation should finish');
        $this->assertSame(4, $this->countRows(self::TABLE));

        $mutations = $mb->getMutations(self::TABLE);
        $this->assertGreaterThanOrEqual(2, count($mutations));
        foreach ($mutations as $m) {
            $this->assertTrue($m['is_done']);
        }

        // KILL MUTATION with no match must execute without error (validates the statement).
        $mb->killMutation(self::TABLE, 'nonexistent-mutation-id');
    }

    #[Test]
    public function batchWriterWritesToDbQualifiedTable(): void
    {
        $this->client->executeQuery('CREATE DATABASE IF NOT EXISTS it_analytics');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_analytics.qualified');
        $this->client->executeQuery('CREATE TABLE it_analytics.qualified (id UInt64) ENGINE = MergeTree() ORDER BY id');

        (new ClickHouseBatchWriter($this->client, 'it_analytics.qualified', ['id']))
            ->write([['id' => 1], ['id' => 2], ['id' => 3]]);

        $output = $this->client->select('SELECT count() AS cnt FROM it_analytics.qualified', new JsonEachRow());

        $this->assertSame(3, (int) ($output->data[0]['cnt'] ?? 0), 'db-qualified write must hit it_analytics.qualified');
    }

    #[Test]
    public function migrationRunnerAppliesAndIsIdempotent(): void
    {
        $pid = getmypid();
        $dir = sys_get_temp_dir() . '/ch_it_migrations_' . ($pid === false ? '0' : (string) $pid);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents(
            $dir . '/001_create_it_migr.sql',
            'CREATE TABLE IF NOT EXISTS it_migr_demo (id UInt64) ENGINE = MergeTree() ORDER BY id',
        );

        $this->client->executeQuery('DROP TABLE IF EXISTS _migrations');
        $this->client->executeQuery('DROP TABLE IF EXISTS it_migr_demo');

        $runner = new ClickHouseMigrationRunner($this->client, $dir);

        $this->assertSame(['001_create_it_migr.sql'], $runner->run(), 'Первый запуск применяет миграцию');
        $this->assertSame([], $runner->run(), 'Повторный запуск идемпотентен');

        unlink($dir . '/001_create_it_migr.sql');
        rmdir($dir);
    }
}
