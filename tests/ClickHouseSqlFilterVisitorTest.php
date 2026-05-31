<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseSqlFilterVisitor;
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

#[CoversClass(ClickHouseSqlFilterVisitor::class)]
final class ClickHouseSqlFilterVisitorTest extends TestCase
{
    private ClickHouseSqlFilterVisitor $visitor;

    #[\Override]
    protected function setUp(): void
    {
        $this->visitor = new ClickHouseSqlFilterVisitor(
            allowedFields: ['id', 'status', 'created_at'],
            fieldTypes: ['id' => 'UInt64', 'created_at' => 'DateTime'],
        );
    }

    #[Test]
    public function dispatchAllReturnsEmpty(): void
    {
        $index = 0;
        $this->assertSame(['', []], $this->visitor->visitAll(new All(), $index, false));
    }

    #[Test]
    public function dispatchNoneReturnsZero(): void
    {
        $index = 0;
        $this->assertSame(['0', []], $this->visitor->visitNone(new None(), $index, false));
    }

    #[Test]
    public function dispatchEquals(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('status', 'active'), $index, false);
        $this->assertSame('status = {p0:String}', $result[0]);
        $this->assertSame(['p0' => 'active'], $result[1]);
        $this->assertSame(1, $index);
    }

    #[Test]
    public function dispatchEqualsDisallowedFieldReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('secret', 'x'), $index, false);
        $this->assertSame('', $result[0]);
        $this->assertSame(0, $index);
    }

    #[Test]
    public function dispatchGreaterThanIncrementsIndex(): void
    {
        $index = 5;
        $result = $this->visitor->visitGreaterThan(new GreaterThan('id', 10), $index, false);
        $this->assertSame('id > {p5:UInt64}', $result[0]);
        $this->assertSame(6, $index);
    }

    #[Test]
    public function dispatchInWithMultipleValues(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', [1, 2, 3]), $index, false);
        $this->assertSame('id IN ({p0:UInt64}, {p1:UInt64}, {p2:UInt64})', $result[0]);
        $this->assertSame(['p0' => 1, 'p1' => 2, 'p2' => 3], $result[1]);
        $this->assertSame(3, $index);
    }

    #[Test]
    public function dispatchInWithEmptyValuesMatchesNothing(): void
    {
        $index = 0;
        $result = $this->visitor->visitIn(new In('id', []), $index, false);
        $this->assertSame('0', $result[0]);
        $this->assertSame(0, $index);
    }

    #[Test]
    public function dispatchBetween(): void
    {
        $index = 0;
        $result = $this->visitor->visitBetween(new Between('id', 10, 20), $index, false);
        $this->assertSame('id BETWEEN {p0:UInt64} AND {p1:UInt64}', $result[0]);
        $this->assertSame(['p0' => 10, 'p1' => 20], $result[1]);
        $this->assertSame(2, $index);
    }

    #[Test]
    public function dispatchEqualsNull(): void
    {
        $index = 0;
        $result = $this->visitor->visitEqualsNull(new EqualsNull('status'), $index, false);
        $this->assertSame('status IS NULL', $result[0]);
        $this->assertSame([], $result[1]);
    }

    #[Test]
    public function dispatchLikeContains(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act'), $index, false);
        $this->assertSame('status ILIKE {p0:String}', $result[0]);
        $this->assertSame(['p0' => '%act%'], $result[1]);
    }

    #[Test]
    public function dispatchLikeCastsNonStringFieldToString(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('id', '12'), $index, false);
        $this->assertSame('toString(id) ILIKE {p0:String}', $result[0]);
        $this->assertSame(['p0' => '%12%'], $result[1]);
    }

    #[Test]
    public function dispatchLikeWithEmptyValueIsDropped(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', ''), $index, false);
        $this->assertSame(['', []], $result);
        $this->assertSame(0, $index);
    }

    #[Test]
    public function dispatchLikeStartsWithCaseSensitive(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', 'act', caseSensitive: true, mode: LikeMode::StartsWith), $index, false);
        $this->assertSame('status LIKE {p0:String}', $result[0]);
        $this->assertSame(['p0' => 'act%'], $result[1]);
    }

    #[Test]
    public function dispatchNotWrapsInner(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('status', 'active')), $index, false);
        $this->assertSame('NOT (status = {p0:String})', $result[0]);
        $this->assertSame(['p0' => 'active'], $result[1]);
    }

    #[Test]
    public function dispatchNotWithDroppedInnerIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitNot(new Not(new Equals('secret', 'x')), $index, false);
        $this->assertSame('', $result[0]);
    }

    #[Test]
    public function dispatchAndX(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('status', 'a'), new GreaterThan('id', 5)), $index, false);
        $this->assertSame('(status = {p0:String} AND id > {p1:UInt64})', $result[0]);
        $this->assertSame(['p0' => 'a', 'p1' => 5], $result[1]);
        $this->assertSame(2, $index);
    }

    #[Test]
    public function dispatchOrX(): void
    {
        $index = 0;
        $result = $this->visitor->visitOrX(new OrX(new Equals('status', 'a'), new Equals('status', 'b')), $index, false);
        $this->assertSame('(status = {p0:String} OR status = {p1:String})', $result[0]);
    }

    #[Test]
    public function dispatchSkipsDisallowedSubFilters(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x'), new Equals('status', 'a')), $index, false);
        $this->assertSame('(status = {p0:String})', $result[0]);
    }

    #[Test]
    public function trustedBypassesAllowList(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('tenant_id', 5), $index, true);
        $this->assertSame('tenant_id = {p0:String}', $result[0]);
    }

    #[Test]
    public function trustedRejectsMalformedIdentifier(): void
    {
        $index = 0;
        $this->expectException(\InvalidArgumentException::class);
        $this->visitor->visitEquals(new Equals('bad; DROP', 1), $index, true);
    }

    #[Test]
    public function dispatchUnknownFilterReturnsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->dispatch(new class implements \Yiisoft\Data\Reader\FilterInterface {}, $index, false);
        $this->assertSame(['', []], $result);
    }

    #[Test]
    public function dateTimeNormalizedWithoutTimezone(): void
    {
        $visitor = new ClickHouseSqlFilterVisitor(['dt'], ['dt' => 'DateTime']);
        $index = 0;
        $dt = new \DateTimeImmutable('2024-06-15 12:00:00', new \DateTimeZone('Europe/Moscow'));
        $result = $visitor->visitEquals(new Equals('dt', $dt), $index, false);
        $this->assertSame(['p0' => '2024-06-15 12:00:00'], $result[1]);
    }

    #[Test]
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
        $this->assertSame(['p0' => '2024-06-15 12:00:00'], $result[1]);
    }

    #[Test]
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
        $this->assertSame(['p0' => '2024-06-15 12:00:00'], $result[1]);
    }

    #[Test]
    public function boolIsNormalizedToInt(): void
    {
        $index = 0;
        $result = $this->visitor->visitEquals(new Equals('id', true), $index, false);
        $this->assertSame(['p0' => 1], $result[1]);
    }

    #[Test]
    public function compositeWithAllSubsDroppedIsEmpty(): void
    {
        $index = 0;
        $result = $this->visitor->visitAndX(new AndX(new Equals('secret', 'x')), $index, false);
        $this->assertSame(['', []], $result);
    }

    #[Test]
    public function likeEscapesWildcards(): void
    {
        $index = 0;
        $result = $this->visitor->visitLike(new Like('status', "50%_off'x"), $index, false);
        $this->assertSame(['p0' => "%50\\%\\_off'x%"], $result[1]);
    }

    #[Test]
    public function lessThanOrEqualUsesFieldType(): void
    {
        $index = 0;
        $result = $this->visitor->visitLessThanOrEqual(new LessThanOrEqual('created_at', '2024-01-01'), $index, false);
        $this->assertSame('created_at <= {p0:DateTime}', $result[0]);
    }
}
