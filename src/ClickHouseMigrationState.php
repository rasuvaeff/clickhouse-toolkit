<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * State of a migration file relative to the `_migrations` table.
 *
 * - {@see self::Applied}: the file exists and its checksum matches the recorded one.
 * - {@see self::Pending}: the file exists but has not been recorded yet.
 * - {@see self::Missing}: the file no longer exists on disk but was recorded.
 * - {@see self::Diverged}: the file exists, was recorded, but its current
 *   contents no longer match the stored checksum (tampering / uncommitted edit).
 *
 * @api
 */
enum ClickHouseMigrationState: string
{
    case Applied = 'applied';
    case Pending = 'pending';
    case Missing = 'missing';
    case Diverged = 'diverged';
}
