<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Writes rows into a ClickHouse table.
 *
 * @api
 */
interface ClickHouseWriterInterface
{
    /**
     * @param iterable<array<string, mixed>> $rows Associative rows keyed by column name.
     *
     * @throws ClickHouseWriteException
     */
    public function write(iterable $rows): void;
}
