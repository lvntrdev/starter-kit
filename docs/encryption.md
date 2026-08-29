# Data Encryption

The starter kit encrypts a small set of sensitive database values. This document explains which key protects them, how that key relates to `APP_KEY`, and the exact commands to run when adopting the dedicated key or rotating it.

## What is encrypted, and with which key

| Data | Where | Key used |
| --- | --- | --- |
| Sensitive settings values (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) | `settings.value` | `DataCrypt` (dedicated key) |
| Two-factor secret and recovery codes | `users.two_factor_secret`, `users.two_factor_recovery_codes` | `DataCrypt` (dedicated key) |
| Sessions, signed URLs, cookies, and any app code still calling the `Crypt` facade | — | `APP_KEY` (unchanged) |

`DataCrypt` (`Lvntr\StarterKit\Support\Encryption\DataCrypt`) is a facade with the same API as Laravel's `Crypt` facade (`encryptString()`, `decryptString()`, `encrypt()`, `decrypt()`). The only difference is which key backs it.

## `APP_KEY` vs `DATA_ENCRYPTION_KEY`

These are two independent keys with two independent lifecycles:

- **`APP_KEY`** — Laravel's own key. Backs sessions, cookies, signed URLs, and the `Crypt` facade. `php artisan key:generate` regenerates it.
- **`DATA_ENCRYPTION_KEY`** — a dedicated key for the settings and 2FA data listed above. It has its own `.env` variable and its own commands (`encryption:key`, `encryption:rekey`, `encryption:health`). **`php artisan key:generate` never reads or writes `DATA_ENCRYPTION_KEY` or `DATA_ENCRYPTION_PREVIOUS_KEYS` — it touches only `APP_KEY`.**

This split exists because `key:generate` used to silently break every encrypted setting and every user's 2FA secret the moment it ran (`Crypt`/Fortify's default encrypter is bound to `APP_KEY`, and `SettingService` swallows the resulting `DecryptException` and returns `null`, so the failure was invisible). A dedicated key removes that coupling.

## Key-resolution contract

This table is the whole safety property. It is implemented by `DataEncrypterFactory` and enforced by `DataCrypt`.

| State | Primary key (writes) | Previous-key chain (reads) |
| --- | --- | --- |
| `DATA_ENCRYPTION_KEY` empty (an install that has not adopted the dedicated key yet) | `APP_KEY` | `DATA_ENCRYPTION_PREVIOUS_KEYS` (if any) |
| `DATA_ENCRYPTION_KEY` set | `DATA_ENCRYPTION_KEY` | `DATA_ENCRYPTION_PREVIOUS_KEYS`, then **`APP_KEY` last** |

- If `APP_KEY` is not the primary key, it is *always* appended to the end of the read chain — anything written before adoption stays readable without running any command.
- The chain is tried in order, deduplicated, with empty entries dropped.
- A malformed key in the chain raises a `RuntimeException` rather than being skipped silently. A silent skip would make a value look unreadable mid-rotation and tempt an operator into clearing `DATA_ENCRYPTION_PREVIOUS_KEYS` — which is permanent data loss.

**Doing nothing is a valid choice.** An app that never runs `encryption:key` keeps working exactly as it does today: `DATA_ENCRYPTION_KEY` stays empty, the primary key stays `APP_KEY`, and behavior is bit-for-bit unchanged. Nothing about `composer update`/`sk:update` forces adoption.

## The three commands

| Command | Purpose | Writes to disk? |
| --- | --- | --- |
| `php artisan encryption:key` | Generate a new `DATA_ENCRYPTION_KEY` and preserve the key it replaces in `DATA_ENCRYPTION_PREVIOUS_KEYS` | Yes — `.env` |
| `php artisan encryption:rekey` | Re-encrypt every settings/2FA row onto the current primary key | Yes — the database (not `.env`) |
| `php artisan encryption:health` | Report, per row, which key it needs and whether `DATA_ENCRYPTION_PREVIOUS_KEYS` is safe to clear | No — read-only |

### `encryption:key`

```bash
php artisan encryption:key
```

- Resolves the current primary key from `.env` (not from cached config), generates a new random key for the configured cipher in memory, writes `DATA_ENCRYPTION_PREVIOUS_KEYS` with the old primary prepended, and only then writes the new `DATA_ENCRYPTION_KEY`. That order is deliberate: a crash between the two writes leaves the old key still primary and redundantly listed — never the reverse, which would orphan every encrypted row.
- `APP_KEY` is never read, modified, or re-emitted by this command, under any option.
- `--show` prints a freshly generated key to stdout and writes nothing. Use it to inspect what a key looks like without touching `.env`.
- `--force` is required to run in an environment that looks like production, because rotating the key here makes every encrypted value unreadable until `encryption:rekey` completes. Re-run with `--force` only once you have a database backup and a maintenance window.
- The new key is written to `.env` but never printed. The key it replaces is never printed or logged either — only its source variable name appears in output.

