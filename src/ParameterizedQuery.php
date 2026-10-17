<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * A finished statement and its bound parameters — what
 * `ClickHouseClient::selectWithParams()` takes. Produced by
 * {@see SelectQuery::build()}; the WHERE half of the toolkit produces its
 * {@see WhereClause} instead, but the shape is deliberately the same.
 *
 * @api
 */
final readonly class ParameterizedQuery
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $sql,
        public array $params,
    ) {}
}
