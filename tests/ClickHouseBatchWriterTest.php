<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseBatchWriter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseWriteException;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Schema\Table;

#[CoversClass(ClickHouseBatchWriter::class)]
#[CoversClass(ClickHouseWriteException::class)]
final class ClickHouseBatchWriterTest extends TestCase
{
    #[Test]
    public function splitsRowsIntoFixedSizeBatches(): void
    {
        $sizes = [];
        $tables = [];
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('insert')->willReturnCallback(
            static function (string $table, array $values) use (&$sizes, &$tables): void {
                $tables[] = $table;
                $sizes[] = count($values);
            },
        );

        $writer = new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 1000);
        $writer->write($this->rows(2500));

        $this->assertSame([1000, 1000, 500], $sizes);
        $this->assertSame(['events', 'events', 'events'], $tables);
    }

    #[Test]
    public function projectsRowsOntoDeclaredColumns(): void
    {
        $captured = [];
        $columns = [];
        $table = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('insert')->willReturnCallback(
            static function (string $t, array $values, ?array $cols) use (&$captured, &$columns, &$table): void {
                $table = $t;
                $captured = $values;
                $columns = $cols;
            },
        );

        $writer = new ClickHouseBatchWriter($client, 'events', ['id', 'name', 'missing']);
        $writer->write([['id' => 1, 'name' => 'a', 'extra' => 'ignored']]);

        $this->assertSame('events', $table);
        $this->assertSame([['id' => 1, 'name' => 'a', 'missing' => null]], $captured);
        $this->assertSame(['id', 'name', 'missing'], $columns);
    }

    #[Test]
    public function doesNotInsertWhenNoRows(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->expects($this->never())->method('insert');

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([]);
    }

    #[Test]
    public function rejectsNonPositiveBatchSize(): void
    {
        $client = $this->createMock(ClickHouseClient::class);

        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseBatchWriter($client, 'events', ['id'], batchSize: 0);
    }

    #[Test]
    public function rejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseBatchWriter($this->createMock(ClickHouseClient::class), 'events; DROP TABLE x', ['id']);
    }

    #[Test]
    public function rejectsMalformedColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseBatchWriter($this->createMock(ClickHouseClient::class), 'events', ['id', 'name) --']);
    }

    #[Test]
    public function rejectsDbQualifiedColumn(): void
    {
        // A dotted column name is invalid in an INSERT column list.
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseBatchWriter($this->createMock(ClickHouseClient::class), 'events', ['events.id']);
    }

    #[Test]
    public function plainTableIsPassedAsString(): void
    {
        $captured = 'unset';
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('insert')->willReturnCallback(
            static function (Table|string $table) use (&$captured): void {
                $captured = $table;
            },
        );

        (new ClickHouseBatchWriter($client, 'events', ['id']))->write([['id' => 1]]);

        $this->assertSame('events', $captured);
    }

    #[Test]
    public function dbQualifiedTableIsSplitIntoDatabaseAndName(): void
    {
        $captured = null;
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('insert')->willReturnCallback(
            static function (Table|string $table) use (&$captured): void {
                $captured = $table;
            },
        );

        (new ClickHouseBatchWriter($client, 'analytics.events', ['id']))->write([['id' => 1]]);

        $this->assertInstanceOf(Table::class, $captured);
        $this->assertSame('events', $captured->name);
        $this->assertSame('analytics', $captured->database);
    }

    #[Test]
    public function wrapsClientFailures(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('insert')->willThrowException(new \RuntimeException('connection refused'));

        $writer = new ClickHouseBatchWriter($client, 'events', ['id']);

        $this->expectException(ClickHouseWriteException::class);
        $this->expectExceptionMessageMatches('/Failed to insert 1 row\(s\) into "events"/');

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
