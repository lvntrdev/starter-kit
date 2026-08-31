# Command reference

> Reference detail for the `lvntr-starter-kit` skill — read on demand.

### Lifecycle

```bash
php artisan sk:install                # one-time setup (interactive) — FIRST INSTALL ONLY
php artisan sk:install --adopt        # already installed, registry lost: rebuild hashes.json ONLY
php artisan sk:install --adopt --dry-run   # preview the registry it would write
php artisan sk:install --force        # overwrite everything AND bypass the already-installed stop
php artisan sk:install --resume       # resume an interrupted install (checkpointed per step)
php artisan sk:install --without-eject     # keep User/Role runtime in vendor (skip default eject)
php artisan sk:install --without-ai-skill  # skip publishing the AI skills (.claude/skills + .codex/skills)

php artisan sk:update                 # upgrade kit files; preserves your customizations
php artisan sk:update --dry-run       # preview what would change  ← ALWAYS first
php artisan sk:update --force         # ignore hash registry; overwrite everything

php artisan sk:upgrade                # only for projects upgrading Laravel 12 → 13 (asserts PHP 8.4)
```

**`sk:install` is a first-install command, not a repair tool.** It runs a
fail-closed detection pass before the banner (kit schema tables + install-only
paths) and STOPS before writing anything if the kit is already there without a
matching `storage/starter-kit/hashes.json`. To change an installed app use
`sk:update` or `sk:publish --tag=<area>`; to recover a lost registry use
`--adopt`. An existing `.env` is merged, never overwritten. A failed mandatory
step (publish / migrations / seeders / permissions / Passport keys / encryption
keys) aborts the run, withholds the registry, keeps the checkpoint for
`--resume` and exits non-zero; frontend steps only warn.

When the database already holds tables the migration step asks how to proceed.
`Run pending migrations only` is the default and the only option a
non-interactive session gets. The `migrate:fresh` branch is withheld entirely on
a production-like `APP_ENV`, with `APP_DEBUG` off, without a TTY, or when any
table already holds rows — and when offered it requires the database name (or
the word `fresh`) TYPED at a prompt; anything else falls back to `migrate`.

### Health check

```bash
php artisan sk:doctor                 # environment/config/queue/schedule checks
php artisan sk:doctor --json          # machine-readable output (used by the admin UI)
php artisan sk:doctor --only=database,redis
```

### Domain scaffolding

```bash
# Interactive wizard — prompts for fields, layers, soft-deletes, vue mode
php artisan make:sk-domain Product

# Non-interactive (CI/scripting)
php artisan make:sk-domain Product \
    --fields="name:string,price:decimal,status:string" \
    --id-type=ulid \
    --admin --api --events \
    --soft-deletes \
    --vue=full --vue-fields

# Opt-in extras: Policy, Factory, Seeder, Pest test, Eloquent relations
php artisan make:sk-domain Product --with=policy,factory,seeder,test
php artisan make:sk-domain Product --with-relations            # interactive relation wizard
php artisan make:sk-domain Product --relations="belongsTo:User,hasMany:Comment"

# Parse fields from an existing migration instead of typing them
php artisan make:sk-domain Product --from-migration=2026_03_21_create_products_table.php

# Reverse it (also strips route entries and provider registrations)
php artisan remove:sk-domain Product
```

### Ejecting vendor domains (`sk:eject`)

Kit domain runtimes live in the vendor package and resolve through `class_alias`;
a local copy in `app/` always wins. To take full, project-owned control of a domain:

```bash
php artisan sk:eject User             # copy vendor domain into app/Domain/User + rewrite namespace
php artisan sk:eject Role --dry-run   # print the copy/rewrite/injection plan, write nothing
php artisan sk:eject Setting --no-vue # backend only; leave Vue pages untouched
php artisan sk:eject Media --force    # overwrite existing app files (backend + Vue pages)
```

Ejectable domains: `User`, `Role`, `Setting`, `ActivityLog`, `ApiClient`,
`SystemHealth`, `ContentLanguage`, `Definitions`, `MediaUpload`, `ApiRoute`,
`Logs`, `Files`, `Session`, `Media`.

**Trade-off:** an ejected domain no longer receives upstream fixes via
`composer update`. Fresh installs auto-eject `User` and `Role` (opt out with
`sk:install --without-eject`).

### Data encryption (`DATA_ENCRYPTION_KEY`)

Sensitive `settings.value` rows (mail password, storage secrets, Turnstile
secret, API tokens) and the Fortify 2FA secret / recovery codes ride on
`DATA_ENCRYPTION_KEY`, independent of `APP_KEY`, so a `php artisan key:generate`
no longer makes them unreadable. Adoption is opt-in; an install that runs none of
this keeps working byte-for-byte.

```bash
php artisan encryption:health          # read-only: which key each value needs
php artisan encryption:health --json   # machine-readable
php artisan encryption:key             # generate a new key, preserve the old one in
                                       # DATA_ENCRYPTION_PREVIOUS_KEYS (writes .env)
php artisan encryption:key --show      # print a candidate key, write nothing
php artisan encryption:rekey --dry-run # attempt every decrypt, write nothing  ← ALWAYS first
php artisan encryption:rekey           # re-encrypt rows onto the primary key (maintenance window)
php artisan encryption:rekey --only=settings|two-factor --chunk=200
```

**Order matters.** `encryption:key` → `php artisan config:clear` (if config is
cached) → `encryption:rekey` → `encryption:health`. Never clear
`DATA_ENCRYPTION_PREVIOUS_KEYS` until health reports `safe-to-clear`.

Health verdicts / exit codes: `safe-to-clear` 0 · `rekey-required` 1 ·
`not-covered` 1 (a surface is served by an encrypter the kit did not build, or
the `starter-kit.encryption` config block is absent) · `incomplete` 1 (a surface
could not be fully scanned, or a cached config no longer matches `.env`) ·
`unreadable` 2 · `key-error` 2. The verdict only ever downgrades.

`encryption:key` aborts before generating anything when the **process
environment** overrides one of these keys (directly, or via a variable an
interpolated `.env` value references) — the file must be the authority.
`encryption:rekey` refuses before reading a row when a selected surface is
unvouched. `encryption:key` and `encryption:rekey` share a cache lock and cannot
run at the same time.

### Permissions

```bash
php artisan sk:seed-permissions --fresh   # re-seed roles + permissions after editing
                                          # config/permission-resources.php
```

### Customization

```bash
# Publish optional assets — opens an interactive picker
php artisan sk:publish

# Or pass tags directly:
#   components | datatable | form | tabs | skeleton | ui
#   filemanager | composables | plugins | lang | config | helpers
php artisan sk:publish --tag=form --tag=datatable --force
```

Once you publish a component to `resources/js/components/Lvntr-Starter-Kit/`
(or a composable to `resources/js/composables/`), edit it freely — the local
copy overrides the vendor one, and `sk:update` skips it (the hash registry
detects your modifications).

### Day-to-day

```bash
php artisan site:install              # one-shot: migrate + seed + passport keys + admin
php artisan env:sync                  # propagate .env keys → .env.example (also runs in pre-commit)
php artisan wayfinder:generate        # regenerate @/routes and @/actions after route changes
php artisan file-manager:purge-trash  # permanently delete expired file-manager trash
                                      # (--days=N, --chunk=N 1-5000 default 500; takes a cache
                                      #  lock, exits non-zero when an item was left behind)
composer dev                          # serve + queue + pail + vite (concurrent)
```
