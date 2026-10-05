<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseKeysetReader;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\Tests\Support\Clients;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;
use SimPod\ClickHouseClient\Client\ClickHouseClient;
use SimPod\ClickHouseClient\Output\JsonEachRow as JsonEachRowOutput;
use SimPod\ClickHouseClient\Output\Output;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\FilterInterface;

use function Rasuvaeff\Understudy\when;

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

        $ids = array_map(static fn(array $row): int => (int) $row['id'], iterator_to_array($reader->stream(), preserve_keys: false));

        Assert::same($ids, [1, 2, 3, 4, 5]);
    }

    public function firstPageHasNoBoundaryAndLaterPagesSeekPastLastKey(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], [['id' => 3], ['id' => 4]], [['id' => 5]]],
            pageSize: 2,
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::same(count($this->calls($client)), 3);
        Assert::same($this->calls($client)[0]['params'], []);
        Assert::string($this->calls($client)[0]['sql'])->contains('ORDER BY id ASC');
        Assert::string($this->calls($client)[0]['sql'])->contains('LIMIT 2 OFFSET 0');
        Assert::string($this->calls($client)[1]['sql'])->contains('id > {ck0:UInt64}');
        Assert::same($this->calls($client)[1]['params'], ['ck0' => 2]);
        Assert::same($this->calls($client)[2]['params'], ['ck0' => 4]);
    }

    public function stopsWhenPageSmallerThanPageSize(): void
    {
        $client = null;
        $reader = $this->reader(pages: [[['id' => 1]]], pageSize: 2, client: $client);

        iterator_to_array($reader->stream());

        Assert::same(count($this->calls($client)), 1);
    }

    public function stopsAfterExactMultipleWithEmptyTailPage(): void
    {
        $client = null;
        $reader = $this->reader(pages: [[['id' => 1], ['id' => 2]], []], pageSize: 2, client: $client);

        $ids = array_map(static fn(array $row): int => (int) $row['id'], iterator_to_array($reader->stream(), preserve_keys: false));

        Assert::same($ids, [1, 2]);
        Assert::same(count($this->calls($client)), 2);
    }

    public function compositeKeyUsesTupleComparison(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [
                [['created_at' => '2024-01-01 00:00:00', 'id' => 1]],
                [],
            ],
            pageSize: 1,
            keyColumns: ['created_at' => 'DateTime', 'id' => 'UInt64'],
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::string($this->calls($client)[0]['sql'])->contains('ORDER BY created_at ASC, id ASC');
        Assert::string($this->calls($client)[1]['sql'])->contains('(created_at, id) > ({ck0:DateTime}, {ck1:UInt64})');
        Assert::same($this->calls($client)[1]['params'], ['ck0' => '2024-01-01 00:00:00', 'ck1' => 1]);
    }

    public function keyColumnsAreAddedToProjection(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [[['id' => 1, 'name' => 'a']]],
            pageSize: 2,
            columns: ['name'],
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::string($this->calls($client)[0]['sql'])->contains('SELECT name, id FROM');
    }

    public function keyColumnAlreadyInProjectionIsNotDuplicated(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [[['id' => 1, 'name' => 'a']]],
            pageSize: 2,
            columns: ['id', 'name'],
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::string($this->calls($client)[0]['sql'])->contains('SELECT id, name FROM');
    }

    public function appliesBaseFilterOnEveryPage(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], [['id' => 3]]],
            pageSize: 2,
            filter: new Equals('status', 'active'),
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::string($this->calls($client)[0]['sql'])->contains('status =');
        Assert::same($this->calls($client)[0]['params'], ['p0' => 'active']);
        Assert::string($this->calls($client)[1]['sql'])->contains('status =');
        Assert::string($this->calls($client)[1]['sql'])->contains('id > {ck0:UInt64}');
        Assert::same($this->calls($client)[1]['params'], ['p0' => 'active', 'ck0' => 2]);
    }

    /**
     * The boundary's `ck0` used to be a reserved name the base filter had to
     * keep clear of; since #34 a clash is renamed like any other.
     */
    public function baseFilterMayUseTheBoundaryParameterName(): void
    {
        $client = null;
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], [['id' => 3]]],
            pageSize: 2,
            filter: new ClickHouseRawFilter('id >= {ck0:UInt64}', ['ck0' => 1]),
            client: $client,
        );

        iterator_to_array($reader->stream());

        Assert::string($this->calls($client)[1]['sql'])->contains('id >= {ck0:UInt64}');
        Assert::string($this->calls($client)[1]['sql'])->contains('id > {ck0_0:UInt64}');
        Assert::same($this->calls($client)[1]['params'], ['ck0' => 1, 'ck0_0' => 2]);
    }

    public function appliesMapperToEachRow(): void
    {
        $reader = $this->reader(
            pages: [[['id' => 1], ['id' => 2]], []],
            pageSize: 2,
            mapper: static fn(array $row): string => 'row-' . $row['id'],
        );

        Assert::same(iterator_to_array($reader->stream(), preserve_keys: false), ['row-1', 'row-2']);
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
            client: Clients::plain(),
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
            client: Clients::plain(),
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
            client: Clients::plain(),
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
            client: Clients::plain(),
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
     * @return ClickHouseKeysetReader<mixed>
     */
    private function reader(
        array $pages,
        int $pageSize = 1000,
        array $keyColumns = ['id' => 'UInt64'],
        array $columns = [],
        ?FilterInterface $filter = null,
        ?\Closure $mapper = null,
        ?ClickHouseClient & $client = null,
    ): ClickHouseKeysetReader {
        $index = 0;
        $make = static function (array $rows): Output {
            $lines = array_map(static fn(array $row): string => (string) json_encode($row), $rows);

            return new JsonEachRowOutput(implode("\n", $lines));
        };

        $client = Understudy::for(ClickHouseClient::class);
        $nextPage = static function () use (&$index, $pages, $make): Output {
            return $make($pages[$index++] ?? []);
        };
        when(fn() => $client->select(Arg::any(), Arg::any()))->answers($nextPage);
        when(fn() => $client->selectWithParams(Arg::any(), Arg::any(), Arg::any()))->answers($nextPage);

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

    /**
     * @return list<array{sql: string, params: array<string, mixed>}> every read the reader issued, in order
     */
    private function calls(ClickHouseClient $client): array
    {
        $invocations = [...Clients::selects($client), ...Clients::parameterisedSelects($client)];
        usort($invocations, static fn(Invocation $a, Invocation $b): int => $a->sequence <=> $b->sequence);

        return array_map(
            static fn(Invocation $call): array => [
                'sql' => $call->arg('query'),
                'params' => $call->method === 'select' ? [] : $call->arg('params'),
            ],
            $invocations,
        );
    }
}
