<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\Scalar;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Scalar::class)]
final class ScalarTest
{
    public function rendersToString(): void
    {
        Assert::same(
            Scalar::toString(column: 'occurred_at', alias: 'occurred_at')->toSql(),
            'toString(occurred_at) AS occurred_at',
        );
    }

    public function anAliasIsOptional(): void
    {
        Assert::same(Scalar::toString(column: 'occurred_at')->toSql(), 'toString(occurred_at)');
    }

    #[DataProvider('refusedProvider')]
    public function malformedIdentifiersAreRefused(string $column, ?string $alias): void
    {
        Expect::exception(InvalidArgumentException::class);

        Scalar::toString(column: $column, alias: $alias);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'column with an expression' => ['toString(x)', 'a'];
        yield 'quoted column' => ['`x`', 'a'];
        yield 'empty column' => ['', 'a'];
        yield 'alias with a space' => ['x', 'bad alias'];
    }
}
