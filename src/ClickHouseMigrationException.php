<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Thrown when a migration cannot be applied — e.g. an already-applied file's
 * contents changed (checksum mismatch).
 *
 * @api
 */
final class ClickHouseMigrationException extends \RuntimeException {}
