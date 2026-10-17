<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use LogicException;
use Rasuvaeff\ClickHouseToolkit\Aggregate;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\Equals;

#[Test]
#[Covers(Aggregate::class)]
final class AggregateTest
{
    #[DataProvider('plainProvider')]
    public function plainAggregatesRender(Aggregate $aggregate, string $expected): void
    {
        Assert::same($aggregate->toSql(), $expected);
    }

    /**
     * @return iterable<string, array{Aggregate, string}>
     */
    public static function plainProvider(): iterable
    {
        yield 'count without an alias' => [Aggregate::count(), 'count()'];
        yield 'count with an alias' => [Aggregate::count(alias: 'orders'), 'count() AS orders'];
        yield 'sum' => [Aggregate::sum(column: 'amount_cents', alias: 'amount_cents'), 'sum(amount_cents) AS amount_cents'];
        yield 'sum without an alias' => [Aggregate::sum(column: 'amount_cents'), 'sum(amount_cents)'];
        yield 'min' => [Aggregate::min(column: 'amount_cents', alias: 'min_amount'), 'min(amount_cents) AS min_amount'];
        yield 'max' => [Aggregate::max(column: 'amount_cents', alias: 'max_amount'), 'max(amount_cents) AS max_amount'];
        yield 'avg' => [Aggregate::avg(column: 'amount_cents', alias: 'avg_amount'), 'avg(amount_cents) AS avg_amount'];
        yield 'uniqExact' => [Aggregate::uniqExact(column: 'subject_id', alias: 'visitors'), 'uniqExact(subject_id) AS visitors'];
    }

    /**
     * A conditional aggregate keeps its condition until the statement builder
     * renders it through the filter visitor — there is no plain-text form.
     */
    public function conditionalAggregatesCarryTheCondition(): void
    {
        $aggregate = Aggregate::countIf(condition: new Equals('status', 'paid'), alias: 'paid_orders');

        Assert::same($aggregate->function, 'countIf');
        Assert::same($aggregate->alias, 'paid_orders');
        Assert::instanceOf($aggregate->condition, Equals::class);
        Expect::exception(LogicException::class);

        $aggregate->toSql();
    }

    #[DataProvider('refusedProvider')]
    public function malformedIdentifiersAreRefused(string $kind, string $column, ?string $alias): void
    {
        Expect::exception(InvalidArgumentException::class);

        match ($kind) {
            'sum' => Aggregate::sum(column: $column, alias: $alias),
            'uniqExact' => Aggregate::uniqExact(column: $column, alias: $alias),
            'count' => Aggregate::count(alias: $alias),
            'max' => Aggregate::max(column: $column, alias: $alias),
        };
    }

    /**
     * The provider carries plain strings: constructing the value object is
     * itself the throwing act under test, so it happens inside the test
     * body, under the expected exception.
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'column with an expression' => ['sum', 'amount; DROP', null];
        yield 'quoted column' => ['uniqExact', '`subject_id`', null];
        yield 'alias with a space' => ['count', 'x', 'bad alias'];
        yield 'alias with an expression' => ['max', 'a', 'a AS b'];
    }
}
