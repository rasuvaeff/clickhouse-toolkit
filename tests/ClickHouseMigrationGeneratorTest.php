<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMigrationGenerator::class)]
final class ClickHouseMigrationGeneratorTest
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

    public function startsAt001WhenDirectoryEmpty(): void
    {
        $path = $this->generate('create events table');

        Assert::same(basename($path), '001_create_events_table.sql');
    }

    public function incrementsFromHighestExistingPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_a.sql', 'SELECT 1');
        file_put_contents($dir . '/010_b.sql', 'SELECT 2');
        file_put_contents($dir . '/005_c.sql', 'SELECT 3');

        $generator = new ClickHouseMigrationGenerator($dir);
        $path = $generator->generate('add column');

        Assert::same(basename($path), '011_add_column.sql');
    }

    public function ignoresFilesWithoutNumericPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/readme.md', 'doc');
        file_put_contents($dir . '/manual.sql', 'SELECT 1');
        file_put_contents($dir . '/002_real.sql', 'SELECT 2');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('new one');

        Assert::same(basename($path), '003_new_one.sql');
    }

    public function ignoresFilesWhereDigitsAreNotAtStart(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/name_001.sql', 'SELECT 1');
        file_put_contents($dir . '/backup_010.sql', 'SELECT 2');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        Assert::same(basename($path), '001_first.sql');
    }

    public function sanitizesDescription(): void
    {
        $dir = $this->makeTempDir();
        $generator = new ClickHouseMigrationGenerator($dir);

        Assert::same(basename($generator->generate('Create EVENTS')), '001_create_events.sql');
        Assert::same(basename($generator->generate('  drop   table  ')), '002_drop_table.sql');
        Assert::same(basename($generator->generate('café à ü')), '003_caf.sql');
        Assert::same(basename($generator->generate('with numbers v2')), '004_with_numbers_v2.sql');
    }

    public function growsPrefixWidthBeyond999(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/999_last.sql', 'SELECT 1');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('next');

        Assert::same(basename($path), '1000_next.sql');
    }

    public function matchesWidthOfExistingFourDigitPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/1000_a.sql', 'SELECT 1');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('b');

        Assert::same(basename($path), '1001_b.sql');
    }

    public function writesHeaderCommentWithSlug(): void
    {
        $path = $this->generate('create events table');

        $contents = (string) file_get_contents($path);
        Assert::same($contents, "-- ClickHouse migration: create_events_table\n\n");
    }

    public function createsMigrationsDirectoryIfMissing(): void
    {
        $dir = sys_get_temp_dir() . '/chmig_' . uniqid('', true) . '/nested';
        $this->tempDirs[] = dirname($dir);

        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        Assert::true(file_exists($path));
    }

    public function returnsAbsolutePath(): void
    {
        $dir = $this->makeTempDir();
        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        Assert::same($path, $dir . '/001_first.sql');
    }

    public function trimsTrailingSlashFromPath(): void
    {
        $dir = $this->makeTempDir() . '/';
        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        Assert::string($path)->notContains('//');
        Assert::same(basename($path), '001_first.sql');
    }

    public function throwsOnEmptySlug(): void
    {
        $dir = $this->makeTempDir();

        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMigrationGenerator($dir))->generate('   !!!   ');
    }

    public function throwsOnEmptyDescription(): void
    {
        $dir = $this->makeTempDir();

        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMigrationGenerator($dir))->generate('');
    }

    private function generate(string $description): string
    {
        $dir = $this->makeTempDir();

        return (new ClickHouseMigrationGenerator($dir))->generate($description);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/chgen_' . uniqid('', true);
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
