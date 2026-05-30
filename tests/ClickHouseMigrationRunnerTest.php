<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
