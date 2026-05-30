<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHousePartitionManager;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;

#[CoversClass(ClickHousePartitionManager::class)]
final class ClickHousePartitionManagerTest extends TestCase
{
    /**
     * @return array{0: ClickHousePartitionManager, 1: \ArrayObject<int, string>}
     */
    private function manager(): array
    {
        /** @var \ArrayObject<int, string> $queries */
        $queries = new \ArrayObject();
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('executeQuery')->willReturnCallback(
            static function (string $query) use ($queries): void {
                $queries->append($query);
            },
        );

        return [new ClickHousePartitionManager($client), $queries];
    }

    #[Test]
    public function dropDetachAttachFreeze(): void
    {
        [$manager, $queries] = $this->manager();

        $manager->dropPartition('events', '202401');
        $manager->detachPartition('events', '202401');
        $manager->attachPartition('events', '202401');
        $manager->freezePartition('events', '202401');

        $this->assertSame([
            "ALTER TABLE events DROP PARTITION ID '202401'",
            "ALTER TABLE events DETACH PARTITION ID '202401'",
            "ALTER TABLE events ATTACH PARTITION ID '202401'",
            "ALTER TABLE events FREEZE PARTITION ID '202401'",
        ], $queries->getArrayCopy());
    }

    #[Test]
    public function escapesPartitionId(): void
    {
        [$manager, $queries] = $this->manager();

        $manager->dropPartition('events', "2024'01");

        $this->assertSame("ALTER TABLE events DROP PARTITION ID '2024\\'01'", $queries[0]);
    }

    #[Test]
    public function clearColumnInPartition(): void
    {
        [$manager, $queries] = $this->manager();

        $manager->clearColumnInPartition('events', '202401', 'payload');

        $this->assertSame("ALTER TABLE events CLEAR COLUMN payload IN PARTITION ID '202401'", $queries[0]);
    }

    #[Test]
    public function moveAndReplace(): void
    {
        [$manager, $queries] = $this->manager();

        $manager->movePartition('src', 'dst', '1');
        $manager->replacePartition('src', 'dst', '1');

        $this->assertSame([
            "ALTER TABLE src MOVE PARTITION ID '1' TO TABLE dst",
            "ALTER TABLE dst REPLACE PARTITION ID '1' FROM src",
        ], $queries->getArrayCopy());
    }

    #[Test]
    public function rejectsMalformedTable(): void
    {
        [$manager] = $this->manager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->dropPartition('events; DROP TABLE x', '1');
    }

    #[Test]
    public function rejectsMalformedColumn(): void
    {
        [$manager] = $this->manager();

        $this->expectException(\InvalidArgumentException::class);

        $manager->clearColumnInPartition('events', '1', 'payload; --');
    }

    #[Test]
    public function getPartitionsBindsTableAndParsesRows(): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static function (string $sql, array $params) use (&$capturedSql, &$capturedParams): Output {
                $capturedSql = $sql;
                $capturedParams = $params;

                return new JsonEachRowOutput(
                    '{"partition":"0","partition_id":"0","rows":"10","bytes":"2048"}' . "\n"
                    . '{"partition":"1","partition_id":"1","rows":"5","bytes":"1024"}',
                );
            },
        );

        $result = (new ClickHousePartitionManager($client))->getPartitions('events');

        $this->assertSame(['tbl' => 'events'], $capturedParams);
        $this->assertIsString($capturedSql);
        $this->assertStringContainsString('currentDatabase()', $capturedSql);
        $this->assertStringContainsString('table = {tbl:String}', $capturedSql);
        $this->assertSame([
            ['partition' => '0', 'partition_id' => '0', 'rows' => 10, 'bytes' => 2048],
            ['partition' => '1', 'partition_id' => '1', 'rows' => 5, 'bytes' => 1024],
        ], $result);
    }

    #[Test]
    public function getPartitionsBindsDatabaseForQualifiedTable(): void
    {
        $capturedParams = null;
        $capturedSql = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static function (string $sql, array $params) use (&$capturedParams, &$capturedSql): Output {
                $capturedSql = $sql;
                $capturedParams = $params;

                return new JsonEachRowOutput('');
            },
        );

        (new ClickHousePartitionManager($client))->getPartitions('analytics.events');

        $this->assertStringContainsString('database = {db:String}', (string) $capturedSql);
        $this->assertSame(['db' => 'analytics', 'tbl' => 'events'], $capturedParams);
    }
}
