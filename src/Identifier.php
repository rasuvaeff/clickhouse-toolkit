<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Shared validation for SQL identifiers and ClickHouse type tokens. Identifiers
 * are not escaped anywhere in the toolkit, so callers must pass trusted, plain
 * names; this guards against accidental injection from misconfigured input.
 *
 * @internal
 */
final class Identifier
{
    /**
     * Validates an unquoted SQL identifier (optionally db-qualified: `db.table`).
     *
     * @throws \InvalidArgumentException
     */
    public static function assert(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier: "%s".', $identifier));
        }
    }

    /**
     * Validates a plain, unqualified SQL identifier — no dot allowed. Use for
     * positions where a `db.table` form is invalid (e.g. INSERT column lists).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertPlain(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid plain SQL identifier: "%s".', $identifier));
        }
    }

    /**
     * Validates a ClickHouse type token embedded in a `{name:Type}` placeholder.
     * Allows nested parametric types (`Array(Nullable(String))`, `Decimal(10, 2)`)
     * but forbids characters that could break out of the placeholder.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertType(string $type): void
    {
        if (preg_match('/^[A-Za-z0-9_(), ]+$/', $type) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid ClickHouse type: "%s".', $type));
        }
    }
}
