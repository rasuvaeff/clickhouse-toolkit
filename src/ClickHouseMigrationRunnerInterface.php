<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * @api
 */
interface ClickHouseMigrationRunnerInterface
{
    /**
     * @return list<string> Applied migration names
     */
    public function run(): array;
}
