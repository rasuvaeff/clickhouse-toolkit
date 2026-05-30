<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Result of {@see ClickHouseQueryBuilder::buildWhere()}: a SQL fragment and its
 * bound parameters. The SQL is empty when the filter produced no condition.
 *
 * @api
 */
final readonly class WhereClause
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $sql,
        public array $params,
    ) {}

    public function isEmpty(): bool
    {
        return $this->sql === '';
    }
}
