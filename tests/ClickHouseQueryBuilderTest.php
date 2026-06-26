<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\ClickHouseFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Rasuvaeff\ClickHouseToolkit\ClickHouseRawFilter;
use Rasuvaeff\ClickHouseToolkit\ClickHouseSqlFilterVisitor;
use Rasuvaeff\ClickHouseToolkit\WhereClause;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
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

#[Test]
#[Covers(ClickHouseQueryBuilder::class)]
#[Covers(ClickHouseSqlFilterVisitor::class)]
#[Covers(ClickHouseRawFilter::class)]
#[Covers(WhereClause::class)]
final class ClickHouseQueryBuilderTest
{
    private ClickHouseQueryBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
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

    public function allFilterReturnsEmptyClause(): void
    {
        $clause = $this->builder->buildWhere(new All());

        Assert::true($clause->isEmpty());
        Assert::same($clause->sql, '');
        Assert::same($clause->params, []);
    }

    public function noneFilterMatchesNothing(): void
    {
        $clause = $this->builder->buildWhere(new None());

        Assert::same($clause->sql, '0');
        Assert::same($clause->params, []);
    }

    public function equals(): void
    {
        $clause = $this->builder->buildWhere(new Equals('status', 'active'));

        Assert::same($clause->sql, 'status = {p0:String}');
        Assert::same($clause->params, ['p0' => 'active']);
    }

    public function equalsIgnoresDisallowedField(): void
    {
        $clause = $this->builder->buildWhere(new Equals('secret', 'value'));

        Assert::true($clause->isEmpty());
        Assert::same($clause->params, []);
    }

    public function comparisonOperators(): void
    {
        Assert::same($this->builder->buildWhere(new GreaterThan('id', 1))->sql, 'id > {p0:UInt64}');
        Assert::same($this->builder->buildWhere(new GreaterThanOrEqual('id', 1))->sql, 'id >= {p0:UInt64}');
        Assert::same($this->builder->buildWhere(new LessThan('id', 1))->sql, 'id < {p0:UInt64}');
        Assert::same($this->builder->buildWhere(new LessThanOrEqual('id', 1))->sql, 'id <= {p0:UInt64}');
    }

    public function equalsNull(): void
    {
        $clause = $this->builder->buildWhere(new EqualsNull('email'));

        Assert::same($clause->sql, 'email IS NULL');
        Assert::same($clause->params, []);
    }

    public function defaultTypeIsString(): void
    {
        $clause = $this->builder->buildWhere(new Equals('name', 'john'));

        Assert::same($clause->sql, 'name = {p0:String}');
    }

    public function dateTimeValueIsNormalized(): void
    {
        $clause = $this->builder->buildWhere(new Equals('created_at', new \DateTimeImmutable('2024-01-02 03:04:05')));

        Assert::same($clause->sql, 'created_at = {p0:DateTime}');
        Assert::same($clause->params, ['p0' => '2024-01-02 03:04:05']);
    }

    public function boolValueIsNormalizedToInt(): void
    {
        $clause = $this->builder->buildWhere(new Equals('is_active', true));

        Assert::same($clause->params, ['p0' => 1]);
    }

    public function likeContainsCaseInsensitiveByDefault(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', 'test'));

        Assert::same($clause->sql, 'name ILIKE {p0:String}');
        Assert::same($clause->params, ['p0' => '%test%']);
    }

    public function likeCastsNonStringFieldToString(): void
    {
        $clause = $this->builder->buildWhere(new Like('id', '12'));

        Assert::same($clause->sql, 'toString(id) ILIKE {p0:String}');
        Assert::same($clause->params, ['p0' => '%12%']);
    }

    public function likeWithEmptyValueIsDropped(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', ''));

        Assert::true($clause->isEmpty());
        Assert::same($clause->params, []);
    }

    public function likeStartsWithAndEndsWith(): void
    {
        Assert::same($this->builder->buildWhere(new Like('name', 'test', mode: LikeMode::StartsWith))->params, ['p0' => 'test%']);
        Assert::same($this->builder->buildWhere(new Like('name', 'test', mode: LikeMode::EndsWith))->params, ['p0' => '%test']);
    }

    public function likeCaseSensitiveUsesLike(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', 'test', caseSensitive: true));

        Assert::same($clause->sql, 'name LIKE {p0:String}');
    }

