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

    #[Test]
    public function skipsAlreadyAppliedMigrations(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRows()));
        $client->expects($this->never())->method('insert');

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        $this->assertSame([], $runner->run(), 'Уже применённые миграции должны пропускаться');
    }

    #[Test]
    public function throwsWhenAppliedMigrationContentChanged(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        // Stored checksum for 001 is wrong -> file was "changed" after applying.
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', sha1('tampered'));
        $client->method('select')->willReturn($this->chOutput($row));

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        $this->expectException(ClickHouseMigrationException::class);
        $this->expectExceptionMessageMatches('/was changed after it was applied/');

        $runner->run();
    }

    #[Test]
    public function throwsWhenMigrationHasConflictingChecksums(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":2}', sha1('x'));
        $client->method('select')->willReturn($this->chOutput($row));

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        $this->expectException(ClickHouseMigrationException::class);
        $this->expectExceptionMessageMatches('/conflicting checksums/');

        $runner->run();
    }

    #[Test]
    public function ensuresMigrationsTableWithMicrosecondVersionColumn(): void
    {
        $queries = [];
        $client = $this->queryCapturingClient($queries);

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `_migrations`', $queries[0]);
        $this->assertStringContainsString('ReplacingMergeTree(applied_at) ORDER BY name', $queries[0]);
        $this->assertStringContainsString('DateTime64(6)', $queries[0], 'Версия должна быть микросекундной');
    }

    #[Test]
    public function executesEachMigrationSqlVerbatim(): void
    {
        $queries = [];
        $client = $this->queryCapturingClient($queries);

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        foreach (['001_create_demo.sql', '002_add_name.sql'] as $name) {
            $this->assertContains((string) file_get_contents(self::MIGRATIONS_DIR . '/' . $name), $queries);
        }
    }

    #[Test]
    public function recordsAppliedMigrationViaInsert(): void
    {
        $inserts = [];
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));
        $client->method('insert')->willReturnCallback(
            static function (string $table, array $values, array $columns) use (&$inserts): void {
                $inserts[] = ['table' => $table, 'values' => $values, 'columns' => $columns];
            },
        );

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $checksum1 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $checksum2 = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/002_add_name.sql'));
        $this->assertSame([
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
        ], $inserts);
    }

    #[Test]
    public function logsAppliedMigrationViaProvidedLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('info')->with(
            'Applied ClickHouse migration {name}',
            $this->callback(static fn(array $context): bool => isset($context['name'])),
        );

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR, $logger))->run();
    }

    #[Test]
    public function continuesPastAlreadyAppliedMigrationToApplyNext(): void
    {
        $checksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $row = sprintf('{"name":"001_create_demo.sql","current_checksum":"%s","variants":1}', $checksum);

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($row));
        $client->expects($this->once())->method('insert');

        $applied = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->run();

        $this->assertSame(['002_add_name.sql'], $applied, 'После пропуска применённой должна примениться следующая');
    }

    #[Test]
    public function skipsWhitespaceOnlyMigrationButAppliesNext(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_blank.sql', "   \n\t");
        file_put_contents($dir . '/002_real.sql', 'CREATE TABLE x (a UInt8) ENGINE = Memory');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Skipping empty ClickHouse migration {name}',
            ['name' => '001_blank.sql'],
        );

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));
        $client->expects($this->once())->method('insert');

        $applied = (new ClickHouseMigrationRunner($client, $dir, $logger))->run();

        $this->assertSame(['002_real.sql'], $applied);
    }

    #[Test]
    public function throwsWhenMigrationFileUnreadable(): void
    {
        $dir = $this->makeTempDir();
        symlink($dir . '/missing_target', $dir . '/001_unreadable.sql'); // broken symlink => file_get_contents() returns false

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));
        $runner = new ClickHouseMigrationRunner($client, $dir);

        set_error_handler(static fn(): bool => true); // swallow "Is a directory" warning

        try {
            $this->expectException(ClickHouseMigrationException::class);
            $this->expectExceptionMessageMatches('/Cannot read migration file/');

            $runner->run();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function appliesMigrationFilesInSortedOrder(): void
    {
        $dir = $this->makeTempDir();
        // Created out of order on purpose.
        file_put_contents($dir . '/030_c.sql', 'SELECT 30');
        file_put_contents($dir . '/010_a.sql', 'SELECT 10');
        file_put_contents($dir . '/020_b.sql', 'SELECT 20');

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));

        $applied = (new ClickHouseMigrationRunner($client, $dir))->run();

        $this->assertSame(['010_a.sql', '020_b.sql', '030_c.sql'], $applied);
    }

    #[Test]
    public function statusMarksAllFilesAppliedWhenChecksumsMatch(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
        ])));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertCount(2, $statuses);
        $this->assertApplied('001_create_demo.sql', '2026-06-14 10:00:00.000000', $statuses[0]);
        $this->assertApplied('002_add_name.sql', '2026-06-14 11:00:00.000000', $statuses[1]);
    }

    #[Test]
    public function statusMarksAllFilesPendingWhenNothingApplied(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertCount(2, $statuses);
        $this->assertSame(ClickHouseMigrationState::Pending, $statuses[0]->state);
        $this->assertSame(ClickHouseMigrationState::Pending, $statuses[1]->state);
        $this->assertNull($statuses[0]->appliedAt);
        $this->assertNull($statuses[1]->appliedAt);
    }

    #[Test]
    public function statusMarksFileDivergedWhenChecksumMismatches(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '002_add_name.sql' => ['2026-06-14 11:00:00.000000', 1],
        ], 'wrong_checksum')));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertSame(ClickHouseMigrationState::Diverged, $statuses[0]->state, '001 должен быть Diverged');
        $this->assertSame(ClickHouseMigrationState::Diverged, $statuses[1]->state, '002 должен быть Diverged');
        $this->assertNotNull($statuses[0]->appliedAt, 'appliedAt должен сохраняться для Diverged');
    }

    #[Test]
    public function statusMarksFileDivergedWhenConflictingChecksumsRecorded(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 2],
        ])));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertSame(ClickHouseMigrationState::Diverged, $statuses[0]->state);
        $this->assertSame('002_add_name.sql', $statuses[1]->name);
        $this->assertSame(ClickHouseMigrationState::Pending, $statuses[1]->state, '002 отсутствует в _migrations — Pending');
    }

    #[Test]
    public function statusMarksRecordedMigrationsMissingWhenFileGone(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
            '099_dropped.sql' => ['2026-06-14 12:00:00.000000', 1],
        ])));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        // sorted by name: 001 (applied), 002 (pending), 099 (missing)
        $this->assertCount(3, $statuses);
        $this->assertSame('001_create_demo.sql', $statuses[0]->name);
        $this->assertSame(ClickHouseMigrationState::Applied, $statuses[0]->state);
        $this->assertSame('002_add_name.sql', $statuses[1]->name);
        $this->assertSame(ClickHouseMigrationState::Pending, $statuses[1]->state);
        $this->assertSame('099_dropped.sql', $statuses[2]->name);
        $this->assertSame(ClickHouseMigrationState::Missing, $statuses[2]->state, 'файл удалён, но запись в _migrations осталась');
        $this->assertNull($statuses[2]->checksum, 'Missing файл не имеет содержимого — checksum null');
        $this->assertSame('2026-06-14 12:00:00.000000', $statuses[2]->appliedAt, 'appliedAt берётся из _migrations');
    }

    #[Test]
    public function statusSortsByNameAcrossAllStates(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        // 000_z.sql recorded but file missing — its name sorts BEFORE files on disk.
        // Without usort, files-from-glob would come first and 000_z would land last.
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '000_z.sql' => ['2026-06-14 09:00:00.000000', 1],
        ])));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $names = array_map(static fn(ClickHouseMigrationStatus $s): string => $s->name, $statuses);
        $this->assertSame(['000_z.sql', '001_create_demo.sql', '002_add_name.sql'], $names);
    }

    #[Test]
    public function statusDivergedShowsCurrentFileChecksum(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        // Stored checksum is "wrong" but file is intact — Diverged must expose the file's checksum.
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 1],
        ], 'stored_value')));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $expectedFileChecksum = sha1((string) file_get_contents(self::MIGRATIONS_DIR . '/001_create_demo.sql'));
        $this->assertSame(ClickHouseMigrationState::Diverged, $statuses[0]->state);
        $this->assertSame($expectedFileChecksum, $statuses[0]->checksum, 'Diverged должен показывать checksum текущего файла, не stored');
    }

    #[Test]
    public function statusCreatesMigrationsTableBeforeReading(): void
    {
        $queries = [];
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));
        $client->method('executeQuery')->willReturnCallback(
            static function (string $sql) use (&$queries): void {
                $queries[] = $sql;
            },
        );

        (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `_migrations`', $queries[0]);
    }

    #[Test]
    public function statusDoesNotThrowOnDivergedOrConflictingRecords(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput($this->appliedRecordsRows([
            '001_create_demo.sql' => ['2026-06-14 10:00:00.000000', 5],
        ], 'totally_wrong')));

        $statuses = (new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR))->status();

        $this->assertSame(ClickHouseMigrationState::Diverged, $statuses[0]->state, 'status() не должен выбрасывать, в отличие от run()');
    }

    /**
     * @param list<string> $queries Captures executeQuery() SQL by reference.
     */
    private function queryCapturingClient(array &$queries): ClickHouseClient
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn($this->chOutput(''));
        $client->method('executeQuery')->willReturnCallback(
            static function (string $sql) use (&$queries): void {
                $queries[] = $sql;
            },
        );

        return $client;
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
