<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\Identifier;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Identifier::class)]
final class IdentifierTest
{
    #[DataProvider('validIdentifiers')]
    public function assertAcceptsValid(string $identifier): void
    {
        Identifier::assert(identifier: $identifier);
        Assert::true(true);
    }

    #[DataProvider('invalidIdentifiers')]
    public function assertRejectsInvalid(string $identifier): void
    {
        Expect::exception(InvalidArgumentException::class);

        Identifier::assert(identifier: $identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'simple' => ['events'];
        yield 'underscore' => ['_migrations'];
        yield 'db qualified' => ['my_db.events'];
        yield 'numeric suffix' => ['col1'];
        yield 'single letter' => ['x'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'hyphen' => ['my-table'];
        yield 'space' => ['my table'];
        yield 'semicolon' => ['events; DROP TABLE'];
        yield 'dot only' => ['.events'];
        yield 'trailing dot' => ['db.'];
        yield 'triple dot' => ['a.b.c'];
        yield 'expression' => ['toDate(x)'];
        yield 'star' => ['*'];
        yield 'quoted' => ['`events`'];
        yield 'bracket' => ['events]'];
    }

    #[DataProvider('validPlainIdentifiers')]
    public function assertPlainAcceptsValid(string $identifier): void
    {
        Identifier::assertPlain(identifier: $identifier);
        Assert::true(true);
    }

    #[DataProvider('invalidPlainIdentifiers')]
    public function assertPlainRejectsInvalid(string $identifier): void
    {
        Expect::exception(InvalidArgumentException::class);

        Identifier::assertPlain(identifier: $identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPlainIdentifiers(): iterable
    {
        yield 'simple' => ['events'];
        yield 'underscore' => ['_migrations'];
        yield 'numeric suffix' => ['col1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPlainIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'dot qualified' => ['db.events'];
        yield 'hyphen' => ['my-col'];
        yield 'space' => ['my col'];
        yield 'starts with digit' => ['1col'];
        yield 'expression' => ['now()'];
    }

    #[DataProvider('validTypes')]
    public function assertTypeAcceptsValid(string $type): void
    {
        Identifier::assertType(type: $type);
        Assert::true(true);
    }

    #[DataProvider('invalidTypes')]
    public function assertTypeRejectsInvalid(string $type): void
    {
        Expect::exception(InvalidArgumentException::class);

        Identifier::assertType(type: $type);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validTypes(): iterable
    {
        yield 'UInt64' => ['UInt64'];
        yield 'String' => ['String'];
        yield 'DateTime' => ['DateTime'];
        yield 'Nullable(String)' => ['Nullable(String)'];
        yield 'Array(Nullable(String))' => ['Array(Nullable(String))'];
        yield 'Decimal(10, 2)' => ['Decimal(10, 2)'];
        yield 'Map(String, UInt64)' => ['Map(String, UInt64)'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'curly brace' => ['String}'];
        yield 'single quote' => ["String'"];
        yield 'backslash' => ['String\\'];
        yield 'semicolon' => ['String; DROP'];
    }
}
