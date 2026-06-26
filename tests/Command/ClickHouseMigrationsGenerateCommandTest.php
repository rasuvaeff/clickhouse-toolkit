<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsGenerateCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMigrationsGenerateCommand::class)]
final class ClickHouseMigrationsGenerateCommandTest
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

    public function createsMigrationAndReturnsSuccess(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute(['description' => 'create events table']);

        Assert::same($exitCode, 0);
        Assert::true(file_exists($dir . '/001_create_events_table.sql'));
        Assert::same($tester->getStatusCode(), 0);
    }

    public function printsCreatedFilenameInOutput(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $tester->execute(['description' => 'add column']);

        $output = $tester->getDisplay();
        Assert::string($output)->contains('Created migration:');
        Assert::string($output)->contains('001_add_column.sql');
        Assert::string($output)->contains($dir . '/001_add_column.sql');
    }

    public function returnsFailureWhenGeneratorThrowsRuntimeException(): void
    {
        $file = sys_get_temp_dir() . '/chcmd_block_' . uniqid('', true);
        file_put_contents($file, 'blocker');
        $this->tempDirs[] = $file;

        $generator = new ClickHouseMigrationGenerator($file);
        $command = new ClickHouseMigrationsGenerateCommand($generator);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['description' => 'first']);

        Assert::same($exitCode, 1);
        Assert::string($tester->getDisplay())->contains('Cannot create migrations directory');
    }

    public function incrementsFromExistingFiles(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_a.sql', '-- a');

        $tester = $this->tester($dir);
        $tester->execute(['description' => 'b']);

        Assert::true(file_exists($dir . '/002_b.sql'));
    }

    public function returnsInvalidOnEmptySlug(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute(['description' => '   !!!   ']);

        Assert::same($exitCode, 2);
        Assert::string($tester->getDisplay())->contains('empty slug');
    }

    private function tester(string $dir): CommandTester
    {
        $generator = new ClickHouseMigrationGenerator($dir);
        $command = new ClickHouseMigrationsGenerateCommand($generator);
        $command->setApplication(new \Symfony\Component\Console\Application());

        return new CommandTester($command);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chcmd_' . uniqid('', true);
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

        if (file_exists($path)) {
            unlink($path);
        }
    }
}