    public function likeEscapesWildcardsInValueNotQuotes(): void
    {
        $clause = $this->builder->buildWhere(new Like('name', "50%_off'x"));

        Assert::same($clause->params, ['p0' => "%50\\%\\_off'x%"]);
    }

    public function in(): void
    {
        $clause = $this->builder->buildWhere(new In('id', [10, 20, 30]));

        Assert::same($clause->sql, 'id IN ({p0:UInt64}, {p1:UInt64}, {p2:UInt64})');
        Assert::same($clause->params, ['p0' => 10, 'p1' => 20, 'p2' => 30]);
    }

    public function inWithEmptyValuesMatchesNothing(): void
    {
        $clause = $this->builder->buildWhere(new In('id', []));

        Assert::same($clause->sql, '0');
        Assert::same($clause->params, []);
    }

    public function between(): void
    {
        $clause = $this->builder->buildWhere(new Between('id', 100, 200));

        Assert::same($clause->sql, 'id BETWEEN {p0:UInt64} AND {p1:UInt64}');
        Assert::same($clause->params, ['p0' => 100, 'p1' => 200]);
    }

    public function not(): void
    {
        $clause = $this->builder->buildWhere(new Not(new Equals('status', 'active')));

        Assert::same($clause->sql, 'NOT (status = {p0:String})');
        Assert::same($clause->params, ['p0' => 'active']);
    }

    public function notWithDroppedInnerFilterIsDropped(): void
    {
        $clause = $this->builder->buildWhere(new Not(new Equals('secret', 'x')));

        Assert::true($clause->isEmpty());
    }

    public function andX(): void
    {
        $clause = $this->builder->buildWhere(new AndX(
            new Equals('status', 'active'),
            new GreaterThanOrEqual('id', 10),
        ));

        Assert::same($clause->sql, '(status = {p0:String} AND id >= {p1:UInt64})');
        Assert::same($clause->params, ['p0' => 'active', 'p1' => 10]);
    }

    public function orXWithSameFieldKeepsBothBranches(): void
    {
        $clause = $this->builder->buildWhere(new OrX(
            new Equals('status', 'active'),
            new Equals('status', 'pending'),
        ));

        Assert::same($clause->sql, '(status = {p0:String} OR status = {p1:String})');
        Assert::same($clause->params, ['p0' => 'active', 'p1' => 'pending']);
    }

    public function nestedCompositeKeepsUniqueParamKeys(): void
    {
        $clause = $this->builder->buildWhere(new AndX(
            new Equals('status', 'active'),
            new OrX(
                new GreaterThanOrEqual('id', 1),
                new LessThanOrEqual('id', 100),
            ),
        ));

        Assert::same($clause->sql, '(status = {p0:String} AND (id >= {p1:UInt64} OR id <= {p2:UInt64}))');
        Assert::same($clause->params, ['p0' => 'active', 'p1' => 1, 'p2' => 100]);
    }

    public function compositeSkipsEmptySubFilters(): void
    {
        $clause = $this->builder->buildWhere(new AndX(new Equals('disallowed', 'val')));

        Assert::true($clause->isEmpty());
        Assert::same($clause->params, []);
    }

    public function orderByNullReturnsDefault(): void
    {
        Assert::same($this->builder->buildOrderBy(null), 'id DESC');
    }

    public function orderByNullReturnsEmptyWithoutDefaultSort(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['id']);

