<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use SimPod\ClickHouseClient\Schema\Table;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ClickHouseBatchWriter::class)]
#[Covers(ClickHouseWriteException::class)]
final class ClickHouseBatchWriterTest
{
    public function splitsRowsIntoFixedSizeBatches(): void
    {
        $sizes = [];
        $tables = [];
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $table, array $values) use (&$sizes, &$tables): void {
                $tables[] = $table;
                $sizes[] = count($values);
            },
        );

        $writer = new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 1000);
        $writer->write($this->rows(2500));

        Assert::same($sizes, [1000, 1000, 500]);
        Assert::same($tables, ['events', 'events', 'events']);
    }

    public function projectsRowsOntoDeclaredColumns(): void
    {
        $captured = [];
        $columns = [];
        $table = null;
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $t, array $values, ?array $cols) use (&$captured, &$columns, &$table): void {
                $table = $t;
                $captured = $values;
                $columns = $cols;
            },
        );

        $writer = new ClickHouseBatchWriter($client, 'events', ['id', 'name', 'missing']);
        $writer->write([['id' => 1, 'name' => 'a', 'extra' => 'ignored']]);

        Assert::same($table, 'events');
        Assert::same($captured, [['id' => 1, 'name' => 'a', 'missing' => null]]);
        Assert::same($columns, ['id', 'name', 'missing']);
    }

    public function doesNotInsertWhenNoRows(): void
    {
        $insertCalled = false;
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function () use (&$insertCalled): void {
                $insertCalled = true;
            },
        );

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([]);

        Assert::false($insertCalled);
    }

    public function allowsBatchSizeOne(): void
    {
        $sizes = [];
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $table, array $values) use (&$sizes): void {
                $sizes[] = count($values);
            },
        );

        (new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 1))->write([['id' => 1], ['id' => 2]]);

        Assert::same($sizes, [1, 1]);
    }

    public function defaultBatchSizeIsOneThousand(): void
    {
        $sizes = [];
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $table, array $values) use (&$sizes): void {
                $sizes[] = count($values);
            },
        );

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write($this->rows(1001));

        Assert::same($sizes, [1000, 1]);
    }

    public function rejectsNonPositiveBatchSize(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(new FakeClickHouseClient(), 'events', ['id'], batchSize: 0);
    }

    public function rejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(new FakeClickHouseClient(), 'events; DROP TABLE x', ['id']);
    }

    public function rejectsMalformedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(new FakeClickHouseClient(), 'events', ['id', 'name) --']);
    }

    public function rejectsDbQualifiedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseBatchWriter(new FakeClickHouseClient(), 'events', ['events.id']);
    }

    public function plainTableIsPassedAsString(): void
    {
        $captured = 'unset';
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $table) use (&$captured): void {
                $captured = $table;
            },
        );

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([['id' => 1]]);

        Assert::same($captured, 'events');
    }

    public function dbQualifiedTableIsSplitIntoDatabaseAndName(): void
    {
        $captured = null;
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (Table|string $table) use (&$captured): void {
                $captured = $table;
            },
        );

        (new ClickHouseBatchWriter($client, 'analytics.events', ['id']))->write([['id' => 1]]);

        Assert::instanceOf($captured, Table::class);
        Assert::same($captured->name, 'events');
        Assert::same($captured->database, 'analytics');
    }

    public function wrapsClientFailures(): void
    {
        $client = (new FakeClickHouseClient())->withInsertCallback(
            static function (): void {
                throw new \RuntimeException('connection refused');
            },
        );

        $writer = new ClickHouseBatchWriter($client, 'events', ['id']);

        Expect::exception(ClickHouseWriteException::class);

        $writer->write([['id' => 1]]);
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
