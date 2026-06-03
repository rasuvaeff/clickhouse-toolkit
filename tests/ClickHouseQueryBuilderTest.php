<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseSqlFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\WhereClause;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\EqualsNull;
use Yiisoft\Data\Reader\Filter\GreaterThan;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\LessThan;
use Yiisoft\Data\Reader\Filter\LessThanOrEqual;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\LikeMode;
use Yiisoft\Data\Reader\Filter\None;
use Yiisoft\Data\Reader\Filter\Not;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\Sort;

#[CoversClass(ClickHouseQueryBuilder::class)]
#[CoversClass(ClickHouseSqlFilterVisitor::class)]
#[CoversClass(ClickHouseRawFilter::class)]
#[CoversClass(WhereClause::class)]
final class ClickHouseQueryBuilderTest extends TestCase
{
    private ClickHouseQueryBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        $this->builder = new ClickHouseQueryBuilder(
            allowedFields: ['id', 'name', 'status', 'created_at', 'email', 'is_active'],
            fieldTypes: [
                'id' => 'UInt64',
                'created_at' => 'DateTime',
                'is_active' => 'UInt8',
            ],
            defaultSort: 'id DESC',
        );
    }

    #[Test]
    public function allFilterReturnsEmptyClause(): void
    {
        $clause = $this->builder->buildWhere(new All());

        $this->assertTrue($clause->isEmpty(), 'All фильтр должен давать пустой WHERE');
        $this->assertSame('', $clause->sql);
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function noneFilterMatchesNothing(): void
    {
        $clause = $this->builder->buildWhere(new None());

        $this->assertSame('0', $clause->sql, 'None должен исключать все строки');
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function equals(): void
    {
        $clause = $this->builder->buildWhere(new Equals('status', 'active'));

        $this->assertSame('status = {p0:String}', $clause->sql);
        $this->assertSame(['p0' => 'active'], $clause->params);
    }

    #[Test]
    public function equalsIgnoresDisallowedField(): void
    {
        $clause = $this->builder->buildWhere(new Equals('secret', 'value'));

        $this->assertTrue($clause->isEmpty(), 'Запрещённое поле не должно попасть в WHERE');
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function comparisonOperators(): void
    {
        $this->assertSame('id > {p0:UInt64}', $this->builder->buildWhere(new GreaterThan('id', 1))->sql);
        $this->assertSame('id >= {p0:UInt64}', $this->builder->buildWhere(new GreaterThanOrEqual('id', 1))->sql);
        $this->assertSame('id < {p0:UInt64}', $this->builder->buildWhere(new LessThan('id', 1))->sql);
        $this->assertSame('id <= {p0:UInt64}', $this->builder->buildWhere(new LessThanOrEqual('id', 1))->sql);
    }

    #[Test]
    public function equalsNull(): void
    {
        $clause = $this->builder->buildWhere(new EqualsNull('email'));

        $this->assertSame('email IS NULL', $clause->sql);
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function defaultTypeIsString(): void
    {
        $clause = $this->builder->buildWhere(new Equals('name', 'john'));

        $this->assertSame('name = {p0:String}', $clause->sql, 'Поле без явного типа должно использовать String');
    }

    #[Test]
    public function dateTimeValueIsNormalized(): void
    {
        $clause = $this->builder->buildWhere(new Equals('created_at', new \DateTimeImmutable('2024-01-02 03:04:05')));

        $this->assertSame('created_at = {p0:DateTime}', $clause->sql);
        $this->assertSame(['p0' => '2024-01-02 03:04:05'], $clause->params);
    }

    #[Test]
    public function boolValueIsNormalizedToInt(): void
    {
        $clause = $this->builder->buildWhere(new Equals('is_active', true));

        $this->assertSame(['p0' => 1], $clause->params, 'bool должен превращаться в 0/1');
    }

    #[Test]
    public function likeContainsCaseInsensitiveByDefault(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', 'test'));

        $this->assertSame('name ILIKE {p0:String}', $clause->sql);
        $this->assertSame(['p0' => '%test%'], $clause->params);
    }

    #[Test]
    public function likeCastsNonStringFieldToString(): void
    {
        $clause = $this->builder->buildWhere(new Like('id', '12'));

        $this->assertSame('toString(id) ILIKE {p0:String}', $clause->sql);
        $this->assertSame(['p0' => '%12%'], $clause->params);
    }

    #[Test]
    public function likeWithEmptyValueIsDropped(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', ''));

        $this->assertTrue($clause->isEmpty());
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function likeStartsWithAndEndsWith(): void
    {
        $this->assertSame(['p0' => 'test%'], $this->builder->buildWhere(new Like('name', 'test', mode: LikeMode::StartsWith))->params);
        $this->assertSame(['p0' => '%test'], $this->builder->buildWhere(new Like('name', 'test', mode: LikeMode::EndsWith))->params);
    }

    #[Test]
    public function likeCaseSensitiveUsesLike(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', 'test', caseSensitive: true));

        $this->assertSame('name LIKE {p0:String}', $clause->sql);
    }

    #[Test]
    public function likeEscapesWildcardsInValueNotQuotes(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', "50%_off'x"));

        // Wildcards экранируются, кавычка — нет (значение уходит как bound-параметр).
        $this->assertSame(['p0' => "%50\\%\\_off'x%"], $clause->params);
    }

    #[Test]
    public function in(): void
    {
        $clause = $this->builder->buildWhere(new In('id', [10, 20, 30]));

        $this->assertSame('id IN ({p0:UInt64}, {p1:UInt64}, {p2:UInt64})', $clause->sql);
        $this->assertSame(['p0' => 10, 'p1' => 20, 'p2' => 30], $clause->params);
    }

    #[Test]
    public function inWithEmptyValuesMatchesNothing(): void
    {
        $clause = $this->builder->buildWhere(new In('id', []));

        $this->assertSame('0', $clause->sql, 'Пустой IN должен исключать все строки, а не отбрасываться');
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function between(): void
    {
        $clause = $this->builder->buildWhere(new Between('id', 100, 200));

        $this->assertSame('id BETWEEN {p0:UInt64} AND {p1:UInt64}', $clause->sql);
        $this->assertSame(['p0' => 100, 'p1' => 200], $clause->params);
    }

    #[Test]
    public function not(): void
    {
        $clause = $this->builder->buildWhere(new Not(new Equals('status', 'active')));

        $this->assertSame('NOT (status = {p0:String})', $clause->sql);
        $this->assertSame(['p0' => 'active'], $clause->params);
    }

    #[Test]
    public function notWithDroppedInnerFilterIsDropped(): void
    {
        $clause = $this->builder->buildWhere(new Not(new Equals('secret', 'x')));

        $this->assertTrue($clause->isEmpty(), 'NOT вокруг отброшенного фильтра должен сам отбрасываться');
    }

    #[Test]
    public function andX(): void
    {
        $clause = $this->builder->buildWhere(new AndX(
            new Equals('status', 'active'),
            new GreaterThanOrEqual('id', 10),
        ));

        $this->assertSame('(status = {p0:String} AND id >= {p1:UInt64})', $clause->sql);
        $this->assertSame(['p0' => 'active', 'p1' => 10], $clause->params);
    }

    #[Test]
    public function orXWithSameFieldKeepsBothBranches(): void
    {
        $clause = $this->builder->buildWhere(new OrX(
            new Equals('status', 'active'),
            new Equals('status', 'pending'),
        ));

        // Регрессия: раньше одинаковый ключ затирал ветку 'active'.
        $this->assertSame('(status = {p0:String} OR status = {p1:String})', $clause->sql);
        $this->assertSame(['p0' => 'active', 'p1' => 'pending'], $clause->params);
    }

    #[Test]
    public function nestedCompositeKeepsUniqueParamKeys(): void
    {
        $clause = $this->builder->buildWhere(new AndX(
            new Equals('status', 'active'),
            new OrX(
                new GreaterThanOrEqual('id', 1),
                new LessThanOrEqual('id', 100),
            ),
        ));

        $this->assertSame('(status = {p0:String} AND (id >= {p1:UInt64} OR id <= {p2:UInt64}))', $clause->sql);
        $this->assertSame(['p0' => 'active', 'p1' => 1, 'p2' => 100], $clause->params);
    }

    #[Test]
    public function compositeSkipsEmptySubFilters(): void
    {
        $clause = $this->builder->buildWhere(new AndX(new Equals('disallowed', 'val')));

        $this->assertTrue($clause->isEmpty());
        $this->assertSame([], $clause->params);
    }

    #[Test]
    public function orderByNullReturnsDefault(): void
    {
        $this->assertSame('id DESC', $this->builder->buildOrderBy(null));
    }

    #[Test]
    public function orderByNullReturnsEmptyWithoutDefaultSort(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['id']);

        $this->assertSame('', $builder->buildOrderBy(null));
    }

    #[Test]
    public function orderByEmptyCriteriaReturnsDefault(): void
    {
        $this->assertSame('id DESC', $this->builder->buildOrderBy(Sort::only([])));
    }

    #[Test]
    public function orderByWithCriteria(): void
    {
        $sort = Sort::only(['id', 'name'])->withOrder(['id' => 'desc', 'name' => 'asc']);

        $this->assertSame('id DESC, name ASC', $this->builder->buildOrderBy($sort));
    }

    #[Test]
    public function orderByDropsDisallowedFields(): void
    {
        $onlyDisallowed = Sort::only(['secret'])->withOrder(['secret' => 'asc']);
        $this->assertSame('id DESC', $this->builder->buildOrderBy($onlyDisallowed), 'Сортировка только по запрещённому полю -> дефолт');

        $mixed = Sort::only(['id', 'secret'])->withOrder(['secret' => 'asc', 'id' => 'desc']);
        $this->assertSame('id DESC', $this->builder->buildOrderBy($mixed), 'Запрещённое поле отбрасывается, разрешённое остаётся');
    }

    #[Test]
    public function selectSelectsAllByDefault(): void
    {
        $sql = $this->builder->buildSelect(table: 'events', limit: 10, offset: 0);

        $this->assertSame('SELECT * FROM events ORDER BY id DESC LIMIT 10 OFFSET 0', $sql);
    }

    #[Test]
    public function selectOmitsOrderByWithoutDefaultSort(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['id']);

        $sql = $builder->buildSelect(table: 'events', limit: 10, offset: 0);

        $this->assertSame('SELECT * FROM events LIMIT 10 OFFSET 0', $sql);
    }

    #[Test]
    public function selectWithColumnProjection(): void
    {
        $sql = $this->builder->buildSelect(
            table: 'events',
            columns: ['id', 'name'],
            where: 'status = {p0:String}',
            orderBy: 'id ASC',
            limit: 50,
            offset: 100,
        );

        $this->assertSame('SELECT id, name FROM events WHERE status = {p0:String} ORDER BY id ASC LIMIT 50 OFFSET 100', $sql);
    }

    #[Test]
    public function selectWithNullLimitOmitsLimitClause(): void
    {
        $sql = $this->builder->buildSelect(table: 'events', columns: ['id'], limit: null);

        $this->assertSame('SELECT id FROM events ORDER BY id DESC', $sql);
    }

    #[Test]
    public function countQuery(): void
    {
        $this->assertSame('SELECT count() AS cnt FROM events', $this->builder->buildCount(table: 'events'));
        $this->assertSame('SELECT count() AS cnt FROM events WHERE status = {p0:String}', $this->builder->buildCount(table: 'events', where: 'status = {p0:String}'));
    }

    #[Test]
    public function distinct(): void
    {
        $this->assertSame('SELECT DISTINCT status FROM events ORDER BY status', $this->builder->buildDistinct(table: 'events', column: 'status'));
    }

    #[Test]
    public function selectAllowsDbQualifiedTable(): void
    {
        $sql = $this->builder->buildSelect(table: 'analytics.events', limit: 5);

        $this->assertStringStartsWith('SELECT * FROM analytics.events', $sql);
    }

    #[Test]
    public function selectRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events; DROP TABLE users');
    }

    #[Test]
    public function selectRejectsMalformedColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', columns: ['id', 'name)) --']);
    }

    #[Test]
    public function selectRejectsNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', limit: -1);
    }

    #[Test]
    public function selectRejectsNegativeOffset(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', offset: -10);
    }

    #[Test]
    public function distinctRejectsMalformedColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildDistinct(table: 'events', column: 'status FROM events;');
    }

    #[Test]
    public function constructorAllowsParametricTypes(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['tags'],
            fieldTypes: ['tags' => 'Array(Nullable(String))'],
        );

        $this->assertSame('tags = {p0:Array(Nullable(String))}', $builder->buildWhere(new Equals('tags', 'x'))->sql);
    }

    #[Test]
    public function constructorRejectsMalformedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id'], fieldTypes: ['id' => 'UInt64} OR 1=1 --']);
    }

    #[Test]
    public function constructorRejectsMalformedAllowedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id', 'name); DROP TABLE users --']);
    }

    #[Test]
    public function rawFilterIsEmittedVerbatimWithParams(): void
    {
        $clause = $this->builder->buildWhere(new ClickHouseRawFilter('toDate(created_at) = {d:Date}', ['d' => '2024-01-01']));

        $this->assertSame('toDate(created_at) = {d:Date}', $clause->sql);
        $this->assertSame(['d' => '2024-01-01'], $clause->params);
    }

    #[Test]
    public function buildWhereWithoutFilterIsEmptyWhenNoMandatory(): void
    {
        $this->assertTrue($this->builder->buildWhere()->isEmpty());
    }

    #[Test]
    public function mandatoryFilterIsAndCombinedAndBypassesAllowList(): void
    {
        // tenant_id is NOT in allowedFields, but a mandatory (trusted) filter still applies.
        $builder = $this->builder->withMandatoryFilter(new Equals('tenant_id', 5));

        $clause = $builder->buildWhere(new Equals('status', 'active'));

        $this->assertSame('(tenant_id = {p0:String}) AND (status = {p1:String})', $clause->sql);
        $this->assertSame(['p0' => 5, 'p1' => 'active'], $clause->params);
    }

    #[Test]
    public function mandatoryFilterAppliesWithoutUserFilter(): void
    {
        $clause = $this->builder->withMandatoryFilter(new Equals('tenant_id', 5))->buildWhere();

        $this->assertSame('tenant_id = {p0:String}', $clause->sql);
        $this->assertSame(['p0' => 5], $clause->params);
    }

    #[Test]
    public function mandatoryFiltersChainWithAnd(): void
    {
        $clause = $this->builder
            ->withMandatoryFilter(new Equals('tenant_id', 5))
            ->withMandatoryFilter(new EqualsNull('deleted_at'))
            ->buildWhere();

        $this->assertSame('(tenant_id = {p0:String} AND deleted_at IS NULL)', $clause->sql);
        $this->assertSame(['p0' => 5], $clause->params);
    }

    #[Test]
    public function mandatoryFilterStillConstrainsWhenUserFilterDropped(): void
    {
        // A disallowed user field is dropped, but the mandatory constraint remains.
        $clause = $this->builder
            ->withMandatoryFilter(new Equals('tenant_id', 5))
            ->buildWhere(new Equals('secret', 'x'));

        $this->assertSame('tenant_id = {p0:String}', $clause->sql);
    }

    #[Test]
    public function mandatoryFilterValidatesIdentifier(): void
    {
        $builder = $this->builder->withMandatoryFilter(new Equals('bad); DROP --', 1));

        $this->expectException(\InvalidArgumentException::class);

        $builder->buildWhere();
    }

    #[Test]
    public function createAndWithDefaultSort(): void
    {
        $builder = ClickHouseQueryBuilder::create(allowedFields: ['id'])->withDefaultSort('id ASC');

        $this->assertSame('id ASC', $builder->buildOrderBy(null));
    }

    #[Test]
    public function serverTimezoneConvertsDateTimeValue(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['created_at'],
            fieldTypes: ['created_at' => 'DateTime'],
            serverTimezone: 'UTC',
        );

        $moscow = new \DateTimeImmutable('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $clause = $builder->buildWhere(new Equals('created_at', $moscow));

        $this->assertSame(['p0' => '2024-06-15 12:00:00'], $clause->params);
    }

    #[Test]
    public function serverTimezoneNullKeepsObjectTimezone(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['created_at'],
            fieldTypes: ['created_at' => 'DateTime'],
        );

        $moscow = new \DateTimeImmutable('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $clause = $builder->buildWhere(new Equals('created_at', $moscow));

        $this->assertSame(['p0' => '2024-06-15 15:00:00'], $clause->params);
    }

    #[Test]
    public function withServerTimezoneReturnsNewInstance(): void
    {
        $original = new ClickHouseQueryBuilder(allowedFields: ['dt'], fieldTypes: ['dt' => 'DateTime']);
        $withTz = $original->withServerTimezone('UTC');

        $moscow = new \DateTimeImmutable('2024-01-01 03:00:00', new \DateTimeZone('Europe/Moscow'));

        $this->assertSame(['p0' => '2024-01-01 03:00:00'], $original->buildWhere(new Equals('dt', $moscow))->params);
        $this->assertSame(['p0' => '2024-01-01 00:00:00'], $withTz->buildWhere(new Equals('dt', $moscow))->params);
    }

    #[Test]
    public function serverTimezoneInWithMultipleValues(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['created_at'],
            fieldTypes: ['created_at' => 'DateTime'],
            serverTimezone: 'UTC',
        );

        $clause = $builder->buildWhere(new In('created_at', [
            '2024-01-01 02:00:00',
            '2024-01-01 05:00:00',
        ]));

        $this->assertSame(['p0' => '2024-01-01 02:00:00', 'p1' => '2024-01-01 05:00:00'], $clause->params);
    }

    #[Test]
    public function customVisitorIsUsedForSqlGeneration(): void
    {
        $visitor = $this->createMock(ClickHouseFilterVisitor::class);
        $visitor->method('dispatch')->willReturn(['custom_sql = 1', ['x' => 9]]);

        $builder = ClickHouseQueryBuilder::create(['id'])->withVisitor($visitor);
        $clause = $builder->buildWhere(new Equals('id', 1));

        $this->assertSame('custom_sql = 1', $clause->sql);
        $this->assertSame(['x' => 9], $clause->params);
    }

    #[Test]
    public function buildOrderByKeepsAllowedFieldAfterDisallowed(): void
    {
        $sort = Sort::only(['secret', 'name'])->withOrder(['secret' => 'asc', 'name' => 'asc']);

        // continue (не break): запрещённое поле пропускается, разрешённое после него остаётся.
        $this->assertSame('name ASC', $this->builder->buildOrderBy($sort));
    }

    #[Test]
    public function buildSelectUsesDefaultLimitAndOffset(): void
    {
        $this->assertSame(
            'SELECT * FROM events ORDER BY id DESC LIMIT 20 OFFSET 0',
            $this->builder->buildSelect(table: 'events'),
        );
    }

    #[Test]
    public function buildSelectAllowsZeroLimit(): void
    {
        $this->assertSame(
            'SELECT * FROM events ORDER BY id DESC LIMIT 0 OFFSET 0',
            $this->builder->buildSelect(table: 'events', limit: 0),
        );
    }

    #[Test]
    public function buildCountRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildCount(table: 'events; DROP TABLE x');
    }

    #[Test]
    public function buildDistinctRejectsMalformedTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder->buildDistinct(table: 'events; DROP TABLE x', column: 'status');
    }

    #[Test]
    public function constructorRejectsEmptyServerTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id'], serverTimezone: '');
    }
}
