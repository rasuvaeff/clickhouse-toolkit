<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\Aggregate;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ParameterizedQuery;
use Rasuvaeff\ClickHouseToolkit\Scalar;
use Rasuvaeff\ClickHouseToolkit\SelectQuery;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Sort;

/**
 * The statement builder around WHERE (#45). Every expectation here is a whole
 * statement, because the promise under test is exactly that no consumer ever
 * concatenates a skeleton around `WhereClause::$sql` again.
 */
#[Test]
#[Covers(SelectQuery::class)]
#[Covers(ParameterizedQuery::class)]
final class SelectQueryTest
{
    public function buildsTheTotalsByStatusRead(): void
    {
        $builder = (new ClickHouseQueryBuilder(allowedFields: []))
            ->withMandatoryFilter(new Equals('source', 'shop'));

        $query = $builder->select(table: 'order_results')
            ->final()
            ->columns('status')
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'))
            ->groupBy('status')
            ->orderBy(self::ascending('status'))
            ->build();

        Assert::same(
            $query->sql,
            'SELECT status, count() AS orders, sum(amount_cents) AS amount_cents FROM order_results FINAL '
            . 'WHERE source = {p0:String} GROUP BY status ORDER BY status ASC',
        );
        Assert::same($query->params, ['p0' => 'shop']);
    }

    public function buildsTheLatestReadWithAScalarWrapAndABoundLimit(): void
    {
        $builder = (new ClickHouseQueryBuilder(allowedFields: []))
            ->withMandatoryFilter(new Equals('source', 'shop'));

        $query = $builder->select(table: 'order_results')
            ->final()
            ->columns('order_id', 'status', 'amount_cents')
            ->scalar(Scalar::toString(column: 'occurred_at', alias: 'occurred_at'))
            ->orderBy(self::descending('occurred_at'))
            ->limit(10)
            ->build();

        Assert::same(
            $query->sql,
            'SELECT order_id, status, amount_cents, toString(occurred_at) AS occurred_at FROM order_results FINAL '
            . 'WHERE source = {p0:String} ORDER BY occurred_at DESC LIMIT {limit:UInt32}',
        );
        Assert::same($query->params, ['p0' => 'shop', 'limit' => 10]);
    }

    public function buildsTheSummaryReadWithoutGroupBy(): void
    {
        $builder = (new ClickHouseQueryBuilder(allowedFields: []))
            ->withMandatoryFilter(new Equals('source', 'shop'));

        $query = $builder->select(table: 'order_results')
            ->final()
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'))
            ->build();

        Assert::same(
            $query->sql,
            'SELECT count() AS orders, sum(amount_cents) AS amount_cents FROM order_results FINAL WHERE source = {p0:String}',
        );
    }

    public function noProjectionsSelectEverything(): void
    {
        $query = $this->builder()->select(table: 'events')->build();

        Assert::same($query->sql, 'SELECT * FROM events');
        Assert::same($query->params, []);
    }

    public function userAndMandatoryFiltersAreAndComposed(): void
    {
        $builder = (new ClickHouseQueryBuilder(allowedFields: ['status']))
            ->withMandatoryFilter(new Equals('source', 'shop'));

        $query = $builder->select(table: 'order_results')
            ->where(new Equals('status', 'paid'))
            ->build();

        Assert::same(
            $query->sql,
            'SELECT * FROM order_results WHERE (source = {p0:String}) AND (status = {p1:String})',
        );
        Assert::same($query->params, ['p0' => 'shop', 'p1' => 'paid']);
    }

    public function aUserFilterOutsideTheAllowListIsDropped(): void
    {
        $query = $this->builder()->select(table: 'events')
            ->where(new Equals('secret', 'x'))
            ->build();

        Assert::same($query->sql, 'SELECT * FROM events');
    }

    public function offsetRidesAlongWithTheLimit(): void
    {
        $query = $this->builder()->select(table: 'events')->limit(10)->offset(30)->build();

        Assert::same($query->sql, 'SELECT * FROM events LIMIT {limit:UInt32} OFFSET {offset:UInt32}');
        Assert::same($query->params, ['limit' => 10, 'offset' => 30]);
    }

    public function aNullLimitOmitsLimitAndOffset(): void
    {
        $query = $this->builder()->select(table: 'events')->limit(null)->offset(0)->build();

        Assert::same($query->sql, 'SELECT * FROM events');
        Assert::same($query->params, []);
    }

