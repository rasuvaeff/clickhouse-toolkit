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

    #[Test]
    public function deleteRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClickHouseMutationBuilder($this->createMock(ClickHouseClient::class)))
            ->delete('events; DROP TABLE x', '1');
    }

    #[Test]
    public function getMutationsRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClickHouseMutationBuilder($this->createMock(ClickHouseClient::class)))
            ->getMutations('events; DROP TABLE x');
    }

    #[Test]
    public function killMutationRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClickHouseMutationBuilder($this->createMock(ClickHouseClient::class)))
            ->killMutation('events; DROP TABLE x', 'm1');
    }

    #[Test]
    public function getMutationsBuildsSqlAndBindsTable(): void
    {
        $sql = null;
        $params = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static function (string $q, array $p) use (&$sql, &$params): Output {
                $sql = $q;
                $params = $p;

                return new JsonEachRowOutput('');
            },
        );

        (new ClickHouseMutationBuilder($client))->getMutations('events');

        $this->assertSame(
            'SELECT mutation_id, command, is_done, parts_to_do, latest_fail_reason '
            . 'FROM system.mutations WHERE database = currentDatabase() AND table = {tbl:String} '
            . 'ORDER BY create_time DESC',
            $sql,
        );
        $this->assertSame(['tbl' => 'events'], $params);
    }

    #[Test]
    public function getMutationsForQualifiedTableBindsDatabaseAndTable(): void
    {
        $sql = null;
        $params = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static function (string $q, array $p) use (&$sql, &$params): Output {
                $sql = $q;
                $params = $p;

                return new JsonEachRowOutput('');
            },
        );

        (new ClickHouseMutationBuilder($client))->getMutations('analytics.events');

        $this->assertStringContainsString('database = {db:String} AND table = {tbl:String}', (string) $sql);
        $this->assertSame(['db' => 'analytics', 'tbl' => 'events'], $params);
    }

    #[Test]
    public function getMutationsCastsParated(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('selectWithParams')->willReturnCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"7","latest_fail_reason":"boom"}',
            ),
        );

        $this->assertSame([[
            'mutation_id' => 'm1',
            'command' => 'c',
            'is_done' => false,
            'parts_to_do' => 7,
            'latest_fail_reason' => 'boom',
        ]], (new ClickHouseMutationBuilder($client))->getMutations('events'));
    }

    #[Test]
    public function killMutationForQualifiedTableScopesDatabase(): void
    {
        $query = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('executeQuery')->willReturnCallback(
            static function (string $q) use (&$query): void {
                $query = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->killMutation('analytics.events', 'm1');

        $this->assertSame(
            "KILL MUTATION WHERE database = 'analytics' AND table = 'events' AND mutation_id = 'm1'",
            $query,
        );
    }
}
