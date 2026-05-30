<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\ClickHouseToolkit\Identifier;

#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    #[Test]
    #[DataProvider('validIdentifiers')]
    public function assertAcceptsValid(string $identifier): void
    {
        $this->expectNotToPerformAssertions();

        Identifier::assert($identifier);
    }

    #[Test]
    #[DataProvider('invalidIdentifiers')]
    public function assertRejectsInvalid(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Identifier::assert($identifier);
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

    #[Test]
    #[DataProvider('validPlainIdentifiers')]
    public function assertPlainAcceptsValid(string $identifier): void
    {
        $this->expectNotToPerformAssertions();

        Identifier::assertPlain($identifier);
    }

    #[Test]
    #[DataProvider('invalidPlainIdentifiers')]
    public function assertPlainRejectsInvalid(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Identifier::assertPlain($identifier);
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

    #[Test]
    #[DataProvider('validTypes')]
    public function assertTypeAcceptsValid(string $type): void
    {
        $this->expectNotToPerformAssertions();

        Identifier::assertType($type);
    }

    #[Test]
    #[DataProvider('invalidTypes')]
    public function assertTypeRejectsInvalid(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Identifier::assertType($type);
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