### `encryption:rekey`

```bash
php artisan encryption:rekey
php artisan encryption:rekey --dry-run
php artisan encryption:rekey --only=settings
php artisan encryption:rekey --only=two-factor
php artisan encryption:rekey --chunk=200
```

- Re-encrypts every settings and 2FA row that decrypts with a non-primary key onto the current primary key. A row that no configured key can read is left untouched and reported — it is never deleted or blanked.
- `--dry-run` performs every decrypt attempt and prints the identical summary without writing a single byte.
- `--only=settings` or `--only=two-factor` (comma-separated to combine) limits the run to one surface.
- `--chunk=<n>` (default 200, max 2000) controls how many rows are read, locked, and rewritten per round trip.
- This command belongs in a **maintenance window**. It re-reads and locks each chunk under a transaction so a concurrent write is not clobbered by a stale rewrite, but a busy production database under a large rekey should not run this outside a planned window.
- No `updated_at` is bumped by a rekey — it is a storage-format change, not a business change.

### `encryption:health`

```bash
php artisan encryption:health
php artisan encryption:health --json
```

Read-only — takes no lock, opens no transaction, safe to run against a live database.

Verdicts (exit code is the machine-readable half):

| Verdict | Exit code | Meaning |
| --- | --- | --- |
| `safe-to-clear` | 0 | Every scanned value reads with the primary key alone; every surface was fully scanned. `DATA_ENCRYPTION_PREVIOUS_KEYS` can be cleared. |
| `rekey-required` | 1 | At least one value needs a non-primary key. Nothing is lost yet, but clearing the previous-key list now would lose it. Run `encryption:rekey`. |
| `incomplete` | 1 | A surface could not be fully scanned, so "safe" cannot be asserted. |
| `unreadable` | 2 | A value exists that no configured key can read. The key that wrote it is missing from `.env` and must be added back — never cleared away. |
| `key-error` | 2 | The key chain itself does not resolve; nothing could be attributed. |

The verdict only ever downgrades, never upgrades — a false "safe to clear" is the one output of this command that destroys data.

## Adopting the dedicated key on an existing install

An install that has never run `encryption:key` is not required to. If you choose to adopt the dedicated key, run these four steps in order:

```bash
php artisan encryption:key
php artisan encryption:rekey
php artisan encryption:health
```

Then, only after `encryption:health` reports `safe-to-clear`:

```bash
# edit .env by hand and clear the value:
DATA_ENCRYPTION_PREVIOUS_KEYS=
```

```bash
php artisan encryption:health
```

Do not clear `DATA_ENCRYPTION_PREVIOUS_KEYS` until the second `encryption:health` run (after the edit) also reports `safe-to-clear`. If it reports anything else, put the old value back and investigate — clearing the previous-key list before every row is confirmed on the primary key is what turns a rotation into permanent data loss.

## Rotating an already-adopted key

Rotation uses the same three commands, in the same order, with the same maintenance-window requirement:

```bash
php artisan encryption:key --force   # --force only needed in a production-like environment
php artisan encryption:rekey
php artisan encryption:health
```

Then clear `DATA_ENCRYPTION_PREVIOUS_KEYS` by hand and run `encryption:health` again to confirm, exactly as in adoption above. Run `encryption:rekey` inside a maintenance window — a large table under active writes should not be rekeyed mid-traffic even though the command is written to tolerate concurrent reads/writes safely.

## Reverting a rollback of this feature

If the encryption code itself is reverted (`git revert`), `DataCrypt` stops existing and every call site falls back to `Crypt`, which is bound to `APP_KEY`. Any row written with the dedicated key becomes unreadable the moment the revert lands, **unless you do this first**:

1. Set `DATA_ENCRYPTION_KEY` in `.env` to the exact same value as `APP_KEY`.
2. Run `php artisan encryption:rekey` so every row is re-encrypted onto that shared value.
3. Only then apply the code revert.

If the rotation was only partially applied (e.g. `encryption:key` ran but `encryption:rekey` did not finish), restore `DATA_ENCRYPTION_KEY` to its previous value and leave `DATA_ENCRYPTION_PREVIOUS_KEYS` untouched — the chain is tried in order, so data stays readable. Confirm with `encryption:health`.

## See also

- `docs/server-migration-runbook.md` — the copy-pasteable checklist for moving an installed app to a new server without losing encrypted data.
- `docs/artisan-commands.md` — full command reference.
