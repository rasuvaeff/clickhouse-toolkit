<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseSqlFilterVisitor;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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
        Assert::same($this->visitor->visitAll(new All(), $index, trusted: false), ['', []]);
    }

    public function dispatchNoneReturnsZero(): void
    {
        $index = 0;
        Assert::same($this->visitor->visitNone(new None(), $index, trusted: false), ['0', []]);
    }

    public function dispatchEquals(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('status', 'active'), $index, trusted: false);
        Assert::same($result[0], 'status = {p0:String}');
        Assert::same($result[1], ['p0' => 'active']);
        Assert::same($index, 1);
    }

    public function dispatchEqualsDisallowedFieldReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('secret', 'x'), $index, trusted: false);
        Assert::same($result[0], '');
        Assert::same($index, 0);
    }

    public function dispatchGreaterThanIncrementsIndex(): void
    {
        $index = 5;
        $result = $this->visitor->visitGreaterThan(new GreaterThan('id', 10), $index, trusted: false);
        Assert::same($result[0], 'id > {p5:UInt64}');
        Assert::same($index, 6);
    }

    public function dispatchInWithMultipleValues(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', [1, 2, 3]), $index, trusted: false);
        Assert::same($result[0], 'id IN ({p0:UInt64}, {p1:UInt64}, {p2:UInt64})');
        Assert::same($result[1], ['p0' => 1, 'p1' => 2, 'p2' => 3]);
        Assert::same($index, 3);
    }

    public function dispatchInWithEmptyValuesMatchesNothing(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', []), $index, trusted: false);
        Assert::same($result[0], '0');
        Assert::same($index, 0);
    }

    public function dispatchBetween(): void
    {
        $index = 0;
        $result = $this->visitor->visitBetween(new Between('id', 10, 20), $index, trusted: false);
        Assert::same($result[0], 'id BETWEEN {p0:UInt64} AND {p1:UInt64}');
        Assert::same($result[1], ['p0' => 10, 'p1' => 20]);
        Assert::same($index, 2);
    }

    public function dispatchEqualsNull(): void
    {
        $index = 0;
        $result = $this->visitor->visitEqualsNull(new EqualsNull('status'), $index, trusted: false);
        Assert::same($result[0], 'status IS NULL');
        Assert::same($result[1], []);
    }

    public function dispatchLikeContains(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act'), $index, trusted: false);
        Assert::same($result[0], 'status ILIKE {p0:String}');
        Assert::same($result[1], ['p0' => '%act%']);
    }

    public function dispatchLikeCastsNonStringFieldToString(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('id', '12'), $index, trusted: false);
        Assert::same($result[0], 'toString(id) ILIKE {p0:String}');
        Assert::same($result[1], ['p0' => '%12%']);
    }

    public function dispatchLikeWithEmptyValueIsDropped(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', ''), $index, trusted: false);
        Assert::same($result, ['', []]);
        Assert::same($index, 0);
    }

    public function dispatchLikeStartsWithCaseSensitive(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act', caseSensitive: true, mode: LikeMode::StartsWith), $index, trusted: false);
        Assert::same($result[0], 'status LIKE {p0:String}');
        Assert::same($result[1], ['p0' => 'act%']);
    }

    public function dispatchNotWrapsInner(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('status', 'active')), $index, trusted: false);
        Assert::same($result[0], 'NOT (status = {p0:String})');
        Assert::same($result[1], ['p0' => 'active']);
    }

    public function dispatchNotWithDroppedInnerIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('secret', 'x')), $index, trusted: false);
        Assert::same($result[0], '');
    }

    public function dispatchAndX(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('status', 'a'), new GreaterThan('id', 5)), $index, trusted: false);
        Assert::same($result[0], '(status = {p0:String} AND id > {p1:UInt64})');
        Assert::same($result[1], ['p0' => 'a', 'p1' => 5]);
        Assert::same($index, 2);
    }

    public function dispatchOrX(): void
    {
        $index = 0;
        $result = $this->visitor->visitOrX(new OrX(new Equals('status', 'a'), new Equals('status', 'b')), $index, trusted: false);
        Assert::same($result[0], '(status = {p0:String} OR status = {p1:String})');
    }

    public function dispatchSkipsDisallowedSubFilters(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x'), new Equals('status', 'a')), $index, trusted: false);
        Assert::same($result[0], '(status = {p0:String})');
    }

    public function trustedBypassesAllowList(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('tenant_id', 5), $index, trusted: true);
        Assert::same($result[0], 'tenant_id = {p0:String}');
    }

    public function trustedRejectsMalformedIdentifier(): void
    {
        $index = 0;
        Expect::exception(\InvalidArgumentException::class);
        $this->visitor->visitEquals(new Equals('bad; DROP', 1), $index, trusted: true);
    }

    public function dispatchUnknownFilterReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->dispatch(new class implements \Yiisoft\Data\Reader\FilterInterface {}, $index, trusted: false);
        Assert::same($result, ['', []]);
    }

    public function dateTimeNormalizedWithoutTimezone(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['dt'], ['dt' => 'DateTime']);
        $index = 0;
        $dt = new \DateTimeImmutable('2024-06-15 12:00:00', new \DateTimeZone('Europe/Moscow'));
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, trusted: false);
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
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, trusted: false);
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
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, trusted: false);
        Assert::same($result[1], ['p0' => '2024-06-15 12:00:00']);
    }

    public function boolIsNormalizedToInt(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('id', value: true), $index, trusted: false);
        Assert::same($result[1], ['p0' => 1]);
    }

    public function compositeWithAllSubsDroppedIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x')), $index, trusted: false);
        Assert::same($result, ['', []]);
    }

    public function likeEscapesWildcards(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', "50%_off'x"), $index, trusted: false);
        Assert::same($result[1], ['p0' => "%50\\%\\_off'x%"]);
    }

    public function lessThanOrEqualUsesFieldType(): void
    {
        $index = 0;
        $result = $this->visitor->visitLessThanOrEqual(new LessThanOrEqual('created_at', '2024-01-01'), $index, trusted: false);
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
        $result = $this->visitor->visitLike(new Like('status', $value), $index, trusted: false);

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
        $result = $this->visitor->visitEquals(new Equals('status', $value), $index, trusted: false);

        Assert::same($result[1], ['p0' => 'abc']);
    }

    public function mutableDateTimeIsNotMutatedByNormalization(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['dt'], ['dt' => 'DateTime'], new \DateTimeZone('UTC'));
        $index = 0;
        $dt = new \DateTime('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));

        $visitor->visitEquals(new Equals('dt', $dt), $index, trusted: false);

        Assert::same($dt->getTimezone()->getName(), 'Europe/Moscow');
    }

    public function likeTreatsNullableStringTypeAsStringWithoutCast(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable(String)']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, trusted: false);

        Assert::same($result[0], 'x ILIKE {p0:String}');
    }

    public function likeNormalizesSpacesInTypeToken(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable( String )']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, trusted: false);

        Assert::same($result[0], 'x ILIKE {p0:String}');
    }

    public function likeTypeMustStartWithNullableToUnwrap(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'xNullable(String)']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, trusted: false);

        Assert::same($result[0], 'toString(x) ILIKE {p0:String}');
    }

    public function likeTypeMustEndWithClosingParenToUnwrap(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['x'], ['x' => 'Nullable(String)x']);
        $index = 0;
        $result = $visitor->visitLike(new Like('x', 'v'), $index, trusted: false);

        Assert::same($result[0], 'toString(x) ILIKE {p0:String}');
    }

    public function dispatchRoutesEqualsToVisitEquals(): void
    {
        $index = 0;
        $result = $this->visitor->dispatch(new Equals('status', 'active'), $index, trusted: false);

        Assert::same($result[0], 'status = {p0:String}');
    }

    #[DataProvider('disallowedFieldFilterProvider')]
    public function disallowedFieldReturnsEmptyPair(FilterInterface $filter): void
    {
        $index = 0;

        Assert::same($this->visitor->dispatch($filter, $index, trusted: false), ['', []]);
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

    #[Property(runs: 300)]
    public function equalsAlwaysBindsValueAsParameter(string $value): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('status', $value), $index, trusted: false);

        Assert::same($result[0], 'status = {p0:String}');
        Assert::same($result[1], ['p0' => $value]);
        Assert::same($index, 1);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function equalsAlwaysBindsValueAsParameterGenerators(): array
    {
        return ['value' => Gen::stringAscii()];
    }

    #[Property(runs: 300)]
    public function betweenAlwaysBindsExactlyTwoParameters(int $min, int $max): void
    {
        $index = 0;
        $result = $this->visitor->visitBetween(new Between('id', $min, $max), $index, trusted: false);

        Assert::true(str_contains($result[0], 'BETWEEN'));
        Assert::same(count($result[1]), 2);
        Assert::same($index, 2);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function betweenAlwaysBindsExactlyTwoParametersGenerators(): array
    {
        return [
            'min' => Gen::int(),
            'max' => Gen::int(),
        ];
    }

    #[Property(runs: 300)]
    public function inBindsOneParameterPerValue(array $values): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', $values), $index, trusted: false);

        Assert::true(str_contains($result[0], 'IN ('));
        Assert::same(count($result[1]), count($values));
        Assert::same($index, count($values));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function inBindsOneParameterPerValueGenerators(): array
    {
        return ['values' => Gen::nonEmptyArrayOf(Gen::intBetween(-1_000, 1_000))];
    }

    /**
     * Issue #34: two raw siblings reusing `v` used to merge by name, so the
     * SQL kept both tokens while only the last value was bound.
     */
    public function compositeRenamesARawNameTakenByAnEarlierRawSibling(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(
            new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
            new ClickHouseRawFilter('id < {v:UInt64}', ['v' => 5]),
        ), $index, trusted: false);

        Assert::same($result[0], '(id > {v:UInt64} AND id < {v_0:UInt64})');
        Assert::same($result[1], ['v' => 1, 'v_0' => 5]);
    }

    #[DataProvider('rawVersusBuilderKeyProvider')]
    public function compositeRenamesWhicheverSiblingComesSecondWhenARawNameMatchesABuilderKey(
        FilterInterface $filter,
        string $expectedSql,
        array $expectedParams,
    ): void {
        $index = 0;
        $result = $this->visitor->dispatch($filter, $index, trusted: false);

        Assert::same($result[0], $expectedSql);
        Assert::same($result[1], $expectedParams);
    }

    /**
     * @return iterable<string, array{FilterInterface, string, array<string, mixed>}>
     */
    public static function rawVersusBuilderKeyProvider(): iterable
    {
        yield 'builder key first' => [
            new AndX(new Equals('id', 7), new ClickHouseRawFilter('id < {p0:UInt64}', ['p0' => 5])),
            '(id = {p0:UInt64} AND id < {p0_0:UInt64})',
            ['p0' => 7, 'p0_0' => 5],
        ];
        yield 'raw first' => [
            new AndX(new ClickHouseRawFilter('id < {p0:UInt64}', ['p0' => 5]), new Equals('id', 7)),
            '(id < {p0:UInt64} AND id = {p0_0:UInt64})',
            ['p0' => 5, 'p0_0' => 7],
        ];
        yield 'raw name reused inside a nested OR' => [
            new AndX(
                new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
                new OrX(
                    new ClickHouseRawFilter('id = {v:UInt64}', ['v' => 2]),
                    new ClickHouseRawFilter('id = {v:UInt64} AND id < {v_max:UInt64}', ['v' => 3, 'v_max' => 9]),
                ),
            ),
            '(id > {v:UInt64} AND (id = {v_0:UInt64} OR id = {v_0_0:UInt64} AND id < {v_max:UInt64}))',
            ['v' => 1, 'v_0' => 2, 'v_0_0' => 3, 'v_max' => 9],
        ];
        yield 'NOT passes a single child through unchanged' => [
            new Not(new ClickHouseRawFilter('id = {v:UInt64}', ['v' => 2])),
            'NOT (id = {v:UInt64})',
            ['v' => 2],
        ];
    }

    /**
     * Whatever the tree, every `{name:` token in the SQL is bound by exactly
     * one key, every key is referenced, and no leaf value is lost — the
     * invariant that a merge by name broke (#34).
     */
    #[Property(runs: 300)]
    public function everyPlaceholderIsBoundExactlyOnceAndNoValueIsLost(FilterInterface $tree): void
    {
        $index = 0;
        [$sql, $params] = $this->visitor->dispatch($tree, $index, trusted: false);

        preg_match_all('/\{(\w+):/', $sql, $matches);
        $referenced = array_values(array_unique($matches[1]));
        $bound = array_keys($params);
        sort($referenced);
        sort($bound);
        Assert::same($referenced, $bound);

        $expectedValues = self::leafValues($tree);
        $boundValues = array_values($params);
        sort($expectedValues);
        sort($boundValues);
        Assert::same($boundValues, $expectedValues);

        Classify::cover(condition: in_array('v_0', $bound, strict: true), label: 'raw name renamed', minPercent: 15);
        Classify::cover(condition: in_array('p0_0', $bound, strict: true) || in_array('p1_0', $bound, strict: true), label: 'builder key renamed', minPercent: 15);
        Classify::cover(condition: str_contains($sql, 'OR'), label: 'has OR', minPercent: 30);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyPlaceholderIsBoundExactlyOnceAndNoValueIsLostGenerators(): array
    {
        $leaf = Gen::frequency([
            [3, Gen::elements([
                new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
                new ClickHouseRawFilter('id < {v:UInt64}', ['v' => 5]),
                new ClickHouseRawFilter('id BETWEEN {v:UInt64} AND {v_max:UInt64}', ['v' => 2, 'v_max' => 8]),
                new ClickHouseRawFilter('id = {p0:UInt64}', ['p0' => 3]),
                new ClickHouseRawFilter('id = {p1:UInt64}', ['p1' => 4]),
            ])],
            [2, Gen::elements([
                new Equals('id', 7),
                new GreaterThan('id', 6),
                new In('id', [10, 11]),
                new Between('id', 20, 30),
            ])],
        ]);
        $combine = static fn(ArbitraryInterface $inner): ArbitraryInterface => Gen::frequency([
            [1, Gen::map($inner, static fn(FilterInterface $f): Not => new Not($f))],
            [2, Gen::map(Gen::tuple($inner, $inner), static fn(array $pair): AndX => new AndX($pair[0], $pair[1]))],
            [2, Gen::map(Gen::tuple($inner, $inner), static fn(array $pair): OrX => new OrX($pair[0], $pair[1]))],
        ]);

        return ['tree' => $combine(Gen::recursive(leaf: $leaf, wrap: $combine, maxDepth: 2))];
    }

    /**
     * @return iterable<string, array{FilterInterface}>
     */
    public static function everyPlaceholderIsBoundExactlyOnceAndNoValueIsLostExamples(): iterable
    {
        yield 'issue #34: raw siblings share v' => [new AndX(
            new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
            new ClickHouseRawFilter('id < {v:UInt64}', ['v' => 5]),
        )];
        yield 'issue #34: raw p0 next to a builder p0' => [new AndX(
            new Equals('id', 7),
            new ClickHouseRawFilter('id < {p0:UInt64}', ['p0' => 5]),
        )];
        yield 'renamed name collides again one level up' => [new AndX(
            new ClickHouseRawFilter('id > {v:UInt64}', ['v' => 1]),
            new OrX(
                new ClickHouseRawFilter('id = {v:UInt64}', ['v' => 2]),
                new ClickHouseRawFilter('id = {v:UInt64}', ['v' => 5]),
            ),
        )];
    }

    /**
     * @return list<mixed> Every value the tree's leaves would bind, in no particular order.
     */
    private static function leafValues(FilterInterface $filter): array
    {
        return match (true) {
            $filter instanceof ClickHouseRawFilter => array_values($filter->params),
            $filter instanceof Not => self::leafValues($filter->filter),
            $filter instanceof AndX, $filter instanceof OrX => array_merge(
                ...array_map(self::leafValues(...), array_values($filter->filters)),
            ),
            $filter instanceof In => array_values($filter->values),
            $filter instanceof Between => [$filter->minValue, $filter->maxValue],
            $filter instanceof Equals, $filter instanceof GreaterThan => [$filter->value],
            default => [],
        };
    }
}
