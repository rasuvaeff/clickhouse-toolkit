<?php

declare(strict_types=1);

namespace Rasuvaeff\ClickHouseToolkit;

/**
 * Merges the parameters of a WHERE fragment into those already collected for
 * the query. A name the query has taken — by a sibling raw filter, by a
 * builder-owned `pN` key or by the mandatory filter — is renamed to
 * `name_0`, `name_1`, … and its `{name:Type}` token in the fragment is
 * rewritten to match, so that both values stay bound instead of the last one
 * silently winning (#34).
 *
 * @internal
 */
final readonly class PlaceholderRemap
{
    /**
     * @param array<string, mixed> $params Parameters collected so far.
     * @param array<string, mixed> $fragmentParams Parameters of the fragment being appended.
     * @return array{0: string, 1: array<string, mixed>} The rewritten fragment and the merged parameters.
     */
    public static function merge(array $params, string $fragmentSql, array $fragmentParams): array
    {
        /** @var array<string, string> $renames */
        $renames = [];
        /** @var list<string> $keys */
        $keys = [];

        foreach (array_keys($fragmentParams) as $name) {
            $unique = $name;
            $suffix = 0;
            while (array_key_exists($unique, $params) || in_array($unique, $keys, strict: true)) {
                $unique = sprintf('%s_%d', $name, $suffix);
                $suffix++;
            }

            $keys[] = $unique;
            if ($unique !== $name) {
                $renames[$name] = $unique;
            }
        }

        $params += array_combine($keys, $fragmentParams);

        // One pass over the original tokens: a rename never feeds another one
        // (`v` → `v_0` while the fragment's own `v_0` becomes `v_0_0`). The
        // server accepts `{ v :UInt64}` as well, so the whitespace is kept.
        $rewritten = preg_replace_callback(
            '/(\{\s*)(\w+)(\s*:)/',
            static fn(array $match): string => isset($renames[$match[2]])
                ? $match[1] . $renames[$match[2]] . $match[3]
                : $match[0],
            $fragmentSql,
        );

        return [$rewritten ?? $fragmentSql, $params];
    }
}