        Assert::same($builder->buildOrderBy(null), '');
    }

    public function orderByEmptyCriteriaReturnsDefault(): void
    {
        Assert::same($this->builder->buildOrderBy(Sort::only([])), 'id DESC');
    }

    public function orderByWithCriteria(): void
    {
        $sort = Sort::only(['id', 'name'])->withOrder(['id' => 'desc', 'name' => 'asc']);

        Assert::same($this->builder->buildOrderBy($sort), 'id DESC, name ASC');
    }

    public function orderByDropsDisallowedFields(): void
    {
        $onlyDisallowed = Sort::only(['secret'])->withOrder(['secret' => 'asc']);
        Assert::same($this->builder->buildOrderBy($onlyDisallowed), 'id DESC');

        $mixed = Sort::only(['id', 'secret'])->withOrder(['secret' => 'asc', 'id' => 'desc']);
        Assert::same($this->builder->buildOrderBy($mixed), 'id DESC');
    }

    public function selectSelectsAllByDefault(): void
    {
        $sql = $this->builder->buildSelect(table: 'events', limit: 10, offset: 0);

        Assert::same($sql, 'SELECT * FROM events ORDER BY id DESC LIMIT 10 OFFSET 0');
    }

    public function selectOmitsOrderByWithoutDefaultSort(): void
    {
        $builder = new ClickHouseQueryBuilder(allowedFields: ['id']);

        $sql = $builder->buildSelect(table: 'events', limit: 10, offset: 0);

        Assert::same($sql, 'SELECT * FROM events LIMIT 10 OFFSET 0');
    }

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

        Assert::same($sql, 'SELECT id, name FROM events WHERE status = {p0:String} ORDER BY id ASC LIMIT 50 OFFSET 100');
    }

    public function selectWithNullLimitOmitsLimitClause(): void
    {
        $sql = $this->builder->buildSelect(table: 'events', columns: ['id'], limit: null);

        Assert::same($sql, 'SELECT id FROM events ORDER BY id DESC');
    }

    public function countQuery(): void
    {
        Assert::same($this->builder->buildCount(table: 'events'), 'SELECT count() AS cnt FROM events');
        Assert::same($this->builder->buildCount(table: 'events', where: 'status = {p0:String}'), 'SELECT count() AS cnt FROM events WHERE status = {p0:String}');
    }

    public function distinct(): void
    {
        Assert::same($this->builder->buildDistinct(table: 'events', column: 'status'), 'SELECT DISTINCT status FROM events ORDER BY status');
    }

    public function selectAllowsDbQualifiedTable(): void
    {
        $sql = $this->builder->buildSelect(table: 'analytics.events', limit: 5);

        Assert::string($sql)->startsWith('SELECT * FROM analytics.events');
    }

    public function selectRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events; DROP TABLE users');
    }

    public function selectRejectsMalformedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', columns: ['id', 'name)) --']);
    }

    public function selectRejectsNegativeLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', limit: -1);
    }

    public function selectRejectsNegativeOffset(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildSelect(table: 'events', offset: -10);
    }

    public function distinctRejectsMalformedColumn(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildDistinct(table: 'events', column: 'status FROM events;');
    }

    public function constructorAllowsParametricTypes(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['tags'],
            fieldTypes: ['tags' => 'Array(Nullable(String))'],
        );

        Assert::same($builder->buildWhere(new Equals('tags', 'x'))->sql, 'tags = {p0:Array(Nullable(String))}');
    }

    public function constructorRejectsMalformedType(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id'], fieldTypes: ['id' => 'UInt64} OR 1=1 --']);
    }

    public function constructorRejectsMalformedAllowedField(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id', 'name); DROP TABLE users --']);
    }

    public function rawFilterIsEmittedVerbatimWithParams(): void
    {
        $clause = $this->builder->buildWhere(new ClickHouseRawFilter('toDate(created_at) = {d:Date}', ['d' => '2024-01-01']));

        Assert::same($clause->sql, 'toDate(created_at) = {d:Date}');
        Assert::same($clause->params, ['d' => '2024-01-01']);
    }

    public function buildWhereWithoutFilterIsEmptyWhenNoMandatory(): void
    {
        Assert::true($this->builder->buildWhere()->isEmpty());
    }

    public function mandatoryFilterIsAndCombinedAndBypassesAllowList(): void
    {
        $builder = $this->builder->withMandatoryFilter(new Equals('tenant_id', 5));

        $clause = $builder->buildWhere(new Equals('status', 'active'));

        Assert::same($clause->sql, '(tenant_id = {p0:String}) AND (status = {p1:String})');
        Assert::same($clause->params, ['p0' => 5, 'p1' => 'active']);
    }

    public function mandatoryFilterAppliesWithoutUserFilter(): void
    {
        $clause = $this->builder->withMandatoryFilter(new Equals('tenant_id', 5))->buildWhere();

        Assert::same($clause->sql, 'tenant_id = {p0:String}');
        Assert::same($clause->params, ['p0' => 5]);
    }

    public function mandatoryFiltersChainWithAnd(): void
    {
        $clause = $this->builder
            ->withMandatoryFilter(new Equals('tenant_id', 5))
            ->withMandatoryFilter(new EqualsNull('deleted_at'))
            ->buildWhere();

        Assert::same($clause->sql, '(tenant_id = {p0:String} AND deleted_at IS NULL)');
        Assert::same($clause->params, ['p0' => 5]);
    }

    public function mandatoryFilterStillConstrainsWhenUserFilterDropped(): void
    {
        $clause = $this->builder
            ->withMandatoryFilter(new Equals('tenant_id', 5))
            ->buildWhere(new Equals('secret', 'x'));

        Assert::same($clause->sql, 'tenant_id = {p0:String}');
    }

    public function mandatoryFilterValidatesIdentifier(): void
    {
        $builder = $this->builder->withMandatoryFilter(new Equals('bad); DROP --', 1));

        Expect::exception(InvalidArgumentException::class);

        $builder->buildWhere();
    }

    public function createAndWithDefaultSort(): void
    {
        $builder = ClickHouseQueryBuilder::create(allowedFields: ['id'])->withDefaultSort('id ASC');

        Assert::same($builder->buildOrderBy(null), 'id ASC');
    }

    public function serverTimezoneConvertsDateTimeValue(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['created_at'],
            fieldTypes: ['created_at' => 'DateTime'],
            serverTimezone: 'UTC',
        );

        $moscow = new \DateTimeImmutable('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $clause = $builder->buildWhere(new Equals('created_at', $moscow));

        Assert::same($clause->params, ['p0' => '2024-06-15 12:00:00']);
    }

    public function serverTimezoneNullKeepsObjectTimezone(): void
    {
        $builder = new ClickHouseQueryBuilder(
            allowedFields: ['created_at'],
            fieldTypes: ['created_at' => 'DateTime'],
        );

        $moscow = new \DateTimeImmutable('2024-06-15 15:00:00', new \DateTimeZone('Europe/Moscow'));
        $clause = $builder->buildWhere(new Equals('created_at', $moscow));

        Assert::same($clause->params, ['p0' => '2024-06-15 15:00:00']);
    }

    public function withServerTimezoneReturnsNewInstance(): void
    {
        $original = new ClickHouseQueryBuilder(allowedFields: ['dt'], fieldTypes: ['dt' => 'DateTime']);
        $withTz = $original->withServerTimezone('UTC');

        $moscow = new \DateTimeImmutable('2024-01-01 03:00:00', new \DateTimeZone('Europe/Moscow'));

        Assert::same($original->buildWhere(new Equals('dt', $moscow))->params, ['p0' => '2024-01-01 03:00:00']);
        Assert::same($withTz->buildWhere(new Equals('dt', $moscow))->params, ['p0' => '2024-01-01 00:00:00']);
    }

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

        Assert::same($clause->params, ['p0' => '2024-01-01 02:00:00', 'p1' => '2024-01-01 05:00:00']);
    }

    public function customVisitorIsUsedForSqlGeneration(): void
    {
        $visitor = new FakeClickHouseFilterVisitor(returnValue: ['custom_sql = 1', ['x' => 9]]);

        $builder = ClickHouseQueryBuilder::create(['id'])->withVisitor($visitor);
        $clause = $builder->buildWhere(new Equals('id', 1));

        Assert::same($clause->sql, 'custom_sql = 1');
        Assert::same($clause->params, ['x' => 9]);
    }

    public function buildOrderByKeepsAllowedFieldAfterDisallowed(): void
    {
        $sort = Sort::only(['secret', 'name'])->withOrder(['secret' => 'asc', 'name' => 'asc']);

        Assert::same($this->builder->buildOrderBy($sort), 'name ASC');
    }

    public function buildSelectUsesDefaultLimitAndOffset(): void
    {
        Assert::same(
            $this->builder->buildSelect(table: 'events'),
            'SELECT * FROM events ORDER BY id DESC LIMIT 20 OFFSET 0',
        );
    }

    public function buildSelectAllowsZeroLimit(): void
    {
        Assert::same(
            $this->builder->buildSelect(table: 'events', limit: 0),
            'SELECT * FROM events ORDER BY id DESC LIMIT 0 OFFSET 0',
        );
    }

    public function buildCountRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildCount(table: 'events; DROP TABLE x');
    }

    public function buildDistinctRejectsMalformedTable(): void
    {
        Expect::exception(InvalidArgumentException::class);

        $this->builder->buildDistinct(table: 'events; DROP TABLE x', column: 'status');
    }

    public function constructorRejectsEmptyServerTimezone(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ClickHouseQueryBuilder(allowedFields: ['id'], serverTimezone: '');
    }
}
