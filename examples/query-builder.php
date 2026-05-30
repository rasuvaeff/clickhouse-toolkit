<?php

declare(strict_types=1);

use Rasuvaeff\ClickHouseToolkit\ClickHouseDataType as T;
use Rasuvaeff\ClickHouseToolkit\ClickHouseQueryBuilder;
use Yiisoft\Data\Reader\Filter\All;
use Yiisoft\Data\Reader\Filter\AndX;
use Yiisoft\Data\Reader\Filter\Between;
use Yiisoft\Data\Reader\Filter\Equals;
use Yiisoft\Data\Reader\Filter\GreaterThanOrEqual;
use Yiisoft\Data\Reader\Filter\In;
use Yiisoft\Data\Reader\Filter\LessThanOrEqual;
use Yiisoft\Data\Reader\Filter\Like;
use Yiisoft\Data\Reader\Filter\Not;
use Yiisoft\Data\Reader\Filter\OrX;
use Yiisoft\Data\Reader\FilterInterface;
use Yiisoft\Data\Reader\Sort;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Offline: this script only prints generated SQL — no server needed.

$qb = new ClickHouseQueryBuilder(
    allowedFields: ['id', 'status', 'user_id', 'name', 'created_at'],
    fieldTypes: [
        'id' => T::UInt64,
        'user_id' => T::UInt32,
        'created_at' => T::DateTime,
    ],
    defaultSort: 'id DESC',
);

$dump = static function (string $title, FilterInterface $filter) use ($qb): void {
    $clause = $qb->buildWhere($filter);
    printf("%-28s WHERE: %-55s PARAMS: %s\n", $title, $clause->isEmpty() ? '(empty)' : $clause->sql, json_encode($clause->params));
};

echo "== WHERE clauses ==\n";
$dump('All', new All());
$dump('Equals', new Equals('status', 'active'));
$dump('Equals (disallowed field)', new Equals('secret', 'x'));
$dump('GreaterThanOrEqual', new GreaterThanOrEqual('user_id', 1000));
$dump('LessThanOrEqual', new LessThanOrEqual('id', 500));
$dump('In', new In('id', [1, 2, 3]));
$dump('In (empty -> match none)', new In('id', []));
$dump('Between', new Between('created_at', '2024-01-01 00:00:00', '2024-12-31 23:59:59'));
$dump('Like (escaped)', new Like('name', "O'Brien_50%"));
$dump('Not', new Not(new Equals('status', 'active')));
$dump('AndX', new AndX(new Equals('status', 'active'), new GreaterThanOrEqual('user_id', 1)));
$dump('OrX (same field)', new OrX(new Equals('status', 'active'), new Equals('status', 'pending')));
$dump('Nested AndX/OrX', new AndX(
    new Equals('status', 'active'),
    new OrX(new GreaterThanOrEqual('id', 1), new LessThanOrEqual('id', 100)),
));

echo "\n== ORDER BY ==\n";
printf("default            : %s\n", $qb->buildOrderBy(null));
printf("single desc        : %s\n", $qb->buildOrderBy(Sort::only(['created_at'])->withOrder(['created_at' => 'desc'])));
printf("multi              : %s\n", $qb->buildOrderBy(Sort::only(['status', 'id'])->withOrder(['status' => 'asc', 'id' => 'desc'])));

echo "\n== SELECT / COUNT / DISTINCT ==\n";
$where = $qb->buildWhere(new Equals('status', 'active'));
printf("select *      : %s\n", $qb->buildSelect(table: 'events', where: $where->sql, limit: 20, offset: 40));
printf("select cols   : %s\n", $qb->buildSelect(table: 'events', columns: ['id', 'status'], where: $where->sql, orderBy: 'id DESC', limit: 20, offset: 40));
printf("count         : %s\n", $qb->buildCount(table: 'events', where: $where->sql));
printf("distinct      : %s\n", $qb->buildDistinct(table: 'events', column: 'status'));
