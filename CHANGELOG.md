# Changelog

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
