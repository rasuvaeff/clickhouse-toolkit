<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Schema\Table;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ClickHouseBatchWriter::class)]
#[Covers(ClickHouseWriteException::class)]
final class ClickHouseBatchWriterTest
{
    public function splitsRowsIntoFixedSizeBatches(): void
    {
        $client = Clients::plain();

        $writer = new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 1000);
        $writer->write($this->rows(2500));

        Assert::same($this->batchSizes($client), [1000, 1000, 500]);
        Assert::same(array_map(Clients::insertedTable(...), Clients::inserts($client)), ['events', 'events', 'events']);
    }

    public function projectsRowsOntoDeclaredColumns(): void
    {
        $client = Clients::plain();

        $writer = new ClickHouseBatchWriter($client, 'events', ['id', 'name', 'missing']);
        $writer->write([['id' => 1, 'name' => 'a', 'extra' => 'ignored']]);

        $insert = Clients::inserts($client)[0];
        Assert::same(Clients::insertedTable($insert), 'events');
        Assert::same($insert->arg('values'), [['id' => 1, 'name' => 'a', 'missing' => null]]);
        Assert::same($insert->arg('columns'), ['id', 'name', 'missing']);
    }

    public function doesNotInsertWhenNoRows(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([]);

        verify(fn() => $client->insert(Arg::any(), Arg::any()), never: true);
    }

    public function allowsBatchSizeOne(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 1))->write([['id' => 1], ['id' => 2]]);

        Assert::same($this->batchSizes($client), [1, 1]);
    }

    public function defaultBatchSizeIsOneThousand(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write($this->rows(1001));

        Assert::same($this->batchSizes($client), [1000, 1]);
    }

    public function rejectsNonPositiveBatchSize(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(Clients::plain(), 'events', ['id'], batchSize: 0);
    }

    public function rejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(Clients::plain(), 'events; DROP TABLE x', ['id']);
    }

    public function rejectsMalformedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(Clients::plain(), 'events', ['id', 'name) --']);
    }

    public function rejectsDbQualifiedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(Clients::plain(), 'events', ['events.id']);
    }

    public function plainTableIsPassedAsString(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([['id' => 1]]);

        Assert::same(Clients::insertedTable(Clients::inserts($client)[0]), 'events');
    }

    public function dbQualifiedTableIsSplitIntoDatabaseAndName(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'analytics.events', ['id']))->write([['id' => 1]]);

        $captured = Clients::insertedTable(Clients::inserts($client)[0]);
        Assert::instanceOf($captured, Table::class);
        Assert::same($captured->name, 'events');
        Assert::same($captured->database, 'analytics');
    }

    public function appliesSettingsToEveryBatch(): void
    {
        $client = Clients::plain();

        $writer = new ClickHouseBatchWriter(
            $client,
            'events',
            ['id'],
            batchSize: 1000,
            settings: ['async_insert' => 1, 'wait_for_async_insert' => 0],
        );
        $writer->write($this->rows(1500));

        Assert::same(array_map(static fn(Invocation $insert): mixed => $insert->arg('settings'), Clients::inserts($client)), [
            ['async_insert' => 1, 'wait_for_async_insert' => 0],
            ['async_insert' => 1, 'wait_for_async_insert' => 0],
        ]);
    }

    public function defaultsToEmptySettings(): void
    {
        $client = Clients::plain();

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([['id' => 1]]);

        Assert::same(Clients::inserts($client)[0]->arg('settings'), []);
    }

    public function wrapsClientFailures(): void
    {
        $client = Clients::plain();
        when(fn() => $client->insert(Arg::any(), Arg::any()))->throws(new \RuntimeException('connection refused'));

        $writer = new ClickHouseBatchWriter($client, 'events', ['id']);

        Expect::exception(ClickHouseWriteException::class);

        $writer->write([['id' => 1]]);
    }

    /**
     * @return list<int>
     */
    private function batchSizes(ClickHouseClient $client): array
    {
        return array_map(
            static fn(Invocation $insert): int => count($insert->arg('values')),
            Clients::inserts($client),
        );
    }

    /**
     * @return iterable<array{id: int}>
     */
    private function rows(int $count): iterable
    {
        for ($i = 0; $i < $count; $i++) {
            yield ['id' => $i];
        }
    }
}
