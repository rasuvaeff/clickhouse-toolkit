<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Thrown when a batch insert into ClickHouse fails.
 *
 * @api
 */
final class ClickHouseWriteException extends \RuntimeException {}
