<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use Rasuvaeff\ClickHouseToolkit\PlaceholderRemap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(PlaceholderRemap::class)]
final class PlaceholderRemapTest
{
    public function keepsFragmentVerbatimWithoutCollision(): void
    {
        [$sql, $params] = PlaceholderRemap::merge(['a' => 1], 'id = {b:UInt64}', ['b' => 2]);

        Assert::same($sql, 'id = {b:UInt64}');
        Assert::same($params, ['a' => 1, 'b' => 2]);
    }

    public function keepsFragmentVerbatimWhenNothingIsCollectedYet(): void
    {
        [$sql, $params] = PlaceholderRemap::merge([], 'id = {v:UInt64}', ['v' => 2]);

        Assert::same($sql, 'id = {v:UInt64}');
        Assert::same($params, ['v' => 2]);
    }

    #[DataProvider('collisionProvider')]
    public function renamesCollidingNameAndRewritesItsToken(string $fragment, string $expected): void
    {
        [$sql, $params] = PlaceholderRemap::merge(['v' => 1], $fragment, ['v' => 5, 'v_max' => 9]);

        Assert::same($sql, $expected);
        Assert::same($params, ['v' => 1, 'v_0' => 5, 'v_max' => 9]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function collisionProvider(): iterable
    {
        yield 'prefixed sibling is a different token' => [
            'id BETWEEN {v:UInt64} AND {v_max:UInt64}',
            'id BETWEEN {v_0:UInt64} AND {v_max:UInt64}',
        ];
        yield 'prefixed sibling first' => [
            'id <= {v_max:UInt64} AND id >= {v:UInt64}',
            'id <= {v_max:UInt64} AND id >= {v_0:UInt64}',
        ];
        yield 'parametric type survives the rename' => [
            'price > {v:Decimal(10, 2)} AND tag = {v_max:Nullable(String)}',
            'price > {v_0:Decimal(10, 2)} AND tag = {v_max:Nullable(String)}',
        ];
        yield 'whitespace inside the token is kept' => [
            'id = { v : UInt64 } AND id < {v_max:UInt64}',
            'id = { v_0 : UInt64 } AND id < {v_max:UInt64}',
        ];
        yield 'same token used twice' => [
            'id = {v:UInt64} OR parent_id = {v:UInt64} OR id = {v_max:UInt64}',
            'id = {v_0:UInt64} OR parent_id = {v_0:UInt64} OR id = {v_max:UInt64}',
        ];
    }

    public function renamesTwiceCollidingNamesInOnePass(): void
    {
        [$sql, $params] = PlaceholderRemap::merge(
            ['v' => 1],
            'id = {v:UInt64} OR id = {v_0:UInt64}',
            ['v' => 2, 'v_0' => 3],
        );

        Assert::same($sql, 'id = {v_0:UInt64} OR id = {v_0_0:UInt64}');
        Assert::same($params, ['v' => 1, 'v_0' => 2, 'v_0_0' => 3]);
    }

    public function picksTheNextFreeSuffixWhenTheRenamedNameIsTakenToo(): void
    {
        [$sql, $params] = PlaceholderRemap::merge(['v' => 1, 'v_0' => 2], 'id = {v:UInt64}', ['v' => 3]);

        Assert::same($sql, 'id = {v_1:UInt64}');
        Assert::same($params, ['v' => 1, 'v_0' => 2, 'v_1' => 3]);
    }

    public function leavesTokensOfOtherNamesAlone(): void
    {
        [$sql, $params] = PlaceholderRemap::merge(['v' => 1], 'id = {v:UInt64} AND {flag:Bool}', ['v' => 2]);

        Assert::same($sql, 'id = {v_0:UInt64} AND {flag:Bool}');
        Assert::same($params, ['v' => 1, 'v_0' => 2]);
    }
}
