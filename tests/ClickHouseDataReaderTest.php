<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseDataReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

#[Test]
#[Covers(ClickHouseDataReader::class)]
final class ClickHouseDataReaderTest
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
        $output = $this->makeOutput($returnRows);

        $client = (new FakeClickHouseClient())
            ->withSelectCallback(
                static function (string $sql) use ($output, &$capturedSql): Output {
                    $capturedSql = $sql;

                    return $output;
                },
            )
            ->withSelectWithParamsCallback(
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

        Assert::same($rows, [['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'active']]);
        Assert::same($sql, 'SELECT id, status FROM events WHERE status = {p0:String} ORDER BY id ASC LIMIT 10 OFFSET 20');
        Assert::same($params, ['p0' => 'active']);
    }

    public function readWithoutLimitOmitsLimitClause(): void
    {
        $sql = null;
        $reader = $this->reader([], $sql);

        $reader->read();

        Assert::true(is_string($sql));
        Assert::string($sql)->contains('SELECT id, status FROM events ORDER BY id DESC');
        Assert::string($sql)->notContains('LIMIT');
    }

    public function readOneReturnsFirstRow(): void
    {
        $sql = null;
        $reader = $this->reader([['id' => 7, 'status' => 'x']], $sql);

        Assert::same($reader->readOne(), ['id' => 7, 'status' => 'x']);
        Assert::true(is_string($sql));
        Assert::string($sql)->contains('LIMIT 1');
    }

    public function readOneReturnsNullWhenEmpty(): void
    {
        Assert::null($this->reader([])->readOne());
    }

    public function countReturnsInteger(): void
    {
        $sql = null;
        $reader = $this->reader([['cnt' => 42]], $sql);

        Assert::same($reader->count(), 42);
        Assert::true(is_string($sql));
        Assert::string($sql)->contains('SELECT count() AS cnt FROM events');
    }

    public function withMethodsAreImmutable(): void
    {
        $reader = $this->reader([]);
        $modified = $reader->withLimit(5)->withOffset(10);

        Assert::null($reader->getLimit());
        Assert::same($reader->getOffset(), 0);
        Assert::same($modified->getLimit(), 5);
        Assert::same($modified->getOffset(), 10);
    }

    public function withFilterReturnsNewReader(): void
    {
        $reader = $this->reader([]);

        Assert::false($reader === $reader->withFilter(new Equals('status', 'active')));
    }

    public function withSortReturnsNewReader(): void
    {
        $reader = $this->reader([]);

        Assert::false($reader === $reader->withSort(Sort::only(['id'])->withOrder(['id' => 'asc'])));
    }

    public function getIteratorYieldsRows(): void
    {
        $reader = $this->reader([['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']]);

        Assert::same(
            iterator_to_array($reader->getIterator()),
            [['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']],
        );
    }

    public function negativeLimitThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->reader([])->withLimit(-1);
    }

    public function negativeOffsetThrows(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->reader([])->withOffset(-1);
    }

    public function limitZeroIsAllowed(): void
    {
        Assert::same($this->reader([])->withLimit(0)->getLimit(), 0);
    }

    public function offsetZeroIsAllowed(): void
    {
        Assert::same($this->reader([])->withOffset(0)->getOffset(), 0);
    }

    public function withOffsetReturnsNewReaderAndKeepsOriginal(): void
    {
        $reader = $this->reader([]);
        $modified = $reader->withOffset(7);

        Assert::false($reader === $modified);
        Assert::same($reader->getOffset(), 0);
        Assert::same($modified->getOffset(), 7);
    }

    public function countAppliesFilterViaSelectWithParams(): void
    {
        $sql = null;
        $params = null;
        $reader = $this->reader([['cnt' => 5]], $sql, $params)
            ->withFilter(new Equals('status', 'active'));

        Assert::same($reader->count(), 5);
        Assert::same($params, ['p0' => 'active']);
        Assert::true(is_string($sql));
        Assert::string($sql)->contains('WHERE status = {p0:String}');
    }

    public function countReturnsZeroWhenNoRows(): void
    {
        Assert::same($this->reader([])->count(), 0);
    }

    public function countClampsNegativeCountToZero(): void
    {
        Assert::same($this->reader([['cnt' => -3]])->count(), 0);
    }

    public function countCastsStringCountToInt(): void
    {
        Assert::same($this->reader([['cnt' => '42']])->count(), 42);
    }

    public function readAppliesMapperToEachRow(): void
    {
        $client = (new FakeClickHouseClient())->withSelectCallback(
            fn() => $this->makeOutput([['id' => 1, 'status' => 'a'], ['id' => 2, 'status' => 'b']]),
        );

        $reader = new ClickHouseDataReader(
            client: $client,
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => ['ref' => 'r' . (int) $row['id']],
            columns: ['id', 'status'],
        );

        Assert::same($reader->read(), [['ref' => 'r1'], ['ref' => 'r2']]);
    }
}
