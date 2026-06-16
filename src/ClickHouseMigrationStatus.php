<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Immutable record describing the state of a single migration file, produced by
 * {@see ClickHouseMigrationRunner::status()}.
 *
 * `checksum` is the current sha1 of the file contents (null when the file is
 * missing from disk). `appliedAt` is the stored `applied_at` value from the
 * `_migrations` table (null for pending files), formatted as the server
 * returned it (a ClickHouse `DateTime64(6)` string, e.g. `2026-06-14 12:00:00.123456`).
 *
 * @api
 */
final readonly class ClickHouseMigrationStatus
{
    public function __construct(
        public string $name,
        public ClickHouseMigrationState $state,
        public ?string $checksum = null,
        public ?string $appliedAt = null,
    ) {}
}
