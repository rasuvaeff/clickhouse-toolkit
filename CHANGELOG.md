# Changelog

## 1.1.0 — 2026-06-07

- `ClickHouseQueryBuilder`: default `$defaultSort` changed from `'id DESC'` to `''` — no implicit `ORDER BY` is added unless a sort is provided.
- `ClickHouseSqlFilterVisitor`: fix `LIKE`/`ILIKE` filter handling.

## 1.0.0

Initial release.
