# Definitions

Definitions are a shared lookup system for label/value pairs used across forms, filters, and tags.

## Storage and Management

Definitions are database-backed records. There is no admin CRUD UI for them — they are managed via seeders and migrations.

- Migration: `database/migrations/2026_03_12_001950_create_definitions_table.php`
- Seeder: `database/seeders/_02_DefinitionSeeder.php`

## Database Columns

The `definitions` table has the following columns:

| Column | Type | Notes |
|---|---|---|
| `key` | string | indexed; groups related definitions |
| `value` | string | the stored value |
| `label` | string | human-readable display label |
| `explanation` | text | nullable; additional description |
| `severity` | string | nullable; e.g. `info`, `warning`, `danger` |
| `icon` | string | nullable; icon identifier |
| `is_active` | boolean | defaults to `true` |
| `order` | integer | defaults to `0`; controls sort order |
| `visibility` | boolean | defaults to `true` |
| `lang` | string(35) | defaults to `en`; supports i18n |

A unique constraint is enforced on `(key, value, lang)`.

> **Why `lang` is 35 characters, not 255.** The composite unique index over three default 255-character `utf8mb4` columns measures 3060 bytes against InnoDB's 3072-byte key limit — one character of headroom on any one column away from breaking outright. The `2026_08_31_120000_narrow_definitions_unique_index_columns` migration narrows `lang` to 35, the widest locale value the kit accepts anywhere (`content_languages.code`), leaving ~892 bytes of headroom; `key` and `value` keep their published 255. The migration measures every existing row first — soft-deleted ones included — and **refuses, leaving the schema unchanged, if a single row would be truncated**. Both directions end by asserting the unique index exists (name, uniqueness and the exact `{key, value, lang}` column set), so a table that arrives with a drifted or missing index gets the real one rebuilt instead of being recorded as migrated without its guarantee. See [UPGRADE.md](UPGRADE.md).

## Access Points

- web service route: `/definitions`
- API route: `/api/v1/definitions`
- frontend composable: `useDefinition()`

## Frontend Benefits

Definitions make it easy to:

- populate select options
- render status tags consistently
- share the same meaning across pages and modules

## Common Methods

From `useDefinition()`:

- `load(keys)`
- `loadAll()`
- `list(key)`
- `options(key)`
- `find(key, value)`
- `clearCache()`

