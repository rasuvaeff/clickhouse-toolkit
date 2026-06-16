<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsStatusCommand;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClickHouseMigrationsStatusCommand::class)]
final class ClickHouseMigrationsStatusCommandTest extends TestCase
{
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
    public function returnsSuccessWhenEverythingApplied(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, $this->allAppliedRows($dir));

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('2 applied', $output);
        $this->assertStringContainsString('0 pending', $output);
        $this->assertStringContainsString('001_a.sql', $output);
        $this->assertStringContainsString('002_b.sql', $output);
        $this->assertStringContainsString('Migration', $output, 'header колонки Migration должен присутствовать');
        $this->assertStringContainsString('Applied at', $output, 'header колонки Applied at должен присутствовать');
        $this->assertStringContainsString('2026-06-14 10:00:00.000000', $output, 'applied_at должен выводиться для applied миграций');
    }

    #[Test]
    public function showsEmptyChecksumAndAppliedAtForPendingMigrations(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, '');

        $tester->execute([]);
        $output = $tester->getDisplay();

        // Pending rows show empty Checksum and Applied at — verify the table renders
        // by checking the count summary (cells are visually empty, hard to assert directly).
        $this->assertStringContainsString('2 pending', $output);
        $this->assertStringNotContainsString('2026-06-14', $output, 'pending миграции не должны иметь applied_at');
    }

    #[Test]
    public function showsStoredChecksumForAppliedMigrations(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $checksum = sha1((string) file_get_contents($dir . '/001_a.sql'));

        $tester = $this->tester($dir, $this->allAppliedRows($dir));
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString($checksum, $output, 'checksum должен быть в таблице для applied миграций');
    }

    #[Test]
    public function returnsFailureAndPrintsErrorWhenStatusThrows(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();

        $client = $this->createMock(ClickHouseClient::class);
        // status() calls select() twice (ensureMigrationsTable is executeQuery, then fetchAppliedRecords select).
        // Make the second call throw a RuntimeException to exercise the catch in the command.
        $client->method('select')->willThrowException(new \RuntimeException('server unreachable'));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsStatusCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('server unreachable', $tester->getDisplay(), 'сообщение об ошибке должно выводиться через $io->error()');
    }

    #[Test]
    public function returnsFailureWhenDivergedExists(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        // Wrong checksum for both files -> diverged.
        $row = sprintf(
            '{"name":"001_a.sql","current_checksum":"%s","current_applied_at":"2026-06-14 10:00:00.000000","variants":1}' . "\n"
            . '{"name":"002_b.sql","current_checksum":"%s","current_applied_at":"2026-06-14 11:00:00.000000","variants":1}',
            'wrong',
            'wrong',
        );
        $tester = $this->tester($dir, $row);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('2 diverged', $tester->getDisplay());
    }

    #[Test]
    public function returnsFailureWhenMissingExists(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        // 099_z.sql is recorded but file is gone -> missing.
        $row = sprintf(
            '{"name":"099_z.sql","current_checksum":"%s","current_applied_at":"2026-06-14 12:00:00.000000","variants":1}',
            'any',
        );
        $tester = $this->tester($dir, $row);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('1 missing', $output);
        $this->assertStringContainsString('099_z.sql', $output);
        $this->assertStringContainsString('2 pending', $output);
    }

    #[Test]
    public function marksPendingWhenNothingApplied(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, '');

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('2 pending', $tester->getDisplay());
    }

    private function tester(string $dir, string $chRows): CommandTester
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn(new JsonEachRow($chRows));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsStatusCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());

        return new CommandTester($command);
    }

    private function makeTempDirWithTwoMigrations(): string
    {
        $dir = sys_get_temp_dir() . '/chcmdstat_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . '/001_a.sql', 'CREATE TABLE a (x UInt8) ENGINE = Memory');
        file_put_contents($dir . '/002_b.sql', 'CREATE TABLE b (y UInt8) ENGINE = Memory');

        return $dir;
    }

    private function allAppliedRows(string $dir): string
    {
        $rows = [];
        foreach (['001_a.sql', '002_b.sql'] as $i => $name) {
            $checksum = sha1((string) file_get_contents($dir . '/' . $name));
            $appliedAt = $i === 0 ? '2026-06-14 10:00:00.000000' : '2026-06-14 11:00:00.000000';
            $rows[] = sprintf(
                '{"name":"%s","current_checksum":"%s","current_applied_at":"%s","variants":1}',
                $name,
                $checksum,
                $appliedAt,
            );
        }

        return implode("\n", $rows);
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

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
