<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Creates a new `*.sql` migration file with the next sequential numeric prefix.
 *
 * Files are named `<prefix>_<description>.sql`, where `<prefix>` is a
 * zero-padded integer that sorts lexicographically with the existing files
 * (so the {@see ClickHouseMigrationRunner}, which applies files in filename
 * order, runs them in the intended sequence). The prefix width grows to fit
 * the largest existing prefix: `001`, `002`, … `999`, then `1000`, `1001`, …
 *
 * The description is sanitised to a `kebab-snake` slug: lowercase, every run
 * of non-alphanumeric characters collapsed to a single underscore, leading /
 * trailing underscores stripped.
 *
 * The file is created with a header comment followed by a blank line so the
 * migration runner's empty-file guard (it skips whitespace-only files with a
 * warning) is not triggered — write your DDL after the header.
 *
 * The generator is a plain filesystem helper: it does not talk to ClickHouse
 * and has no client dependency. Concurrent invocations are not serialised —
 * run it from a single workstation (like {@see ClickHouseMigrationRunner::run()},
 * which documents the same caveat).
 *
 * @api
 */
final readonly class ClickHouseMigrationGenerator
{
    private const string HEADER_TEMPLATE = "-- ClickHouse migration: %s\n\n";

    public function __construct(
        private string $migrationsPath,
    ) {}

    /**
     * Creates `<migrationsPath>/<prefix>_<slug>.sql` and returns the absolute path.
     *
     * @param string $description Human-readable description (e.g. "create events table").
     *
     * @throws \RuntimeException             when the migrations path cannot be created or the file cannot be written.
     * @throws \InvalidArgumentException    when the description yields an empty slug.
     */
    public function generate(string $description): string
    {
        $slug = $this->slug($description);

        $prefix = $this->nextPrefix();
        $filename = sprintf('%s_%s.sql', $prefix, $slug);
        $path = rtrim($this->migrationsPath, '/') . '/' . $filename;

        if (file_exists($path)) {
            throw new \RuntimeException(sprintf('Migration file "%s" already exists.', $filename));
        }

        if (!is_dir($this->migrationsPath) && !@mkdir($this->migrationsPath, 0o777, true) && !is_dir($this->migrationsPath)) {
            throw new \RuntimeException(sprintf('Cannot create migrations directory "%s".', $this->migrationsPath));
        }

        $written = @file_put_contents($path, sprintf(self::HEADER_TEMPLATE, $slug));
        if ($written === false) {
            throw new \RuntimeException(sprintf('Cannot write migration file "%s".', $path));
        }

        return $path;
    }

    /**
     * Returns the next zero-padded prefix matching the width of the widest existing one.
     */
    private function nextPrefix(): string
    {
        $maxNumber = 0;
        $maxWidth = 3;

        foreach ($this->existingPrefixes() as $prefix) {
            $maxNumber = max($maxNumber, (int) $prefix);
            $maxWidth = max($maxWidth, strlen($prefix));
        }

        $next = $maxNumber + 1;

        return str_pad((string) $next, $maxWidth, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string> Numeric prefixes of existing `*.sql` files, in arbitrary order.
     */
    private function existingPrefixes(): array
    {
        $files = glob($this->migrationsPath . '/*.sql');
        if ($files === false) {
            return [];
        }

        $prefixes = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^(\d+)_/', $name, $matches) === 1) {
                $prefixes[] = $matches[1];
            }
        }

        return $prefixes;
    }

    /**
     * @throws \InvalidArgumentException when the description yields an empty slug.
     */
    private function slug(string $description): string
    {
        $slug = strtolower($description);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');

        if ($slug === '') {
            throw new \InvalidArgumentException(sprintf('Migration description "%s" yields an empty slug.', $description));
        }

        return $slug;
    }
}
