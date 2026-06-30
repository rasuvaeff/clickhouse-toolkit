# Changelog

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
