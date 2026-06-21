<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Benchmarks;

use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Testo\Bench;

/**
 * Compares ClickHouseQueryBuilder construction cost for a short allow-list
 * (2 identifier validations) vs a long allow-list (10 validations).
 *
 * Each allowedField triggers one preg_match() in Identifier::assert(); the
 * constructor is the natural place to measure that overhead since it runs
 * immediately at creation time rather than deferring to query execution.
 */
final class QueryBuilderBench
{
    #[Bench(
        callables: [
            'many_fields' => [self::class, 'constructWithManyFields'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function constructWithFewFields(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status'],
        );
    }

    public static function constructWithManyFields(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(
            allowedFields: [
                'id',
                'user_id',
                'tenant_id',
                'event_type',
                'payload',
                'created_at',
                'updated_at',
                'deleted_at',
                'status',
                'meta',
            ],
        );
    }
}
