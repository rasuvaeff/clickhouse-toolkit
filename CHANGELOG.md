# Changelog

## 1.9.0 — 2026-09-13

### Fixed

- Two `ClickHouseRawFilter`s that reused a parameter name — under one `AndX`/`OrX`,
  or one in the mandatory filter and one in the user filter — merged by name, so
  the SQL kept both `{name:Type}` tokens while only the last value was bound; a
  raw name equal to a builder key (`p0`, …) or to the keyset boundary (`ck0`, …)
  lost the builder's value the same way. Parameters are now isolated at both
  merge points (`ClickHouseSqlFilterVisitor` composites and
  `ClickHouseQueryBuilder::buildWhere()`): the colliding name becomes `name_0`,
  `name_1`, … and its token is rewritten, whichever sibling comes second. A
  custom `ClickHouseFilterVisitor` keeps merging its own composites — only the
  builder-level merge is applied to it. The `ck0`/`ck1` names are no longer
  reserved (#34).

## 1.8.0 — 2026-09-11

- `ClickHouseMigrationRunner::run()` and `status()` now throw a
  `ClickHouseMigrationException` naming the path when `$migrationsPath` is not a
  directory, instead of treating it as an empty set of files. `glob()` returns an
  empty list for a mistyped path exactly as it does for an empty directory, so a
  wrong path — the one setting that cannot be verified locally, where it is
  correct by definition — made a deploy report success with no table created; the
  failure surfaced later, on the first query against a table that was never made.
  Behaviour change: a runner pointed at a directory that does not exist yet now
  fails instead of returning `[]` (#31).
- `clickhouse:migrations:migrate` reports recorded migrations whose file is gone
  from disk. `run()` only walks the files, so it could not see them, and the two
  commands contradicted each other: `status` reported `1 missing` where `migrate`
  reported an up-to-date schema. The command now reads the status once after
  applying, warns naming those records, and reflects them in the summary — while
  still exiting `0`, since applying what is pending is its job and a deleted file
  is not a reason to fail a deploy. `clickhouse:migrations:status` remains the
  gate that exits `1` (#31).
- `ClickHouseDataReader::withFilter()`, `withSort()`, `withLimit()` and
  `withOffset()` carry the `TValue` template argument over to the returned
  instance, and `readOne()` is annotated `TValue|null`. Psalm used to widen the
  reader back to its `array|object` bound behind any `withX()`, so every
  `read()`/`readOne()` after one lost its type and consumers had to re-annotate
  at each call site — an annotation that could not catch a mapper returning a
  different object type, both satisfying the bound. Docblocks only; no runtime
  or signature change (#32).

## 1.7.0 — 2026-09-10

- `ClickHouseMigrationRunner` accepts `$migrationsTable`: the bookkeeping table
  recording applied migrations is no longer hardcoded to `_migrations`. Adopting
  the runner where a table of that name already exists with a different schema
  used to fail inside the first read — before any migration file was read, so
  the repair could not itself ship as a migration — and two applications sharing
  one ClickHouse database collided on it. The name is interpolated into SQL, so
  it is validated as a plain identifier; a db-qualified form is refused. Existing
  installations keep `_migrations` (#29).

## 1.6.0 — 2026-07-25

- `ClickHouseMigrationRunner` accepts `$placeholders`: `{{key}}` tokens in a
  migration file are replaced before the file is hashed and executed, so a
  package can ship DDL whose table names the application configures instead of
  hard-coding them. An unresolved `{{…}}` throws a `ClickHouseMigrationException`
  naming the file and the token, rather than sending it to the server.
- The checksum covers the **resolved** SQL, not the raw file. That is what makes
  the feature safe to adopt: a package switching its shipped DDL from a literal
  name to a placeholder is invisible to installations on the default value —
  their resolved text is byte-identical to what they applied. Changing a value
  after a migration has been applied is still reported as a divergence.
- `status()` resolves placeholders identically to `run()`, so the two cannot
  disagree about whether a file has diverged.

## 1.5.1 — 2026-07-25

- Reject trailing newlines in validated values: `Identifier` now anchors patterns
  with `\z` instead of `$` (PCRE `$` matches before a trailing `\n`, which let
  `"<identifier>\n"` slip through identifier/type validation).

## 1.5.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-clickhouse-toolkit/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev dependency to `^2.6`.
- Make property-test generator methods `public static` (private ones are removed
  by rector's `RemoveUnusedPrivateMethodRector` — they are only called via reflection).

## 1.4.0 — 2026-07-08

- Added `ClickHouseKeysetReader` — bounded-memory streaming of large result sets via keyset (seek) pagination (`WHERE key > last ORDER BY key LIMIT pageSize`), yielding rows through a generator. Supports composite keys (tuple comparison), a base filter AND-combined with the boundary, and preserves the query builder's mandatory filter and allow-list on every page.

## 1.3.0 — 2026-07-08

- Added an optional `settings` argument to `ClickHouseBatchWriter` — ClickHouse query settings (e.g. `['async_insert' => 1, 'wait_for_async_insert' => 0]`) applied to every batch `INSERT`. Backward compatible: defaults to no settings.

## 1.2.2 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

## 1.2.1 — 2026-06-26

- Migrate tests from PHPUnit to Testo (testo/testo + testo/bridge-infection + testo/bench).

## 1.2.0 — 2026-06-14

- Added `ClickHouseMigrationGenerator` for creating new migration files with auto-incremented numeric prefixes (`NNN_description.sql`).
- Added `ClickHouseMigrationRunner::status()` returning a list of `ClickHouseMigrationStatus` records classifying every migration as `Applied`, `Pending`, `Missing`, or `Diverged`.
- Added `ClickHouseMigrationState` enum and `ClickHouseMigrationStatus` value object.
- Added three Symfony Console commands: `clickhouse:migrations:generate`, `clickhouse:migrations:status`, and `clickhouse:migrations:migrate` (in the new `Rasuvaeff\ClickHouseToolkit\Command` namespace).
- Added `symfony/console` (^7.2) as a runtime dependency.

## 1.1.0 — 2026-06-07

- `ClickHouseQueryBuilder`: default `$defaultSort` changed from `'id DESC'` to `''` — no implicit `ORDER BY` is added unless a sort is provided.
- `ClickHouseSqlFilterVisitor`: fix `LIKE`/`ILIKE` filter handling.

## 1.0.0

Initial release.
