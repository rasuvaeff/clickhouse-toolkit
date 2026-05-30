<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseTableBuilder;
use SimPod\ClickHouseClient\Client\ClickHouseClient;

#[CoversClass(ClickHouseTableBuilder::class)]
final class ClickHouseTableBuilderTest extends TestCase
{
    private function builder(string $table = 'events'): ClickHouseTableBuilder
    {
        return new ClickHouseTableBuilder($this->createMock(ClickHouseClient::class), $table);
    }

    #[Test]
    public function buildsMinimalCreateTable(): void
    {
        $sql = $this->builder()
            ->column('id', 'UInt64')
            ->column('name', 'String')
            ->engine('MergeTree()')
            ->orderBy('id')
            ->build();

        $this->assertSame('CREATE TABLE events (id UInt64, name String) ENGINE = MergeTree() ORDER BY id', $sql);
    }

    #[Test]
    public function buildsFullCreateTable(): void
    {
        $sql = ClickHouseTableBuilder::create($this->createMock(ClickHouseClient::class), 'analytics.events')
            ->ifNotExists()
            ->column('id', 'UInt64')
            ->column('created_at', 'DateTime')
            ->engine('ReplacingMergeTree(created_at)')
            ->partitionBy('toYYYYMM(created_at)')
            ->primaryKey('id')
            ->orderBy('(created_at, id)')
            ->build();

        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS analytics.events (id UInt64, created_at DateTime) ENGINE = ReplacingMergeTree(created_at) PARTITION BY toYYYYMM(created_at) PRIMARY KEY id ORDER BY (created_at, id)',
            $sql,
        );
    }

    #[Test]
    public function executeRunsBuiltSql(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->expects($this->once())
            ->method('executeQuery')
            ->with('CREATE TABLE events (id UInt64) ENGINE = Memory');

        (new ClickHouseTableBuilder($client, 'events'))
            ->column('id', 'UInt64')
            ->engine('Memory')
            ->execute();
    }

    #[Test]
    public function throwsWithoutColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->engine('Memory')->build();
    }

    #[Test]
    public function throwsWithoutEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->column('id', 'UInt64')->build();
    }

    #[Test]
    public function rejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseTableBuilder($this->createMock(ClickHouseClient::class), 'events; DROP TABLE x');
    }

    #[Test]
    public function rejectsDbQualifiedColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->column('events.id', 'UInt64');
    }
}
