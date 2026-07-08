<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseKeysetReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\Equals;

#[Test]
#[Covers(ClickHouseKeysetReader::class)]
final class ClickHouseKeysetReaderTest
{
    public function streamsAllRowsAcrossPagesInOrder(): void
    {
        $reader = $this->reader(
            pages: [
                [['id' => 1], ['id' => 2]],
                [['id' => 3], ['id' => 4]],
                [['id' => 5]],
            ],
            pageSize: 2,
        );

        $ids = array_map(static fn(array $row): int => (int) $row['id'], iterator_to_array($reader->stream(), false));

        Assert::same($ids, [1, 2, 3, 4, 5]);
    }

    public function firstPageHasNoBoundaryAndLaterPagesSeekPastLastKey(): void
    {
        $calls = [];
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], [['id' => 3], ['id' => 4]], [['id' => 5]]],
            pageSize: 2,
            calls: $calls,
        );

        iterator_to_array($reader->stream());

        Assert::same(count($calls), 3);
        Assert::same($calls[0]['params'], []);
        Assert::string($calls[0]['sql'])->contains('ORDER BY id ASC');
        Assert::string($calls[0]['sql'])->contains('LIMIT 2 OFFSET 0');
        Assert::string($calls[1]['sql'])->contains('id > {ck0:UInt64}');
        Assert::same($calls[1]['params'], ['ck0' => 2]);
        Assert::same($calls[2]['params'], ['ck0' => 4]);
    }

    public function stopsWhenPageSmallerThanPageSize(): void
    {
        $calls = [];
        $reader = $this->reader(pages: [[['id' => 1]]], pageSize: 2, calls: $calls);

        iterator_to_array($reader->stream());

        Assert::same(count($calls), 1);
    }

    public function stopsAfterExactMultipleWithEmptyTailPage(): void
    {
        $calls = [];
        $reader = $this->reader(pages: [[['id' => 1], ['id' => 2]], []], pageSize: 2, calls: $calls);

        $ids = array_map(static fn(array $row): int => (int) $row['id'], iterator_to_array($reader->stream(), false));

        Assert::same($ids, [1, 2]);
        Assert::same(count($calls), 2);
    }

    public function compositeKeyUsesTupleComparison(): void
    {
        $calls = [];
        $reader = $this->reader(
            pages: [
                [['created_at' => '2024-01-01 00:00:00', 'id' => 1]],
                [],
            ],
            pageSize: 1,
            keyColumns: ['created_at' => 'DateTime', 'id' => 'UInt64'],
            calls: $calls,
        );

        iterator_to_array($reader->stream());

        Assert::string($calls[0]['sql'])->contains('ORDER BY created_at ASC, id ASC');
        Assert::string($calls[1]['sql'])->contains('(created_at, id) > ({ck0:DateTime}, {ck1:UInt64})');
        Assert::same($calls[1]['params'], ['ck0' => '2024-01-01 00:00:00', 'ck1' => 1]);
    }

    public function keyColumnsAreAddedToProjection(): void
    {
        $calls = [];
        $reader = $this->reader(
            pages: [[['id' => 1, 'name' => 'a']]],
            pageSize: 2,
            columns: ['name'],
            calls: $calls,
        );

        iterator_to_array($reader->stream());

        Assert::string($calls[0]['sql'])->contains('SELECT name, id FROM');
    }

    public function keyColumnAlreadyInProjectionIsNotDuplicated(): void
    {
        $calls = [];
        $reader = $this->reader(
            pages: [[['id' => 1, 'name' => 'a']]],
            pageSize: 2,
            columns: ['id', 'name'],
            calls: $calls,
        );

        iterator_to_array($reader->stream());

        Assert::string($calls[0]['sql'])->contains('SELECT id, name FROM');
    }

    public function appliesBaseFilterOnEveryPage(): void
    {
        $calls = [];
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], [['id' => 3]]],
            pageSize: 2,
            filter: new Equals('status', 'active'),
            calls: $calls,
        );

        iterator_to_array($reader->stream());

        Assert::string($calls[0]['sql'])->contains('status =');
        Assert::same($calls[0]['params'], ['p0' => 'active']);
        Assert::string($calls[1]['sql'])->contains('status =');
        Assert::string($calls[1]['sql'])->contains('id > {ck0:UInt64}');
        Assert::same($calls[1]['params'], ['p0' => 'active', 'ck0' => 2]);
    }

    public function appliesMapperToEachRow(): void
    {
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], []],
            pageSize: 2,
            mapper: static fn(array $row): string => 'row-' . $row['id'],
        );

        Assert::same(iterator_to_array($reader->stream(), false), ['row-1', 'row-2']);
    }

    public function rejectsEmptyKeyColumns(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->reader(pages: [], keyColumns: []);
    }

    public function rejectsNonPositivePageSize(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->reader(pages: [], pageSize: 0);
    }

    public function rejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseKeysetReader(
            client: new FakeClickHouseClient(),
            table: 'events; DROP TABLE x',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => $row,
            keyColumns: ['id' => 'UInt64'],
        );
    }

    public function rejectsMalformedKeyColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseKeysetReader(
            client: new FakeClickHouseClient(),
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => $row,
            keyColumns: ['id) --' => 'UInt64'],
        );
    }

    public function rejectsMalformedProjectionColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseKeysetReader(
            client: new FakeClickHouseClient(),
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => $row,
            keyColumns: ['id' => 'UInt64'],
            columns: ['name) --'],
        );
    }

    public function rejectsMalformedKeyType(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseKeysetReader(
            client: new FakeClickHouseClient(),
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: static fn(array $row): array => $row,
            keyColumns: ['id' => 'UInt64) --'],
        );
    }

    private function queryBuilder(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(
            allowedFields: ['id', 'status', 'name', 'created_at'],
            fieldTypes: ['id' => 'UInt64', 'created_at' => 'DateTime'],
        );
    }

    /**
     * @param list<list<array<string, mixed>>> $pages
     * @param array<string, string> $keyColumns
     * @param list<string> $columns
     * @param \Closure|null $mapper
     * @param list<array{sql: string, params: array<string, mixed>}> $calls
     *
     * @return ClickHouseKeysetReader<mixed>
     */
    private function reader(
        array $pages,
        int $pageSize = 1000,
        array $keyColumns = ['id' => 'UInt64'],
        array $columns = [],
        ?Equals $filter = null,
        ?\Closure $mapper = null,
        array &$calls = [],
    ): ClickHouseKeysetReader {
        $index = 0;
        $make = static function (array $rows): Output {
            $lines = array_map(static fn(array $row): string => (string) json_encode($row), $rows);

            return new JsonEachRowOutput(implode("\n", $lines));
        };

        $client = (new FakeClickHouseClient())
            ->withSelectCallback(
                static function (string $sql) use (&$index, $pages, &$calls, $make): Output {
                    $calls[] = ['sql' => $sql, 'params' => []];

                    return $make($pages[$index++] ?? []);
                },
            )
            ->withSelectWithParamsCallback(
                static function (string $sql, array $params) use (&$index, $pages, &$calls, $make): Output {
                    $calls[] = ['sql' => $sql, 'params' => $params];

                    return $make($pages[$index++] ?? []);
                },
            );

        return new ClickHouseKeysetReader(
            client: $client,
            table: 'events',
            queryBuilder: $this->queryBuilder(),
            mapper: $mapper ?? static fn(array $row): array => $row,
            keyColumns: $keyColumns,
            columns: $columns,
            pageSize: $pageSize,
            filter: $filter,
        );
    }
}