    #[DataProvider('refusedShapeProvider')]
    public function malformedShapesAreRefused(string $case): void
    {
        Expect::exception(InvalidArgumentException::class);

        $builder = new ClickHouseQueryBuilder(allowedFields: []);

        match ($case) {
            'table with an expression' => $builder->select(table: 'events; DROP'),
            'column with a space' => $builder->select(table: 'e')->columns('bad column'),
            'group by an expression' => $builder->select(table: 'e')->groupBy('a, b'),
            'negative limit' => $builder->select(table: 'e')->limit(-1),
            'negative offset' => $builder->select(table: 'e')->offset(-5),
            'offset without a limit' => $builder->select(table: 'e')->offset(5)->build(),
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedShapeProvider(): iterable
    {
        yield 'table with an expression' => ['table with an expression'];
        yield 'column with a space' => ['column with a space'];
        yield 'group by an expression' => ['group by an expression'];
        yield 'negative limit' => ['negative limit'];
        yield 'negative offset' => ['negative offset'];
        yield 'offset without a limit' => ['offset without a limit'];
    }

    public function aConditionalAggregateBindsItsValues(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['status'], fieldTypes: ['status' => 'String']);

        $query = $builder->select(table: 'order_results')
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->aggregate(Aggregate::countIf(condition: new Equals('status', 'paid'), alias: 'paid_orders'))
            ->aggregate(Aggregate::sumIf(column: 'amount_cents', condition: new Equals('status', 'paid'), alias: 'paid_amount'))
            ->build();

        Assert::same(
            $query->sql,
            'SELECT count() AS orders, countIf(status = {p0:String}) AS paid_orders, '
            . 'sumIf(amount_cents, status = {p1:String}) AS paid_amount FROM order_results',
        );
        Assert::same($query->params, ['p0' => 'paid', 'p1' => 'paid']);
    }

    public function aConditionalAggregateWhoseConditionWasDroppedIsRefused(): void
    {
        // 'secret' is not allow-listed: the condition would render empty and
        // countIf() silently degenerate into count() — a different query.
        Expect::exception(InvalidArgumentException::class);

        $this->builder()->select(table: 'events')
            ->aggregate(Aggregate::countIf(condition: new Equals('secret', 'x')))
            ->build();
    }

    public function aLimitNamedParameterCollisionIsRenamed(): void
    {
        $builder = (new ClickHouseQueryBuilder(allowedFields: []))
            ->withMandatoryFilter(new ClickHouseRawFilter(sql: 'n = {limit:UInt64}', params: ['limit' => 7]));

        $query = $builder->select(table: 'events')->limit(3)->build();

        Assert::same($query->sql, 'SELECT * FROM events WHERE n = {limit:UInt64} LIMIT {limit_0:UInt32}');
        Assert::same($query->params, ['limit' => 7, 'limit_0' => 3]);
    }

    public function orderByAcceptsProjectedAliasesAndGroupColumns(): void
    {
        $query = $this->builder()->select(table: 'order_results')
            ->columns('status')
            ->aggregate(Aggregate::count(alias: 'orders'))
            ->groupBy('status')
            ->orderBy(self::descending('orders'))
            ->build();

        Assert::same(
            $query->sql,
            'SELECT status, count() AS orders FROM order_results GROUP BY status ORDER BY orders DESC',
        );
    }

    public function orderByDropsFieldsTheQueryDoesNotKnow(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['status']);

        $query = $builder->select(table: 'events')
            ->columns('id')
            ->orderBy(self::ascending('id', 'secret'))
            ->build();

        Assert::same($query->sql, 'SELECT id FROM events ORDER BY id ASC');
    }

    public function anEmptySortFallsBackToTheDefaultSort(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: [], defaultSort: 'id DESC');

        $query = $builder->select(table: 'events')
            ->orderBy(self::ascending('secret'))
            ->build();

        Assert::same($query->sql, 'SELECT * FROM events ORDER BY id DESC');
    }

    private function builder(): ClickHouseQueryBuilder
    {
        return new ClickHouseQueryBuilder(allowedFields: []);
    }

    /**
     * @return Sort sorted by the given fields, ascending, in the order given.
     */
    private static function ascending(string ...$fields): Sort
    {
        return Sort::only($fields)->withOrder(array_combine($fields, array_fill(0, count($fields), 'asc')));
    }

    private static function descending(string ...$fields): Sort
    {
        return Sort::only($fields)->withOrder(array_combine($fields, array_fill(0, count($fields), 'desc')));
    }
}
