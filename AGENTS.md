# AGENTS.md — clickhouse-toolkit

Guidance for AI agents working on this package. Read before changing code.

## What this is

A small, framework-agnostic ClickHouse toolkit (PHP 8.3+): client factory,
migration runner, `yiisoft/data` → parameterized SQL query builder, immutable
`DataReader`, and a batched writer. Built on `simpod/clickhouse-client`. Depends
only on PSR interfaces for HTTP (no concrete client).

Public API is in `src/` (one class per file, `Rasuvaeff\ClickHouseToolkit\`).
`Identifier` is `@internal`; everything else user-facing is marked `@api`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build` AND the integration suite against a real ClickHouse. "Should
   work" / "probably" do not count. Run the commands below and paste real output.
2. **No suppressions.** `@psalm-suppress`, `@phpstan-ignore`, baseline entries are
   not allowed. Fix the root cause (narrow types, restructure). Psalm runs at
   `errorLevel=1`.
3. **Don't widen the SQL-injection surface.** User-supplied values must always be
   bound parameters (`{pN:Type}`); identifiers (table/column/field/type) must go
   through `Identifier::assert()` / `Identifier::assertType()` or the allow-list.
4. **Preserve the public contract.** Pre-1.0 but treat signatures of `@api`
   classes as stable unless the task says otherwise; update README + tests with
   any change.

## Commands

No PHP/Composer on the host — everything runs in Docker via the `composer:2`
image (which bundles PHP). Run from the package root.

```bash
# Full gate: validate + normalize + require-checker + cs + psalm + phpunit
docker run --rm -v "$PWD":/app -w /app composer:2 composer build

# Auto-fix code style first if cs fails
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix

# Single tool
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library). The `git ... dubious ownership` lines
from cs/normalize are harmless noise.
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Integration tests (real ClickHouse)

`tests/Integration/` is skipped unless `CLICKHOUSE_HOST` is set. CI pins
`clickhouse/clickhouse-server:24.8` — verify locally against the SAME version,
because some SQL (`DateTime64`/`now64`, `uniqExact`, parametric binds) only fails
on a live server, not in unit string-comparison tests.

```bash
docker rm -f ch-test 2>/dev/null
docker run -d --name ch-test -p 8123:8123 -e CLICKHOUSE_PASSWORD=ch_test \
  clickhouse/clickhouse-server:24.8
# wait until ready
for i in $(seq 1 40); do curl -fs -H 'X-ClickHouse-User: default' \
  -H 'X-ClickHouse-Key: ch_test' --data-binary 'SELECT 1' \
  http://127.0.0.1:8123/ | grep -q '^1$' && break; sleep 1; done
# run the suite (host network so the container reaches 127.0.0.1:8123)
docker run --rm --network host -v "$PWD":/app -w /app \
  -e CLICKHOUSE_HOST=127.0.0.1 -e CLICKHOUSE_PASSWORD=ch_test \
  composer:2 vendor/bin/phpunit tests/Integration
docker rm -f ch-test
```

## Code style & invariants

- `declare(strict_types=1);` in every file. `final readonly class` by default
  (`ClickHouseDataReader` is `final` only — it is immutable via `clone` in
  `with*` methods, so it cannot be `readonly`).
- `@api` on every public class/interface (psalm flags them unused otherwise).
  `#[\Override]` on interface/parent implementations.
- Explicit return/param types; named arguments at call sites are the norm.
- Comments in code: Russian is allowed (project preference); keep them only where
  non-obvious.
- `ClickHouseQueryBuilder`: parameter keys are unique per occurrence (`p0`, `p1`,
  …) — never per field; this is a correctness invariant (repeated fields in
  OR/AND/IN must not collide). Disallowed/unknown *user* filters are **silently
  dropped** (widening) — enforce ACL/tenant constraints with
  `withMandatoryFilter()` (always applied, AND-combined, bypasses the allow-list),
  never via the user filter. `ClickHouseRawFilter` emits raw SQL (trusted; values
  via `{name:Type}` params that must not clash with the `pN` keys).
- Migration runner: `_migrations` is `ReplacingMergeTree(applied_at) ORDER BY
  name` with microsecond `DateTime64(6)`; reads via `argMax` + `uniqExact`
  conflict detection. Tamper-evident (checksum mismatch → exception). One SQL
  statement per file; no naive `;` splitting.

- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
