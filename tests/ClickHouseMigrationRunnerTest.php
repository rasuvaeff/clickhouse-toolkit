<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;

#[CoversClass(ClickHouseMigrationRunner::class)]
#[CoversClass(ClickHouseMigrationException::class)]
final class ClickHouseMigrationRunnerTest extends TestCase
{
    private const string MIGRATIONS_DIR = __DIR__ . '/Fixtures/migrations';

    /** @var list<string> */
    private array $tempDirs = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeRecursively($dir);
        }
        $this->tempDirs = [];
    }

    #[Test]
    public function appliesPendingMigrationsInOrder(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        // No migrations applied yet.
        $client->method('select')->willReturn($this->chOutput(''));
        $client->expects($this->exactly(2))->method('insert');

        $runner = new ClickHouseMigrationRunner($client, self::MIGRATIONS_DIR);

        $applied = $runner->run();

        $this->assertSame(['001_create_demo.sql', '002_add_name.sql'], $applied);
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
     * Builds a stub ClickHouse output from newline-delimited JSON rows.
     */
    private function chOutput(string $rowsJson): Output
    {
        return new JsonEachRowOutput($rowsJson);
    }
}
