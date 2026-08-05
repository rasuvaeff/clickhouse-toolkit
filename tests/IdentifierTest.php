<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit\Tests;

use InvalidArgumentException;
use Rasuvaeff\ClickHouseToolkit\Identifier;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
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
        yield 'trailing newline' => ["events\n"];
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
        yield 'trailing newline' => ["events\n"];
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
        yield 'trailing newline' => ["String\n"];
    }

    #[Property(runs: 500)]
    public function acceptsExactlyValidIdentifiers(string $name): void
    {
        $valid = (bool) preg_match('/^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?\z/', $name);

        try {
            Identifier::assert(identifier: $name);
            Assert::true($valid);
        } catch (InvalidArgumentException) {
            Assert::false($valid);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyValidIdentifiersGenerators(): array
    {
        return ['name' => Gen::stringFrom(alphabet: 'abcABC012_.', minLength: 0, maxLength: 40)];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function acceptsExactlyValidIdentifiersExamples(): iterable
    {
        foreach ([...self::validIdentifiers(), ...self::invalidIdentifiers()] as $case) {
            yield $case;
        }
    }

    #[Property(runs: 500)]
    public function acceptsExactlyValidPlainIdentifiers(string $name): void
    {
        $valid = (bool) preg_match('/^[A-Za-z_]\w*\z/', $name);

        try {
            Identifier::assertPlain(identifier: $name);
            Assert::true($valid);
        } catch (InvalidArgumentException) {
            Assert::false($valid);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyValidPlainIdentifiersGenerators(): array
    {
        return ['name' => Gen::stringFrom(alphabet: 'abcABC012_.', minLength: 0, maxLength: 40)];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function acceptsExactlyValidPlainIdentifiersExamples(): iterable
    {
        foreach ([...self::validPlainIdentifiers(), ...self::invalidPlainIdentifiers()] as $case) {
            yield $case;
        }
    }

    #[Property(runs: 500)]
    public function acceptsExactlyValidTypes(string $type): void
    {
        $valid = (bool) preg_match('/^[A-Za-z0-9_(), ]+\z/', $type);

        try {
            Identifier::assertType(type: $type);
            Assert::true($valid);
        } catch (InvalidArgumentException) {
            Assert::false($valid);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsExactlyValidTypesGenerators(): array
    {
        return ['type' => Gen::stringFrom(alphabet: 'AaBbCc019_(), ', minLength: 0, maxLength: 40)];
    }

    /**
     * @return iterable<array{string}>
     */
    public static function acceptsExactlyValidTypesExamples(): iterable
    {
        foreach ([...self::validTypes(), ...self::invalidTypes()] as $case) {
            yield $case;
        }
    }
}
