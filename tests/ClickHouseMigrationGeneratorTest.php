<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMigrationGenerator;

#[CoversClass(ClickHouseMigrationGenerator::class)]
final class ClickHouseMigrationGeneratorTest extends TestCase
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
    public function startsAt001WhenDirectoryEmpty(): void
    {
        $path = $this->generate('create events table');

        $this->assertSame('001_create_events_table.sql', basename($path));
    }

    #[Test]
    public function incrementsFromHighestExistingPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/001_a.sql', 'SELECT 1');
        file_put_contents($dir . '/010_b.sql', 'SELECT 2');
        file_put_contents($dir . '/005_c.sql', 'SELECT 3');

        $generator = new ClickHouseMigrationGenerator($dir);
        $path = $generator->generate('add column');

        $this->assertSame('011_add_column.sql', basename($path));
    }

    #[Test]
    public function ignoresFilesWithoutNumericPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/readme.md', 'doc');
        file_put_contents($dir . '/manual.sql', 'SELECT 1');
        file_put_contents($dir . '/002_real.sql', 'SELECT 2');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('new one');

        $this->assertSame('003_new_one.sql', basename($path));
    }

    #[Test]
    public function ignoresFilesWhereDigitsAreNotAtStart(): void
    {
        $dir = $this->makeTempDir();
        // 'name_001.sql' has digits, but not at the start — must be ignored.
        file_put_contents($dir . '/name_001.sql', 'SELECT 1');
        file_put_contents($dir . '/backup_010.sql', 'SELECT 2');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        $this->assertSame('001_first.sql', basename($path), 'Цифры не в начале имени не должны считаться за prefix');
    }

    #[Test]
    public function sanitizesDescription(): void
    {
        $dir = $this->makeTempDir();
        $generator = new ClickHouseMigrationGenerator($dir);

        $this->assertSame('001_create_events.sql', basename($generator->generate('Create EVENTS')));
        $this->assertSame('002_drop_table.sql', basename($generator->generate('  drop   table  ')));
        $this->assertSame('003_caf.sql', basename($generator->generate('café à ü')));
        $this->assertSame('004_with_numbers_v2.sql', basename($generator->generate('with numbers v2')));
    }

    #[Test]
    public function growsPrefixWidthBeyond999(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/999_last.sql', 'SELECT 1');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('next');

        $this->assertSame('1000_next.sql', basename($path));
    }

    #[Test]
    public function matchesWidthOfExistingFourDigitPrefix(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/1000_a.sql', 'SELECT 1');

        $path = (new ClickHouseMigrationGenerator($dir))->generate('b');

        $this->assertSame('1001_b.sql', basename($path));
    }

    #[Test]
    public function writesHeaderCommentWithSlug(): void
    {
        $path = $this->generate('create events table');

        $contents = (string) file_get_contents($path);
        $this->assertSame("-- ClickHouse migration: create_events_table\n\n", $contents);
    }

    #[Test]
    public function createsMigrationsDirectoryIfMissing(): void
    {
        $dir = sys_get_temp_dir() . '/chmig_' . uniqid('', true) . '/nested';
        $this->tempDirs[] = dirname($dir);

        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        $this->assertFileExists($path);
    }

    #[Test]
    public function returnsAbsolutePath(): void
    {
        $dir = $this->makeTempDir();
        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        $this->assertSame($dir . '/001_first.sql', $path);
    }

    #[Test]
    public function trimsTrailingSlashFromPath(): void
    {
        $dir = $this->makeTempDir() . '/';
        $path = (new ClickHouseMigrationGenerator($dir))->generate('first');

        $this->assertStringNotContainsString('//', $path);
        $this->assertSame('001_first.sql', basename($path));
    }

    #[Test]
    public function throwsOnEmptySlug(): void
    {
        $dir = $this->makeTempDir();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty slug/');

        (new ClickHouseMigrationGenerator($dir))->generate('   !!!   ');
    }

    #[Test]
    public function throwsOnEmptyDescription(): void
    {
        $dir = $this->makeTempDir();

        $this->expectException(InvalidArgumentException::class);

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
