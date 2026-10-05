<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseMutationBuilder;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
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
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->update(
            'events',
            'status = {st:String}',
            'id = {id:UInt64}',
            ['st' => 'x', 'id' => 2],
        );

        $execution = Clients::parameterisedExecutions($client)[0];
        Assert::same($execution->arg('query'), 'ALTER TABLE events UPDATE status = {st:String} WHERE id = {id:UInt64}');
        Assert::same($execution->arg('params'), ['st' => 'x', 'id' => 2]);
    }

    public function deleteBuildsSqlAndBindsParams(): void
    {
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->delete('events', 'id = {id:UInt64}', ['id' => 1]);

        Assert::same(Clients::parameterisedExecutions($client)[0]->arg('query'), 'ALTER TABLE events DELETE WHERE id = {id:UInt64}');
    }

    public function getMutationsParsesRows(): void
    {
        $client = Clients::plain(new JsonEachRowOutput(
            '{"mutation_id":"m1","command":"UPDATE status = ...","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
        ));

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
        $client = Clients::plain(new JsonEachRowOutput(
            '{"mutation_id":"m1","command":"c","is_done":"1","parts_to_do":"0","latest_fail_reason":""}',
        ));

        Assert::true((new ClickHouseMutationBuilder($client))->waitForMutations('events', 5.0));
    }

    public function waitForMutationsTimesOutWhilePending(): void
    {
        $client = Clients::plain(new JsonEachRowOutput(
            '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"3","latest_fail_reason":""}',
        ));

        Assert::false((new ClickHouseMutationBuilder($client))->waitForMutations('events', 0.0));
    }

    public function killMutationEscapesArguments(): void
    {
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->killMutation('events', "m'1");

        Assert::same(
            Clients::executedQueries($client)[0],
            "KILL MUTATION WHERE database = currentDatabase() AND table = 'events' AND mutation_id = 'm\\'1'",
        );
    }

    public function updateRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(Clients::plain()))
            ->update('events; DROP TABLE x', 'a = {a:UInt8}', '1', ['a' => 1]);
    }

    public function deleteRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(Clients::plain()))
            ->delete('events; DROP TABLE x', '1');
    }

    public function getMutationsRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(Clients::plain()))
            ->getMutations('events; DROP TABLE x');
    }

    public function killMutationRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        (new ClickHouseMutationBuilder(Clients::plain()))
            ->killMutation('events; DROP TABLE x', 'm1');
    }

    public function getMutationsBuildsSqlAndBindsTable(): void
    {
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->getMutations('events');

        $select = Clients::parameterisedSelects($client)[0];
        Assert::same(
            $select->arg('query'),
            'SELECT mutation_id, command, is_done, parts_to_do, latest_fail_reason '
            . 'FROM system.mutations WHERE database = currentDatabase() AND table = {tbl:String} '
            . 'ORDER BY create_time DESC',
        );
        Assert::same($select->arg('params'), ['tbl' => 'events']);
    }

    public function getMutationsForQualifiedTableBindsDatabaseAndTable(): void
    {
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->getMutations('analytics.events');

        $select = Clients::parameterisedSelects($client)[0];
        Assert::string($select->arg('query'))->contains('database = {db:String} AND table = {tbl:String}');
        Assert::same($select->arg('params'), ['db' => 'analytics', 'tbl' => 'events']);
    }

    public function getMutationsCastsParated(): void
    {
        $client = Clients::plain(new JsonEachRowOutput(
            '{"mutation_id":"m1","command":"c","is_done":"0","parts_to_do":"7","latest_fail_reason":"boom"}',
        ));

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
        $client = Clients::plain();

        (new ClickHouseMutationBuilder($client))->killMutation('analytics.events', 'm1');

        Assert::same(
            Clients::executedQueries($client)[0],
            "KILL MUTATION WHERE database = 'analytics' AND table = 'events' AND mutation_id = 'm1'",
        );
    }
}
