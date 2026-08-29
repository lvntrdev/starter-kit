# Server Migration Runbook

A copy-pasteable checklist for moving an already-installed application to a new server without losing encrypted settings or 2FA data. Read `docs/encryption.md` first if any step here is unfamiliar.

**The one rule that matters most:** carry the existing `.env` file, and do **not** run `php artisan key:generate` on the target server.

## Before you start

- [ ] Take a full database backup on the source server.
- [ ] Confirm you have a copy of the source server's `.env` file, in full — not a regenerated one, not a `.env.example` filled in from memory.
- [ ] Schedule a maintenance window if the source app has active traffic.

## 1. Copy the environment file

- [ ] Copy the source server's `.env` file to the target server verbatim, including **both**:
  - `APP_KEY`
  - `DATA_ENCRYPTION_KEY` and `DATA_ENCRYPTION_PREVIOUS_KEYS` (even if `DATA_ENCRYPTION_KEY` is empty — that emptiness is itself meaningful and must be carried, not "fixed")
- [ ] Do **not** create a fresh `.env` from `.env.example` and paste values in by hand. Copy the actual file.

## 2. Do NOT run these on the target server

- [ ] **Do not run `php artisan key:generate`.** It overwrites `APP_KEY`. If `DATA_ENCRYPTION_KEY` is empty on this install, `APP_KEY` IS the primary data-encryption key — overwriting it makes every encrypted setting and every user's 2FA secret permanently unreadable, with no error at the point of failure.
- [ ] Do not run `php artisan encryption:key` on the target server as part of this migration. That command generates a *new* key; it is for rotation, not migration. Migration carries the existing key across, unchanged.
- [ ] Do not run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe` on the target server against data you intend to keep.

## 3. Deploy the application code and run migrations

- [ ] Deploy the codebase (same version as source, or your intended upgrade target).
- [ ] Install dependencies (`composer install --no-dev`, `npm ci && npm run build` as applicable to your deploy process).
- [ ] Run `php artisan migrate` (schema only — no encryption keys are touched by migrations).
- [ ] Restore the database backup from step "Before you start" if this is a fresh database on the target server, rather than a replicated/streamed one.

## 4. Verify BEFORE serving traffic

Do not point DNS/load balancer traffic at the target server until both of these pass:

- [ ] `php artisan encryption:health`

  Expect `safe-to-clear` (exit 0) if the source server had already run `encryption:rekey`/cleared its previous-keys list, or `rekey-required` (exit 1) if it had pending previous keys — both are fine at this stage, they mean data is readable. **`unreadable` (exit 2) or `key-error` (exit 2) means STOP** — do not serve traffic; see the recovery section below.

- [ ] `php artisan sk:doctor`

  Confirm no failing checks, in particular any encryption-related check (`Data Encryption Key`). A warning may be acceptable depending on your environment; a failure is not.

- [ ] Spot-check manually: open the admin panel's Settings screen and confirm a previously-configured sensitive value (e.g. mail password, a configured storage secret) still shows as configured rather than empty. Log in as a user with 2FA enabled and confirm the 2FA challenge still accepts a code.

Only after both commands pass and the spot-check succeeds, cut traffic over to the target server.

## 5. After cutover

- [ ] Keep the source server's database and `.env` available, untouched, until you are confident the target server is stable (rollback path).
- [ ] If you plan to rotate the data encryption key on the new server, do it as a separate, later, deliberate operation — see `docs/encryption.md` → "Rotating an already-adopted key". Do not combine key rotation with the server migration itself; verify the migration succeeded first.

## Recovery: the old key is genuinely gone

If `.env` was not carried correctly and the key that encrypted existing data (`APP_KEY` and/or `DATA_ENCRYPTION_KEY`, whichever was primary on the source server) is truly lost — not backed up anywhere, not recoverable from any copy of the old `.env` — then:

- **No command in this kit can recover that data.** `encryption:health` will report `unreadable` or `key-error` and stay that way; there is no repair path.
- **What is unrecoverable:**
  - Every sensitive setting encrypted under the lost key (`mail.password`, `storage.spaces_secret`, `storage.aws_secret`, `turnstile.secret_key`, `postman.api_key`, `apidog.access_token`) — these must be **re-entered by hand** in the Settings screen once the target server is otherwise healthy.
  - Every user's 2FA secret and recovery codes encrypted under the lost key — each affected user must **disable and re-enrol two-factor authentication**. They cannot self-recover through the existing challenge flow if the secret itself cannot be decrypted.
- **What is not affected:** anything encrypted under a key you *do* still have (e.g. if only `DATA_ENCRYPTION_PREVIOUS_KEYS` was lost but the current primary key was carried correctly, only rows still on the old key are affected — run `encryption:health` to see exactly which).
- Do not attempt to work around this by clearing `DATA_ENCRYPTION_PREVIOUS_KEYS` or regenerating `APP_KEY`/`DATA_ENCRYPTION_KEY` "to make the error go away" — that does not restore the data and forecloses any chance of finding the real key later.

## Placeholder values used in this document

Any key shown in this document or `docs/encryption.md` (e.g. in an `.env.example` block) is an obviously fake placeholder such as `base64:REPLACE_ME_WITH_A_GENERATED_KEY=` — never a real key. Never paste a real `APP_KEY` or `DATA_ENCRYPTION_KEY` value into a ticket, chat, or commit message either; treat both the same way you treat any other secret.

## See also

- `docs/encryption.md` — what is encrypted, the key-resolution contract, adoption and rotation procedures.
- `docs/artisan-commands.md` — full command reference including `sk:doctor`.
