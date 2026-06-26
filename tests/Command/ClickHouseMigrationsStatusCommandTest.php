<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationRunner;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsStatusCommand;
use SimPod\ClickHouseClient\Output\JsonEachRow;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMigrationsStatusCommand::class)]
final class ClickHouseMigrationsStatusCommandTest
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

    public function returnsSuccessWhenEverythingApplied(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, $this->allAppliedRows($dir));

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 0);
        $output = $tester->getDisplay();
        Assert::string($output)->contains('2 applied');
        Assert::string($output)->contains('0 pending');
        Assert::string($output)->contains('001_a.sql');
        Assert::string($output)->contains('002_b.sql');
        Assert::string($output)->contains('Migration');
        Assert::string($output)->contains('Applied at');
        Assert::string($output)->contains('2026-06-14 10:00:00.000000');
    }

    public function showsEmptyChecksumAndAppliedAtForPendingMigrations(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, '');

        $tester->execute([]);
        $output = $tester->getDisplay();

        Assert::string($output)->contains('2 pending');
        Assert::string($output)->notContains('2026-06-14');
    }

    public function showsStoredChecksumForAppliedMigrations(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $checksum = sha1((string) file_get_contents($dir . '/001_a.sql'));

        $tester = $this->tester($dir, $this->allAppliedRows($dir));
        $tester->execute([]);

        $output = $tester->getDisplay();
        Assert::string($output)->contains($checksum);
    }

    public function returnsFailureAndPrintsErrorWhenStatusThrows(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();

        $client = (new \Rasuvaeff\ClickHouseToolkit\Tests\FakeClickHouseClient())
            ->withSelectCallback(static function () {
                throw new \RuntimeException('server unreachable');
            });
        $runner = new ClickHouseMigrationRunner($client, $dir);
        $command = new ClickHouseMigrationsStatusCommand($runner);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 1);
        Assert::string($tester->getDisplay())->contains('server unreachable');
    }

    public function returnsFailureWhenDivergedExists(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $row = sprintf(
            '{"name":"001_a.sql","current_checksum":"%s","current_applied_at":"2026-06-14 10:00:00.000000","variants":1}' . "\n"
            . '{"name":"002_b.sql","current_checksum":"%s","current_applied_at":"2026-06-14 11:00:00.000000","variants":1}',
            'wrong',
            'wrong',
        );
        $tester = $this->tester($dir, $row);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 1);
        Assert::string($tester->getDisplay())->contains('2 diverged');
    }

    public function returnsFailureWhenMissingExists(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $row = sprintf(
            '{"name":"099_z.sql","current_checksum":"%s","current_applied_at":"2026-06-14 12:00:00.000000","variants":1}',
            'any',
        );
        $tester = $this->tester($dir, $row);

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 1);
        $output = $tester->getDisplay();
        Assert::string($output)->contains('1 missing');
        Assert::string($output)->contains('099_z.sql');
        Assert::string($output)->contains('2 pending');
    }

    public function marksPendingWhenNothingApplied(): void
    {
        $dir = $this->makeTempDirWithTwoMigrations();
        $tester = $this->tester($dir, '');

        $exitCode = $tester->execute([]);

        Assert::same($exitCode, 0);
        Assert::string($tester->getDisplay())->contains('2 pending');
    }

    private function tester(string $dir, string $chRows): CommandTester
    {
        $client = (new \Rasuvaeff\ClickHouseToolkit\Tests\FakeClickHouseClient())
            ->withSelectCallback(fn() => new JsonEachRow($chRows));
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
