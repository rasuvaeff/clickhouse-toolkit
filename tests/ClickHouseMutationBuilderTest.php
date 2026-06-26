<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHouseMutationBuilder::class)]
final class ClickHouseMutationBuilderTest
{
    public function updateBuildsSqlAndBindsParams(): void
    {
        $sql = null;
        $params = null;
        $client = (new FakeClickHouseClient())->withExecuteQueryWithParamsCallback(
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

        Assert::same($sql, 'ALTER TABLE events UPDATE status = {st:String} WHERE id = {id:UInt64}');
        Assert::same($params, ['st' => 'x', 'id' => 2]);
    }

    public function deleteBuildsSqlAndBindsParams(): void
    {
        $sql = null;
        $client = (new FakeClickHouseClient())->withExecuteQueryWithParamsCallback(
            static function (string $q) use (&$sql): void {
                $sql = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->delete('events', 'id = {id:UInt64}', ['id' => 1]);

        Assert::same($sql, 'ALTER TABLE events DELETE WHERE id = {id:UInt64}');
    }

    public function getMutationsParsesRows(): void
    {
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"UPDATE status = ...","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
            ),
        );

        $mutations = (new ClickHouseMutationBuilder($client))->getMutations('events');

        Assert::same($mutations, [[
            'mutation_id' => 'm1',
            'command' => 'UPDATE status = ...',
            'is_done' => true,
            'parts_to_do' => 0,
            'latest_fail_reason' => '',
        ]]);
    }

    public function waitForMutationsReturnsTrueWhenAllDone(): void
    {
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
            ),
        );

        Assert::true((new ClickHouseMutationBuilder($client))->waitForMutations('events', 5.0));
    }

    public function waitForMutationsTimesOutWhilePending(): void
    {
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"3","latest_fail_reason":""}',
            ),
        );

        Assert::false((new ClickHouseMutationBuilder($client))->waitForMutations('events', 0.0));
    }

    public function killMutationEscapesArguments(): void
    {
        $query = null;
        $client = (new FakeClickHouseClient())->withExecuteQueryCallback(
            static function (string $q) use (&$query): void {
                $query = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->killMutation('events', "m'1");

        Assert::same(
            $query,
            "KILL MUTATION WHERE database = currentDatabase() AND table = 'events' AND mutation_id = 'm\\'1'",
        );
    }

    public function updateRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(new FakeClickHouseClient()))
            ->update('events; DROP TABLE x', 'a = {a:UInt8}', '1', ['a' => 1]);
    }

    public function deleteRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(new FakeClickHouseClient()))
            ->delete('events; DROP TABLE x', '1');
    }

    public function getMutationsRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(new FakeClickHouseClient()))
            ->getMutations('events; DROP TABLE x');
    }

    public function killMutationRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(new FakeClickHouseClient()))
            ->killMutation('events; DROP TABLE x', 'm1');
    }

    public function getMutationsBuildsSqlAndBindsTable(): void
    {
        $sql = null;
        $params = null;
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static function (string $q, array $p) use (&$sql, &$params): Output {
                $sql = $q;
                $params = $p;

                return new JsonEachRowOutput('');
            },
        );

        (new ClickHouseMutationBuilder($client))->getMutations('events');

        Assert::same(
            $sql,
            'SELECT mutation_id, command, is_done, parts_to_do, latest_fail_reason '
            . 'FROM system.mutations WHERE database = currentDatabase() AND table = {tbl:String} '
            . 'ORDER BY create_time DESC',
        );
        Assert::same($params, ['tbl' => 'events']);
    }

    public function getMutationsForQualifiedTableBindsDatabaseAndTable(): void
    {
        $sql = null;
        $params = null;
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static function (string $q, array $p) use (&$sql, &$params): Output {
                $sql = $q;
                $params = $p;

                return new JsonEachRowOutput('');
            },
        );

        (new ClickHouseMutationBuilder($client))->getMutations('analytics.events');

        Assert::string($sql)->contains('database = {db:String} AND table = {tbl:String}');
        Assert::same($params, ['db' => 'analytics', 'tbl' => 'events']);
    }

    public function getMutationsCastsParated(): void
    {
        $client = (new FakeClickHouseClient())->withSelectWithParamsCallback(
            static fn(): Output => new JsonEachRowOutput(
                '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"7","latest_fail_reason":"boom"}',
            ),
        );

        Assert::same((new ClickHouseMutationBuilder($client))->getMutations('events'), [[
            'mutation_id' => 'm1',
            'command' => 'c',
            'is_done' => false,
            'parts_to_do' => 7,
            'latest_fail_reason' => 'boom',
        ]]);
    }

    public function killMutationForQualifiedTableScopesDatabase(): void
    {
        $query = null;
        $client = (new FakeClickHouseClient())->withExecuteQueryCallback(
            static function (string $q) use (&$query): void {
                $query = $q;
            },
        );

        (new ClickHouseMutationBuilder($client))->killMutation('analytics.events', 'm1');

        Assert::same(
            $query,
            "KILL MUTATION WHERE database = 'analytics' AND table = 'events' AND mutation_id = 'm1'",
        );
    }
}
