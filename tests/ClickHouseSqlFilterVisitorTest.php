<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseSqlFilterVisitor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\EqualsNull;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\LessThanOrEqual;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\LikeMode;
use Yiisoft\Data\Reader\Filter\None;
use Yiisoft\Data\Reader\Filter\Not;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\FilterInterface;

#[Test]
#[Covers(ClickHouseSqlFilterVisitor::class)]
final class ClickHouseSqlFilterVisitorTest
{
    private ClickHouseSqlFilterVisitor $visitor;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->visitor = new ClickHouseSqlFilterVisitor(
            allowedFields: ['id', 'status', 'created_at'],
            fieldTypes: ['id' => 'UInt64', 'created_at' => 'DateTime'],
        );
    }

    public function dispatchAllReturnsEmpty(): void
    {
        $index = 0;
        Assert::same($this->visitor->visitAll(new All(), $index, false), ['', []]);
    }

    public function dispatchNoneReturnsZero(): void
    {
        $index = 0;
        Assert::same($this->visitor->visitNone(new None(), $index, false), ['0', []]);
    }

    public function dispatchEquals(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('status', 'active'), $index, false);
        Assert::same($result[0], 'status = {p0:String}');
        Assert::same($result[1], ['p0' => 'active']);
        Assert::same($index, 1);
    }

    public function dispatchEqualsDisallowedFieldReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('secret', 'x'), $index, false);
        Assert::same($result[0], '');
        Assert::same($index, 0);
    }

    public function dispatchGreaterThanIncrementsIndex(): void
    {
        $index = 5;
        $result = $this->visitor->visitGreaterThan(new GreaterThan('id', 10), $index, false);
        Assert::same($result[0], 'id > {p5:UInt64}');
        Assert::same($index, 6);
    }

    public function dispatchInWithMultipleValues(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', [1, 2, 3]), $index, false);
        Assert::same($result[0], 'id IN ({p0:UInt64}, {p1:UInt64}, {p2:UInt64})');
        Assert::same($result[1], ['p0' => 1, 'p1' => 2, 'p2' => 3]);
        Assert::same($index, 3);
    }

    public function dispatchInWithEmptyValuesMatchesNothing(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', []), $index, false);
        Assert::same($result[0], '0');
        Assert::same($index, 0);
    }

    public function dispatchBetween(): void
    {
        $index = 0;
        $result = $this->visitor->visitBetween(new Between('id', 10, 20), $index, false);
        Assert::same($result[0], 'id BETWEEN {p0:UInt64} AND {p1:UInt64}');
        Assert::same($result[1], ['p0' => 10, 'p1' => 20]);
        Assert::same($index, 2);
    }

    public function dispatchEqualsNull(): void
    {
        $index = 0;
        $result = $this->visitor->visitEqualsNull(new EqualsNull('status'), $index, false);
        Assert::same($result[0], 'status IS NULL');
        Assert::same($result[1], []);
    }

    public function dispatchLikeContains(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act'), $index, false);
        Assert::same($result[0], 'status ILIKE {p0:String}');
        Assert::same($result[1], ['p0' => '%act%']);
    }

    public function dispatchLikeCastsNonStringFieldToString(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('id', '12'), $index, false);
        Assert::same($result[0], 'toString(id) ILIKE {p0:String}');
        Assert::same($result[1], ['p0' => '%12%']);
    }

    public function dispatchLikeWithEmptyValueIsDropped(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', ''), $index, false);
        Assert::same($result, ['', []]);
        Assert::same($index, 0);
    }

    public function dispatchLikeStartsWithCaseSensitive(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act', caseSensitive: true, mode: LikeMode::StartsWith), $index, false);
        Assert::same($result[0], 'status LIKE {p0:String}');
        Assert::same($result[1], ['p0' => 'act%']);
    }

    public function dispatchNotWrapsInner(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('status', 'active')), $index, false);
        Assert::same($result[0], 'NOT (status = {p0:String})');
        Assert::same($result[1], ['p0' => 'active']);
    }

    public function dispatchNotWithDroppedInnerIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('secret', 'x')), $index, false);
        Assert::same($result[0], '');
    }

    public function dispatchAndX(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('status', 'a'), new GreaterThan('id', 5)), $index, false);
        Assert::same($result[0], '(status = {p0:String} AND id > {p1:UInt64})');
        Assert::same($result[1], ['p0' => 'a', 'p1' => 5]);
        Assert::same($index, 2);
    }

    public function dispatchOrX(): void
    {
        $index = 0;
        $result = $this->visitor->visitOrX(new OrX(new Equals('status', 'a'), new Equals('status', 'b')), $index, false);
        Assert::same($result[0], '(status = {p0:String} OR status = {p1:String})');
    }

    public function dispatchSkipsDisallowedSubFilters(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x'), new Equals('status', 'a')), $index, false);
        Assert::same($result[0], '(status = {p0:String})');
    }

    public function trustedBypassesAllowList(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('tenant_id', 5), $index, true);
        Assert::same($result[0], 'tenant_id = {p0:String}');
    }

    public function trustedRejectsMalformedIdentifier(): void
    {
        $index = 0;
        Expect::exception(\InvalidArgumentException::class);
        $this->visitor->visitEquals(new Equals('bad; DROP', 1), $index, true);
    }

    public function dispatchUnknownFilterReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->dispatch(new class implements \Yiisoft\Data\Reader\FilterInterface {}, $index, false);
        Assert::same($result, ['', []]);
    }

    public function dateTimeNormalizedWithoutTimezone(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['dt'], ['dt' => 'DateTime']);
        $index = 0;
        $dt = new \DateTimeImmutable('2024-06-15 12:00:00', new \DateTimeZone('Europe/Moscow'));
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, false);
        Assert::same($result[1], ['p0' => '2024-06-15 12:00:00']);
    }

    public function dateTimeNormalizedWithServerTimezone(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(
            ['dt'],
            ['dt' => 'DateTime'],
            new \DateTimeZone('UTC'),
        );
        $index = 0;
        $dt = new \DateTimeImmutable('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, false);
        Assert::same($result[1], ['p0' => '2024-06-15 12:00:00']);
    }

    public function dateTimeMutableConvertedToServerTimezone(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(
            ['dt'],
            ['dt' => 'DateTime'],
            new \DateTimeZone('UTC'),
        );
        $index = 0;
        $dt = new \DateTime('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, false);
        Assert::same($result[1], ['p0' => '2024-06-15 12:00:00']);
    }

    public function boolIsNormalizedToInt(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('id', true), $index, false);
        Assert::same($result[1], ['p0' => 1]);
    }

    public function compositeWithAllSubsDroppedIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x')), $index, false);
        Assert::same($result, ['', []]);
    }

    public function likeEscapesWildcards(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', "50%_off'x"), $index, false);
        Assert::same($result[1], ['p0' => "%50\\%\\_off'x%"]);
    }

    public function lessThanOrEqualUsesFieldType(): void
    {
        $index = 0;
        $result = $this->visitor->visitLessThanOrEqual(new LessThanOrEqual('created_at', '2024-01-01'), $index, false);
        Assert::same($result[0], 'created_at <= {p0:DateTime}');
    }

    public function likeStringifiesStringableValueAndAdvancesIndex(): void
    {
        $index = 0;
        $value = new class {
            public function __toString(): string
            {
                return 'abc';
            }
        };
        $result = $this->visitor->visitLike(new Like('status', $value), $index, false);

        Assert::same($result[1], ['p0' => '%abc%']);
        Assert::same($index, 1);
    }

    public function equalsStringifiesStringableValue(): void
    {
        $index = 0;
        $value = new class {
            public function __toString(): string
            {
                return 'abc';
            }
        };
        $result = $this->visitor->visitEquals(new Equals('status', $value), $index, false);

        Assert::same($result[1], ['p0' => 'abc']);
    }

    public function mutableDateTimeIsNotMutatedByNormalization(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['dt'], ['dt' => 'DateTime'], new \DateTimeZone('UTC'));
        $index = 0;
        $dt = new \DateTime('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));

        $visitor->visitEquals(new Equals('dt', $dt), $index, false);

        Assert::same($dt->getTimezone()->getName(), 'Europe/Moscow');
    }

    public function likeTreatsNullableStringTypeAsStringWithoutCast(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable(String)']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, false);

        Assert::same($result[0], 'x ILIKE {p0:String}');
    }

    public function likeNormalizesSpacesInTypeToken(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable( String )']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, false);

        Assert::same($result[0], 'x ILIKE {p0:String}');
    }

    public function likeTypeMustStartWithNullableToUnwrap(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'xNullable(String)']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, false);

        Assert::same($result[0], 'toString(x) ILIKE {p0:String}');
    }

    public function likeTypeMustEndWithClosingParenToUnwrap(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable(String)x']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, false);

        Assert::same($result[0], 'toString(x) ILIKE {p0:String}');
    }

    public function dispatchRoutesEqualsToVisitEquals(): void
    {
        $index = 0;
        $result = $this->visitor->dispatch(new Equals('status', 'active'), $index, false);

        Assert::same($result[0], 'status = {p0:String}');
    }

    #[DataProvider('disallowedFieldFilterProvider')]
    public function disallowedFieldReturnsEmptyPair(FilterInterface $filter): void
    {
        $index = 0;

        Assert::same($this->visitor->dispatch($filter, $index, false), ['', []]);
    }

    /**
     * @return iterable<string, array{FilterInterface}>
     */
    public static function disallowedFieldFilterProvider(): iterable
    {
        yield 'equalsNull' => [new EqualsNull('secret')];
        yield 'like' => [new Like('secret', 'x')];
        yield 'in' => [new In('secret', [1])];
        yield 'between' => [new Between('secret', 1, 2)];
    }
}
