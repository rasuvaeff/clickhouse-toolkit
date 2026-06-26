<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseTableBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHouseTableBuilder::class)]
final class ClickHouseTableBuilderTest
{
    private function builder(string $table = 'events'): ClickHouseTableBuilder
    {
        return new ClickHouseTableBuilder(new FakeClickHouseClient(), $table);
    }

    public function buildsMinimalCreateTable(): void
    {
        $sql = $this->builder()
            ->column('id', 'UInt64')
            ->column('name', 'String')
            ->engine('MergeTree()')
            ->orderBy('id')
            ->build();

        Assert::same($sql, 'CREATE TABLE events (id UInt64, name String) ENGINE = MergeTree() ORDER BY id');
    }

    public function buildsFullCreateTable(): void
    {
        $sql = ClickHouseTableBuilder::create(new FakeClickHouseClient(), 'analytics.events')
            ->ifNotExists()
            ->column('id', 'UInt64')
            ->column('created_at', 'DateTime')
            ->engine('ReplacingMergeTree(created_at)')
            ->partitionBy('toYYYYMM(created_at)')
            ->primaryKey('id')
            ->orderBy('(created_at, id)')
            ->build();

        Assert::same(
            $sql,
            'CREATE TABLE IF NOT EXISTS analytics.events (id UInt64, created_at DateTime) ENGINE = ReplacingMergeTree(created_at) PARTITION BY toYYYYMM(created_at) PRIMARY KEY id ORDER BY (created_at, id)',
        );
    }

    public function executeRunsBuiltSql(): void
    {
        $capturedQuery = null;
        $client = (new FakeClickHouseClient())->withExecuteQueryCallback(
            static function (string $query) use (&$capturedQuery): void {
                $capturedQuery = $query;
            },
        );

        (new ClickHouseTableBuilder($client, 'events'))
            ->column('id', 'UInt64')
            ->engine('Memory')
            ->execute();

        Assert::same($capturedQuery, 'CREATE TABLE events (id UInt64) ENGINE = Memory');
    }

    public function throwsWithoutColumns(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder()->engine('Memory')->build();
    }

    public function throwsWithoutEngine(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder()->column('id', 'UInt64')->build();
    }

    public function rejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseTableBuilder(new FakeClickHouseClient(), 'events; DROP TABLE x');
    }

    public function rejectsDbQualifiedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder()->column('events.id', 'UInt64');
    }
}
