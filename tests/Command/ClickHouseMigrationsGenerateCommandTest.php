<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Rasuvaeff\ClickHouseToolkit\Command\ClickHouseMigrationsGenerateCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ClickHouseMigrationsGenerateCommand::class)]
final class ClickHouseMigrationsGenerateCommandTest extends TestCase
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
    public function createsMigrationAndReturnsSuccess(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute(['description' => 'create events table']);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($dir . '/001_create_events_table.sql');
        $tester->assertCommandIsSuccessful();
    }

    #[Test]
    public function printsCreatedFilenameInOutput(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $tester->execute(['description' => 'add column']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Created migration:', $output, 'success-сообщение должно присутствовать');
        $this->assertStringContainsString('001_add_column.sql', $output, 'basename должен быть в success-сообщении');
        $this->assertStringContainsString($dir . '/001_add_column.sql', $output, 'полный путь должен выводиться отдельной строкой');
    }

    #[Test]
    public function returnsFailureWhenGeneratorThrowsRuntimeException(): void
    {
        // $migrationsPath is a file, not a directory — mkdir inside Generator will fail
        // and it will throw RuntimeException, which the command must catch as FAILURE.
        $file = sys_get_temp_dir() . '/chcmd_block_' . uniqid('', true);
        file_put_contents($file, 'blocker');
        $this->tempDirs[] = $file;

        $generator = new ClickHouseMigrationGenerator($file);
        $command = new ClickHouseMigrationsGenerateCommand($generator);
        $command->setApplication(new \Symfony\Component\Console\Application());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['description' => 'first']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Cannot create migrations directory', $tester->getDisplay());
    }

    #[Test]
    public function incrementsFromExistingFiles(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_a.sql', '-- a');

        $tester = $this->tester($dir);
        $tester->execute(['description' => 'b']);

        $this->assertFileExists($dir . '/002_b.sql');
    }

    #[Test]
    public function returnsInvalidOnEmptySlug(): void
    {
        $dir = $this->makeTempDir();
        $tester = $this->tester($dir);

        $exitCode = $tester->execute(['description' => '   !!!   ']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('empty slug', $tester->getDisplay());
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
