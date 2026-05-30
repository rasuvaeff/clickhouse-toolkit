<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;

#[CoversClass(ClickHouseMutationBuilder::class)]
final class ClickHouseMutationBuilderTest extends TestCase
{
    #[Test]
    public function updateBuildsSqlAndBindsParams(): void
    {
        $sql = null;
        $params = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('executeQueryWithParams')->willReturnCallback(
            static function (string $q, array $p) use (&$sql, &$params): void {
                $sql = $q;
                $params = $p;
            },
        );

        (new ClickHouseMutationBuilder($client))->update(
            'events',
            'status = {st:String}',
            'id = {id:UInt64}',
            ['st' => 'x', 'id' => 2],
        );

        $this->assertSame('ALTER TABLE events UPDATE status = {st:String} WHERE id = {id:UInt64}', $sql);
        $this->assertSame(['st' => 'x', 'id' => 2], $params);
    }

    #[Test]
    public function deleteBuildsSqlAndBindsParams(): void
    {
        $sql = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('executeQueryWithParams')->willReturnCallback(
            static function (string $q) use (&$sql): void {
                $sql = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->delete('events', 'id = {id:UInt64}', ['id' => 1]);

        $this->assertSame('ALTER TABLE events DELETE WHERE id = {id:UInt64}', $sql);
    }

    #[Test]
    public function getMutationsParsesRows(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"UPDATE status = ...","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
            ),
        );

        $mutations = (new ClickHouseMutationBuilder($client))->getMutations('events');

        $this->assertSame([[
            'mutation_id' => 'm1',
            'command' => 'UPDATE status = ...',
            'is_done' => true,
            'parts_to_do' => 0,
            'latest_fail_reason' => '',
        ]], $mutations);
    }

    #[Test]
    public function waitForMutationsReturnsTrueWhenAllDone(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
            ),
        );

        $this->assertTrue((new ClickHouseMutationBuilder($client))->waitForMutations('events', 5.0));
    }

    #[Test]
    public function waitForMutationsTimesOutWhilePending(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"3","latest_fail_reason":""}',
            ),
        );

        $this->assertFalse((new ClickHouseMutationBuilder($client))->waitForMutations('events', 0.0));
    }

    #[Test]
    public function killMutationEscapesArguments(): void
    {
        $query = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('executeQuery')->willReturnCallback(
            static function (string $q) use (&$query): void {
                $query = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->killMutation('events', "m'1");

        $this->assertSame(
            "KILL MUTATION WHERE database = currentDatabase() AND table = 'events' AND mutation_id = 'm\\'1'",
            $query,
        );
    }

    #[Test]
    public function updateRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClickHouseMutationBuilder($this->createMock(ClickHouseClient::class)))
            ->update('events; DROP TABLE x', 'a = {a:UInt8}', '1', ['a' => 1]);
    }
}
