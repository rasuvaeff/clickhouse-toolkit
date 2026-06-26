<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsRunCommand;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMigrationsRunCommand::class)]
final class ClickHouseMigrationsRunCommandTest
{
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

    public function appliesPendingMigrationsAndReturnsSuccess(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 0);
        $output = $tester->getDisplay();
        Assert::string($output)->contains('001_a.sql');
        Assert::string($output)->contains('002_b.sql');
        Assert::string($output)->contains('Applied 2 migration');
    }

    public function returnsSuccessWhenNothingToApply(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();

        $rows = [];
        foreach (['001_a.sql', '002_b.sql'] as $name) {
            $checksum = sha1((string) file_get_contents($dir . '/' . $name));
            $rows[] = sprintf('{"name":"%s","current_checksum":"%s","variants":1}', $name, $checksum);
        }

        $client = (new \Rasuvaeff\ClickHouseToolkit\Tests\FakeClickHouseClient())
            ->withSelectCallback(fn () => new JsonEachRow(implode("\n", $rows)));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsRunCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 0);
        Assert::string($tester->getDisplay())->contains('up to date');
    }

    public function returnsFailureOnDivergedChecksum(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $row = sprintf(
            '{"name":"001_a.sql","current_checksum":"%s","variants":1}',
            'wrong',
        );
        $client = (new \Rasuvaeff\ClickHouseToolkit\Tests\FakeClickHouseClient())
            ->withSelectCallback(fn () => new JsonEachRow($row));
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsRunCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 1);
        Assert::string($tester->getDisplay())->contains('was changed after it was applied');
    }

    private function tester(string $dir): CommandTester
    {
        $client = (new \Rasuvaeff\ClickHouseToolkit\Tests\FakeClickHouseClient())
            ->withSelectCallback(fn () => new JsonEachRow(''));
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
