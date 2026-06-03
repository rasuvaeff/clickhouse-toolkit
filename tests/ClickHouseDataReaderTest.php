<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

#[CoversClass(ClickHouseDataReader::class)]
final class ClickHouseDataReaderTest extends TestCase
{
    private function queryBuilder(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status'],
            defaultSort: 'id DESC',
        );
    }

    /**
     * @param list<array<string, mixed>> $returnRows
     * @return ClickHouseDataReader<array<string, mixed>>
     */
    private function reader(array $returnRows, ?string &$capturedSql = null, ?array &$capturedParams = null): ClickHouseDataReader
    {
        $client = $this->createMock(ClickHouseClient::class);
        $output = $this->makeOutput($returnRows);

        $client->method('select')->willReturnCallback(
            static function (string $sql) use ($output, &$capturedSql): Output {
                $capturedSql = $sql;

                return $output;
            },
        );
        $client->method('selectWithParams')->willReturnCallback(
            static function (string $sql, array $params) use ($output, &$capturedSql, &$capturedParams): Output {
                $capturedSql = $sql;
                $capturedParams = $params;

                return $output;
            },
        );

        return new ClickHouseDataReader(
            client: $client,
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => $row,
            columns: ['id', 'status'],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function makeOutput(array $rows): Output
    {
        $lines = array_map(static fn(array $row): string => (string) json_encode($row), $rows);

        return new JsonEachRowOutput(implode("\n", $lines));
    }

    #[Test]
    public function readReturnsMappedRowsWithPagination(): void
    {
        $sql = null;
        $params = null;
        $reader = $this->reader([['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'active']], $sql, $params)
            ->withFilter(new Equals('status', 'active'))
            ->withSort(Sort::only(['id'])->withOrder(['id' => 'asc']))
            ->withLimit(10)
            ->withOffset(20);

        $rows = $reader->read();

        $this->assertSame([['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'active']], $rows);
        $this->assertSame('SELECT id, status FROM events WHERE status = {p0:String} ORDER BY id ASC LIMIT 10 OFFSET 20', $sql);
        $this->assertSame(['p0' => 'active'], $params);
    }

    #[Test]
    public function readWithoutLimitOmitsLimitClause(): void
    {
        $sql = null;
        $reader = $this->reader([], $sql);

        $reader->read();

        $this->assertIsString($sql);
        $this->assertStringContainsString('SELECT id, status FROM events ORDER BY id DESC', $sql);
        $this->assertStringNotContainsString('LIMIT', $sql, 'Без явного лимита LIMIT не добавляется');
    }

    #[Test]
    public function readOneReturnsFirstRow(): void
    {
        $sql = null;
        $reader = $this->reader([['id' => 7, 'status' => 'x']], $sql);

        $this->assertSame(['id' => 7, 'status' => 'x'], $reader->readOne());
        $this->assertIsString($sql);
        $this->assertStringContainsString('LIMIT 1', $sql);
    }

    #[Test]
    public function readOneReturnsNullWhenEmpty(): void
    {
        $this->assertNull($this->reader([])->readOne());
    }

    #[Test]
    public function countReturnsInteger(): void
    {
        $sql = null;
        $reader = $this->reader([['cnt' => 42]], $sql);

        $this->assertSame(42, $reader->count());
        $this->assertIsString($sql);
        $this->assertStringContainsString('SELECT count() AS cnt FROM events', $sql);
    }

    #[Test]
    public function withMethodsAreImmutable(): void
    {
        $reader = $this->reader([]);
        $modified = $reader->withLimit(5)->withOffset(10);

        $this->assertNull($reader->getLimit(), 'Оригинал не должен меняться');
        $this->assertSame(0, $reader->getOffset());
        $this->assertSame(5, $modified->getLimit());
        $this->assertSame(10, $modified->getOffset());
    }

    #[Test]
    public function withFilterReturnsNewReader(): void
    {
        $reader = $this->reader([]);

        $this->assertNotSame($reader, $reader->withFilter(new Equals('status', 'active')));
    }

    #[Test]
    public function withSortReturnsNewReader(): void
    {
        $reader = $this->reader([]);

        $this->assertNotSame($reader, $reader->withSort(Sort::only(['id'])->withOrder(['id' => 'asc'])));
    }

    #[Test]
    public function getIteratorYieldsRows(): void
    {
        $reader = $this->reader([['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']]);

        $this->assertSame(
            [['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']],
            iterator_to_array($reader->getIterator()),
        );
    }

    #[Test]
    public function negativeLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->reader([])->withLimit(-1);
    }

    #[Test]
    public function negativeOffsetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->reader([])->withOffset(-1);
    }

    #[Test]
    public function limitZeroIsAllowed(): void
    {
        $this->assertSame(0, $this->reader([])->withLimit(0)->getLimit());
    }

    #[Test]
    public function offsetZeroIsAllowed(): void
    {
        $this->assertSame(0, $this->reader([])->withOffset(0)->getOffset());
    }

    #[Test]
    public function withOffsetReturnsNewReaderAndKeepsOriginal(): void
    {
        $reader = $this->reader([]);
        $modified = $reader->withOffset(7);

        $this->assertNotSame($reader, $modified);
        $this->assertSame(0, $reader->getOffset(), 'Оригинал не должен меняться');
        $this->assertSame(7, $modified->getOffset());
    }

    #[Test]
    public function countAppliesFilterViaSelectWithParams(): void
    {
        $sql = null;
        $params = null;
        $reader = $this->reader([['cnt' => 5]], $sql, $params)
            ->withFilter(new Equals('status', 'active'));

        $this->assertSame(5, $reader->count());
        $this->assertSame(['p0' => 'active'], $params, 'count с фильтром должен идти через selectWithParams');
        $this->assertIsString($sql);
        $this->assertStringContainsString('WHERE status = {p0:String}', $sql);
    }

    #[Test]
    public function countReturnsZeroWhenNoRows(): void
    {
        $this->assertSame(0, $this->reader([])->count());
    }

    #[Test]
    public function countClampsNegativeCountToZero(): void
    {
        $this->assertSame(0, $this->reader([['cnt' => -3]])->count());
    }

    #[Test]
    public function countCastsStringCountToInt(): void
    {
        $this->assertSame(42, $this->reader([['cnt' => '42']])->count());
    }

    #[Test]
    public function readAppliesMapperToEachRow(): void
    {
        $client = $this->createMock(ClickHouseClient::class);
        $client->method('select')->willReturn(
            $this->makeOutput([['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']]),
        );

        $reader = new ClickHouseDataReader(
            client: $client,
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => ['ref' => 'r' . (int) $row['id']],
            columns: ['id', 'status'],
        );

        $this->assertSame([['ref' => 'r1'], ['ref' => 'r2']], $reader->read());
    }
}
