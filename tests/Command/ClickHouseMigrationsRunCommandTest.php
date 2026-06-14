<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsRunCommand;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClickHouseMigrationsRunCommand::class)]
final class ClickHouseMigrationsRunCommandTest extends TestCase
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
    public function appliesPendingMigrationsAndReturnsSuccess(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('001_a.sql', $output);
        $this->assertStringContainsString('002_b.sql', $output);
        $this->assertStringContainsString('Applied 2 migration', $output);
    }

    #[Test]
    public function returnsSuccessWhenNothingToApply(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();

        // Both migrations already applied (real checksums from disk).
        $rows = [];
        foreach (['001_a.sql', '002_b.sql'] as $name) {
            $checksum = sha1((string) file_get_contents($dir . '/' . $name));
            $rows[] = sprintf('{"name":"%s","current_checksum":"%s","variants":1}', $name, $checksum);
        }

        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn(new JsonEachRow(implode("\n", $rows)));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsRunCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('up to date', $tester->getDisplay());
    }

    #[Test]
    public function returnsFailureOnDivergedChecksum(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $row = sprintf(
            '{"name":"001_a.sql","current_checksum":"%s","variants":1}',
            'wrong',
        );
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn(new JsonEachRow($row));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsRunCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('was changed after it was applied', $tester->getDisplay());
    }

    private function tester(string $dir): CommandTester
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn(new JsonEachRow(''));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsRunCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());

        return new CommandTester($command);
    }

    private function makeTempDirWithTwoMigrations(): string
    {
        $dir = sys_get_temp_dir() . '/chcmdrun_' . uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . '/001_a.sql', 'CREATE TABLE a (x UInt8) ENGINE = Memory');
        file_put_contents($dir . '/002_b.sql', 'CREATE TABLE b (y UInt8) ENGINE = Memory');

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

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
