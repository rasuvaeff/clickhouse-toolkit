<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

/**
 * @api
 */
interface ClickHouseReaderInterface
{
    public function findByFilters(
        ?FilterInterface $filter = null,
        ?Sort $sort = null,
        int $limit = 20,
        int $offset = 0,
    ): array;

    public function countByFilters(?FilterInterface $filter = null): int;
}
