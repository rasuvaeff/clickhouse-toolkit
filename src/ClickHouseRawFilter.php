<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

use Yiisoft\Data\Reader\FilterInterface;

/**
 * A raw SQL fragment as a {@see FilterInterface}, for expressions the typed
 * filters can't express (e.g. `toDate(created_at) = today()`).
 *
 * SECURITY: `$sql` is emitted verbatim — never build it from untrusted input.
 * Pass user values through `$params` as bound ClickHouse parameters.
 *
 * Placeholders use the ClickHouse `{name:Type}` syntax. Param names MUST NOT
 * clash with the builder's auto-generated keys (`p0`, `p1`, …) nor with each
 * other when combined with other filters; prefer a distinctive prefix.
 *
 * @api
 */
final readonly class ClickHouseRawFilter implements FilterInterface
{
    /**
     * @param array<string, mixed> $params Bound parameters for placeholders in $sql.
     */
    public function __construct(
        public string $sql,
        public array $params = [],
    ) {}
}
