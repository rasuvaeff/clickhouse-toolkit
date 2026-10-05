<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHousePartitionManager;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHousePartitionManager::class)]
final class ClickHousePartitionManagerTest
{
    /**
     * @return array{0: ClickHousePartitionManager, 1: ClickHouseClient}
     */
    private function manager(): array
    {
        $client = Clients::plain();

        return [new ClickHousePartitionManager($client), $client];
    }

    public function dropDetachAttachFreeze(): void
    {
        [$manager, $client] = $this->manager();

        $manager->dropPartition('events', '202401');
        $manager->detachPartition('events', '202401');
        $manager->attachPartition('events', '202401');
        $manager->freezePartition('events', '202401');

        Assert::same(Clients::executedQueries($client), [
            "ALTER TABLE events DROP PARTITION ID '202401'",
            "ALTER TABLE events DETACH PARTITION ID '202401'",
            "ALTER TABLE events ATTACH PARTITION ID '202401'",
            "ALTER TABLE events FREEZE PARTITION ID '202401'",
        ]);
    }

    public function escapesPartitionId(): void
    {
        [$manager, $client] = $this->manager();

        $manager->dropPartition('events', "2024'01");

        Assert::same(Clients::executedQueries($client)[0], "ALTER TABLE events DROP PARTITION ID '2024\\'01'");
    }

    public function clearColumnInPartition(): void
    {
        [$manager, $client] = $this->manager();

        $manager->clearColumnInPartition('events', '202401', 'payload');

        Assert::same(Clients::executedQueries($client)[0], "ALTER TABLE events CLEAR COLUMN payload IN PARTITION ID '202401'");
    }

    public function moveAndReplace(): void
    {
        [$manager, $client] = $this->manager();

        $manager->movePartition('src', 'dst', '1');
        $manager->replacePartition('src', 'dst', '1');

        Assert::same(Clients::executedQueries($client), [
            "ALTER TABLE src MOVE PARTITION ID '1' TO TABLE dst",
            "ALTER TABLE dst REPLACE PARTITION ID '1' FROM src",
        ]);
    }

    public function rejectsMalformedTable(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->dropPartition('events; DROP TABLE x', '1');
    }

    public function rejectsMalformedColumn(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->clearColumnInPartition('events', '1', 'payload; --');
    }

    public function getPartitionsBindsTableAndParsesRows(): void
    {
        $client = Clients::plain(new JsonEachRowOutput(
            '{"partition":"0","partition_id":"0","rows":"10","bytes":"2048"}' . "\n"
            . '{"partition":"1","partition_id":"1","rows":"5","bytes":"1024"}',
        ));

        $result = (new ClickHousePartitionManager($client))->getPartitions('events');

        $select = Clients::parameterisedSelects($client)[0];
        Assert::same($select->arg('params'), ['tbl' => 'events']);
        Assert::same(
            $select->arg('query'),
            'SELECT partition, partition_id, sum(rows) AS rows, sum(bytes_on_disk) AS bytes '
            . 'FROM system.parts WHERE active AND database = currentDatabase() AND table = {tbl:String} '
            . 'GROUP BY partition, partition_id ORDER BY partition',
        );
        Assert::same($result, [
            ['partition' => '0', 'partition_id' => '0', 'rows' => 10, 'bytes' => 2048],
            ['partition' => '1', 'partition_id' => '1', 'rows' => 5, 'bytes' => 1024],
        ]);
    }

    public function getPartitionsRejectsMalformedTable(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->getPartitions('events; DROP TABLE x');
    }

    public function getPartitionsRejectsMalformedQualifiedTable(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->getPartitions('analytics.events; DROP TABLE x');
    }

    public function movePartitionRejectsMalformedTargetTable(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->movePartition('src', 'dst; DROP TABLE x', '1');
    }

    public function replacePartitionRejectsMalformedSourceTable(): void
    {
        [$manager] = $this->manager();

        Expect::exception(InvalidArgumentException::class);

        $manager->replacePartition('src; DROP TABLE x', 'dst', '1');
    }

    public function getPartitionsBindsDatabaseForQualifiedTable(): void
    {
        $client = Clients::plain();

        (new ClickHousePartitionManager($client))->getPartitions('analytics.events');

        $select = Clients::parameterisedSelects($client)[0];
        Assert::string($select->arg('query'))->contains('database = {db:String}');
        Assert::same($select->arg('params'), ['db' => 'analytics', 'tbl' => 'events']);
    }
}
