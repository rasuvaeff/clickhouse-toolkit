<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationState;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationStatus;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMigrationRunner::class)]
#[Covers(ClickHouseMigrationException::class)]
#[Covers(ClickHouseMigrationState::class)]
#[Covers(ClickHouseMigrationStatus::class)]
final class ClickHouseMigrationRunnerTest
{
    private const string MIGRATIONS_DIR = __DIR__ . '/Fixtures/migrations';

    /** @var list<string> */
    private array $tempDirs = [];

    #[AfterTest]
    public function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeRecursively($dir);
        }
        $this->tempDirs = [];
    }

    public function appliesPendingMigrationsInOrder(): void
    {
        $insertCount = 0;
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput(''))
            ->withInsertCallback(static function () use (&$insertCount): void {
                $insertCount++;
            });

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        $applied = $runner->run();

        Assert::same($applied, ['001_create_demo.sql', '002_add_name.sql']);
        Assert::same($insertCount, 2);
    }

    public function skipsAlreadyAppliedMigrations(): void
    {
        $insertCalled = false;
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput($this->appliedRows()))
            ->withInsertCallback(static function () use (&$insertCalled): void {
                $insertCalled = true;
            });

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        Assert::same($runner->run(), []);
        Assert::false($insertCalled);
    }

    public function throwsWhenAppliedMigrationContentChanged(): void
    {
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', sha1('tampered'));
        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput($row));

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        Expect::exception(ClickHouseMigrationException::class);

        $runner->run();
    }

    public function throwsWhenMigrationHasConflictingChecksums(): void
    {
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":2}', sha1('x'));
        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput($row));

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        Expect::exception(ClickHouseMigrationException::class);

        $runner->run();
    }

    public function ensuresMigrationsTableWithMicrosecondVersionColumn(): void
    {
        $queries = [];
        $client = $this->queryCapturingClient($queries);

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
        Assert::string($queries[0])->contains('ReplacingMergeTree(applied_at) ORDER BY name');
        Assert::string($queries[0])->contains('DateTime64(6)');
    }

    public function executesEachMigrationSqlVerbatim(): void
    {
        $queries = [];
        $client = $this->queryCapturingClient($queries);

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        foreach (['001_create_demo.sql', '002_add_name.sql'] as $name) {
            Assert::true(in_array((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name), $queries, true));
        }
    }

    public function recordsAppliedMigrationViaInsert(): void
    {
        $inserts = [];
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput(''))
            ->withInsertCallback(
                static function (string $table, array $values, array $columns) use (&$inserts): void {
                    $inserts[] = ['table' => $table, 'values' => $values, 'columns' => $columns];
                },
            );

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $checksum1 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $checksum2 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/002_add_name.sql'));
        Assert::same($inserts, [
            [
                'table' => '_migrations',
                'values' => [['name' => '001_create_demo.sql', 'checksum' => $checksum1]],
                'columns' => ['name', 'checksum'],
            ],
            [
                'table' => '_migrations',
                'values' => [['name' => '002_add_name.sql', 'checksum' => $checksum2]],
                'columns' => ['name', 'checksum'],
            ],
        ]);
    }

    public function logsAppliedMigrationViaProvidedLogger(): void
    {
        $logCalls = [];
        $logger = new class ($logCalls) implements LoggerInterface {
            public function __construct(private array &$calls) {}

            public function emergency(\Stringable|string $message, array $context = []): void {}
            public function alert(\Stringable|string $message, array $context = []): void {}
            public function critical(\Stringable|string $message, array $context = []): void {}
            public function error(\Stringable|string $message, array $context = []): void {}
            public function warning(\Stringable|string $message, array $context = []): void {}
            public function notice(\Stringable|string $message, array $context = []): void {}
            public function info(\Stringable|string $message, array $context = []): void {
                $this->calls[] = [$message, $context];
            }
            public function debug(\Stringable|string $message, array $context = []): void {}
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };

        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput(''));

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR, $logger))->run();

        Assert::same(count($logCalls), 2);
        Assert::same($logCalls[0][0], 'Applied ClickHouse migration {name}');
        Assert::true(isset($logCalls[0][1]['name']));
    }

    public function continuesPastAlreadyAppliedMigrationToApplyNext(): void
    {
        $checksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', $checksum);

        $insertCount = 0;
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput($row))
            ->withInsertCallback(static function () use (&$insertCount): void {
                $insertCount++;
            });

        $applied = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        Assert::same($applied, ['002_add_name.sql']);
        Assert::same($insertCount, 1);
    }

    public function skipsWhitespaceOnlyMigrationButAppliesNext(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_blank.sql', "   \n\t");
        file_put_contents($dir . '/002_real.sql', 'CREATE TABLE x (a UInt8) ENGINE = Memory');

        $warningCalls = [];
        $logger = new class ($warningCalls) implements LoggerInterface {
            public function __construct(private array &$calls) {}

            public function emergency(\Stringable|string $message, array $context = []): void {}
            public function alert(\Stringable|string $message, array $context = []): void {}
            public function critical(\Stringable|string $message, array $context = []): void {}
            public function error(\Stringable|string $message, array $context = []): void {}
            public function warning(\Stringable|string $message, array $context = []): void {
                $this->calls[] = [$message, $context];
            }
            public function notice(\Stringable|string $message, array $context = []): void {}
            public function info(\Stringable|string $message, array $context = []): void {}
            public function debug(\Stringable|string $message, array $context = []): void {}
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };

        $insertCount = 0;
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput(''))
            ->withInsertCallback(static function () use (&$insertCount): void {
                $insertCount++;
            });

        $applied = (new ClickHouseMigrationRunner($client, $dir, $logger))->run();

        Assert::same($applied, ['002_real.sql']);
        Assert::same($insertCount, 1);
        Assert::same(count($warningCalls), 1);
    }

    public function throwsWhenMigrationFileUnreadable(): void
    {
        $dir = $this->makeTempDir();
        symlink($dir . '/missing_target', $dir . '/001_unreadable.sql');

        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput(''));
        $runner = new ClickHouseMigrationRunner($client, $dir);

        set_error_handler(static fn(): bool => true);

        try {
            Expect::exception(ClickHouseMigrationException::class);

            $runner->run();
        } finally {
            restore_error_handler();
        }
    }

    public function appliesMigrationFilesInSortedOrder(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/030_c.sql', 'SELECT 30');
        file_put_contents($dir . '/010_a.sql', 'SELECT 10');
        file_put_contents($dir . '/020_b.sql', 'SELECT 20');

        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput(''));

        $applied = (new ClickHouseMigrationRunner($client, $dir))->run();

        Assert::same($applied, ['010_a.sql', '020_b.sql', '030_c.sql']);
    }

    public function statusMarksAllFilesAppliedWhenChecksumsMatch(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
                '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
            ])),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 2);
        $this->assertApplied('001_create_demo.sql', '2026-06-14 10:00:00.000000', $statuses[0]);
        $this->assertApplied('002_add_name.sql', '2026-06-14 11:00:00.000000', $statuses[1]);
    }

    public function statusMarksAllFilesPendingWhenNothingApplied(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(fn () => $this->chOutput(''));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 2);
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Pending);
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
        Assert::null($statuses[0]->appliedAt);
        Assert::null($statuses[1]->appliedAt);
    }

    public function statusMarksFileDivergedWhenChecksumMismatches(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
                '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
            ], 'wrong_checksum')),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Diverged);
        Assert::false($statuses[0]->appliedAt === null);
    }

    public function statusMarksFileDivergedWhenConflictingChecksumsRecorded(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 2],
            ])),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[1]->name, '002_add_name.sql');
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
    }

    public function statusMarksRecordedMigrationsMissingWhenFileGone(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
                '099_dropped.sql' => ['2026-06-14 12:00:00.000000', 1],
            ])),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same(count($statuses), 3);
        Assert::same($statuses[0]->name, '001_create_demo.sql');
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Applied);
        Assert::same($statuses[1]->name, '002_add_name.sql');
        Assert::same($statuses[1]->state, ClickHouseMigrationState::Pending);
        Assert::same($statuses[2]->name, '099_dropped.sql');
        Assert::same($statuses[2]->state, ClickHouseMigrationState::Missing);
        Assert::null($statuses[2]->checksum);
        Assert::same($statuses[2]->appliedAt, '2026-06-14 12:00:00.000000');
    }

    public function statusSortsByNameAcrossAllStates(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '000_z.sql' => ['2026-06-14 09:00:00.000000', 1],
            ])),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $names = array_map(static fn(ClickHouseMigrationStatus $s): string => $s->name, $statuses);
        Assert::same($names, ['000_z.sql', '001_create_demo.sql', '002_add_name.sql']);
    }

    public function statusDivergedShowsCurrentFileChecksum(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            ], 'stored_value')),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $expectedFileChecksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
        Assert::same($statuses[0]->checksum, $expectedFileChecksum);
    }

    public function statusCreatesMigrationsTableBeforeReading(): void
    {
        $queries = [];
        $client = (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput(''))
            ->withExecuteQueryCallback(
                static function (string $sql) use (&$queries): void {
                    $queries[] = $sql;
                },
            );

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::string($queries[0])->contains('CREATE TABLE IF NOT EXISTS `_migrations`');
    }

    public function statusDoesNotThrowOnDivergedOrConflictingRecords(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn () => $this->chOutput($this->appliedRecordsRows([
                '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 5],
            ], 'totally_wrong')),
        );

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        Assert::same($statuses[0]->state, ClickHouseMigrationState::Diverged);
    }

    /**
     * @param list<string> $queries Captures executeQuery() SQL by reference.
     */
    private function queryCapturingClient(array &$queries)
    {
        return (new FakeClickHouseClient())
            ->withSelectCallback(fn () => $this->chOutput(''))
            ->withExecuteQueryCallback(
                static function (string $sql) use (&$queries): void {
                    $queries[] = $sql;
                },
            );
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chmig_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
                $this->removeRecursively($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }

    private function appliedRows(): string
    {
        $rows = [];
        foreach (['001_create_demo.sql', '002_add_name.sql'] as $name) {
            $checksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name));
            $rows[] = sprintf('{"name":"%s","current_checksum":"%s","variants":1}', $name, $checksum);
        }

        return implode("\n", $rows);
    }

    /**
     * Builds the rows returned by {@see ClickHouseMigrationRunner::fetchAppliedRecords()}.
     *
     * @param array<string, array{0: string, 1: int}> $records name => [appliedAt, variants]
     * @param string|null $checksumOverride        use the real file checksum when null.
     */
    private function appliedRecordsRows(array $records, ?string $checksumOverride = null): string
    {
        $rows = [];
        foreach ($records as $name => [$appliedAt, $variants]) {
            $checksum = $checksumOverride ?? @sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name));
            $rows[] = sprintf(
                '{"name":"%s","current_checksum":"%s","current_applied_at":"%s","variants":%d}',
                $name,
                $checksum,
                $appliedAt,
                $variants,
            );
        }

        return implode("\n", $rows);
    }

    private function assertApplied(string $name, string $appliedAt, ClickHouseMigrationStatus $status): void
    {
        $this->assertSame($name, $status->name);
        $this->assertSame(ClickHouseMigrationState::Applied, $status->state);
        $this->assertSame($appliedAt, $status->appliedAt);
        $this->assertNotNull($status->checksum, 'Applied должна иметь checksum из файла');
    }

    /**
     * Builds a stub ClickHouse output from newline-delimited JSON rows.
     */
    private function chOutput(string $rowsJson): Output
    {
        return new JsonEachRowOutput($rowsJson);
    }
}
