#!/usr/bin/env bash
#
# Provision a throwaway Laravel application with THIS checkout of the starter
# kit installed into it, seed an E2E admin, build the frontend and leave a dev
# server running. The fixture base URL is the ONLY thing printed on stdout, so
# a caller can do:
#
#   E2E_BASE_URL="$(bash scripts/bootstrap-fixture-app.sh)"
#
# Everything else (progress, warnings, log tails) goes to stderr.
#
# The kit is wired in as a Composer *path repository*, which symlinks the
# checkout into the fixture's vendor/ — so the app under test is the working
# tree, not a published release, and `sk:install` itself is exercised on every
# run.
#
# Package-repo tooling only: this file is never copied into stubs/ (the
# consumer-facing scaffold tree), same carve-out as scripts/ci/*.
#
# Environment:
#   FIXTURE_DIR       where the fixture app is provisioned (default: $TMPDIR/sk-e2e-fixture)
#   E2E_HOST          dev-server host (default: 127.0.0.1)
#   E2E_PORT          dev-server port (default: 8000)
#   E2E_BOOT_TIMEOUT  seconds to wait for /login to answer (default: 120)
#   LARAVEL_SKELETON  skeleton package to create the app from (default: laravel/laravel:^13.0)
#   E2E_SEEDER_CLASS  seeder run after install (default: Database\Seeders\E2EAdminSeeder)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DEFAULT_FIXTURE_DIR="${TMPDIR:-/tmp}"
DEFAULT_FIXTURE_DIR="${DEFAULT_FIXTURE_DIR%/}/sk-e2e-fixture"

FIXTURE_DIR="${FIXTURE_DIR:-$DEFAULT_FIXTURE_DIR}"
E2E_HOST="${E2E_HOST:-127.0.0.1}"
E2E_PORT="${E2E_PORT:-8000}"
E2E_BOOT_TIMEOUT="${E2E_BOOT_TIMEOUT:-120}"
LARAVEL_SKELETON="${LARAVEL_SKELETON:-laravel/laravel:^13.0}"
E2E_SEEDER_CLASS="${E2E_SEEDER_CLASS:-Database\\Seeders\\E2EAdminSeeder}"

BASE_URL="http://${E2E_HOST}:${E2E_PORT}"
SEEDER_SOURCE_DIR="$REPO_ROOT/scripts/e2e/fixtures"

# Written right after the app is provisioned. Its presence is the ONLY proof
# this script owns the directory, and therefore the only licence it has to
# delete one on a re-run — see reset_fixture_dir().
MARKER_FILE="$FIXTURE_DIR/.sk-e2e-fixture"
PID_FILE="$FIXTURE_DIR/.e2e-server.pid"
URL_FILE="$FIXTURE_DIR/.e2e-base-url"
SERVE_LOG="$FIXTURE_DIR/storage/logs/e2e-serve.log"

SERVER_PID=""
# Flips to 1 once the server is verified and handed to the caller; until then a
# failure must not leave an orphaned server behind.
HANDOFF=0

export COMPOSER_NO_INTERACTION=1

# ─────────────────────────────────────────────────────────────────────────────
# helpers
# ─────────────────────────────────────────────────────────────────────────────

log() { printf '  %s\n' "$*" >&2; }
step() { printf '\n▸ %s\n' "$*" >&2; }
die() { printf '\n✖ %s\n' "$*" >&2; exit 1; }

# `php artisan serve` runs the actual PHP built-in server as a CHILD process, so
# killing the artisan process alone can orphan a listener on the port. Children
# are reaped by parent pid — never by port, which would let this script kill an
# unrelated server that happens to hold 8000.
stop_server() {
    pid="$1"

    [ -n "$pid" ] || return 0
    kill -0 "$pid" 2>/dev/null || return 0

    if command -v pkill >/dev/null 2>&1; then
        pkill -P "$pid" 2>/dev/null || true
    fi

    kill "$pid" 2>/dev/null || true

    for _ in 1 2 3 4 5 6 7 8 9 10; do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.5
    done
}

cleanup() {
    status=$?

    if [ "$status" -ne 0 ] && [ "$HANDOFF" -eq 0 ] && [ -n "$SERVER_PID" ]; then
        if [ -f "$SERVE_LOG" ]; then
            printf '\n--- %s (last 40 lines) ---\n' "$SERVE_LOG" >&2
            tail -n 40 "$SERVE_LOG" >&2 || true
        fi
        stop_server "$SERVER_PID"
        rm -f "$PID_FILE"
    fi
}
trap cleanup EXIT

require_tool() {
    command -v "$1" >/dev/null 2>&1 || die "\`$1\` was not found on PATH — it is required to build the fixture app."
}

# Bash's own /dev/tcp, so no dependency on lsof/nc (absent on some CI images).
port_open() {
    (exec 3<>"/dev/tcp/${E2E_HOST}/${E2E_PORT}") >/dev/null 2>&1
}

# Run a command with the fixture app as the working directory.
in_fixture() {
    (cd "$FIXTURE_DIR" && "$@")
}

artisan() {
    in_fixture php artisan "$@"
}

# ─────────────────────────────────────────────────────────────────────────────
# 0. preflight
# ─────────────────────────────────────────────────────────────────────────────

step "Preflight"

for tool in php composer node npm curl; do
    require_tool "$tool"
done

case "$FIXTURE_DIR" in
    /*) ;;
    *) die "FIXTURE_DIR must be an absolute path (got: $FIXTURE_DIR)." ;;
esac

case "$FIXTURE_DIR" in
    / | "$HOME" | "$HOME"/) die "Refusing to use [$FIXTURE_DIR] as the fixture directory." ;;
esac

# sk:install refuses to write into the kit's own checkout (RefusesPackageSourceTree),
# and rightly so — catch it here with a message that names the cause.
case "$FIXTURE_DIR" in
    "$REPO_ROOT" | "$REPO_ROOT"/*)
        die "FIXTURE_DIR must live OUTSIDE the package checkout ($REPO_ROOT) — sk:install refuses to run inside it."
        ;;
esac

log "repo:    $REPO_ROOT"
log "fixture: $FIXTURE_DIR"
log "url:     $BASE_URL"

# ─────────────────────────────────────────────────────────────────────────────
# 1. stop a server left by a previous run, then re-provision the directory
# ─────────────────────────────────────────────────────────────────────────────

step "Clearing the previous fixture"

if [ -f "$PID_FILE" ]; then
    previous_pid="$(cat "$PID_FILE" 2>/dev/null || true)"

    if [ -n "$previous_pid" ] && kill -0 "$previous_pid" 2>/dev/null; then
        log "Stopping the dev server from the previous run (pid $previous_pid)…"
        stop_server "$previous_pid"
    fi
fi

reset_fixture_dir() {
    if [ ! -e "$FIXTURE_DIR" ]; then
        return 0
    fi

    [ -d "$FIXTURE_DIR" ] || die "[$FIXTURE_DIR] exists and is not a directory."

    # Three ways a delete is provably safe: this script provisioned it (marker),
    # there is nothing in it, or it is the script's own default scratch path.
    # An operator-supplied directory holding unknown content is never deleted.
    if [ -f "$MARKER_FILE" ] || [ -z "$(ls -A "$FIXTURE_DIR" 2>/dev/null)" ] || [ "$FIXTURE_DIR" = "$DEFAULT_FIXTURE_DIR" ]; then
        log "Removing the previous fixture tree…"
        rm -rf -- "$FIXTURE_DIR"
        return 0
    fi

    die "[$FIXTURE_DIR] is not empty and was not created by this script — refusing to delete it. Point FIXTURE_DIR at a scratch directory."
}

reset_fixture_dir

if port_open; then
    die "Port ${E2E_PORT} on ${E2E_HOST} is already in use. Stop that process or run with E2E_PORT=<free port>."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 2. fresh Laravel skeleton
# ─────────────────────────────────────────────────────────────────────────────

step "Creating a Laravel skeleton ($LARAVEL_SKELETON)"

# --no-scripts: the skeleton's post-create hooks copy .env.example and run
# `migrate`, which would record the STOCK users/cache/jobs migrations as run.
# The kit republishes those same filenames with extra columns, so a pre-migrated
# skeleton would leave the kit's version permanently skipped.
composer create-project "$LARAVEL_SKELETON" "$FIXTURE_DIR" \
    --no-interaction --no-progress --prefer-dist --no-scripts

touch "$MARKER_FILE"

# ─────────────────────────────────────────────────────────────────────────────
# 3. .env (sqlite, no external services)
# ─────────────────────────────────────────────────────────────────────────────

step "Writing .env"

SQLITE_PATH="$FIXTURE_DIR/database/database.sqlite"
rm -f "$SQLITE_PATH"
touch "$SQLITE_PATH"

# Written BEFORE sk:install on purpose. The installer merges only the keys that
# are MISSING from an existing .env, so every value set here survives — and the
# kit's own .env.example defaults (mysql, redis cache, database queue) never
# reach this fixture.
{
    printf 'APP_NAME="Starter Kit E2E"\n'
    printf 'APP_ENV=local\n'
    printf 'APP_KEY=\n'
    printf 'APP_DEBUG=true\n'
    printf 'APP_URL=%s\n' "$BASE_URL"
    printf 'APP_TIMEZONE=UTC\n'
    printf 'APP_DISPLAY_TIMEZONE=UTC\n'
    printf 'APP_FALLBACK_LOCALE=en\n'
    printf 'APP_FAKER_LOCALE=en_US\n'
    printf 'LOG_CHANNEL=stack\n'
    printf 'LOG_LEVEL=debug\n'
    printf 'DB_CONNECTION=sqlite\n'
    printf 'DB_DATABASE=%s\n' "$SQLITE_PATH"
    printf 'DB_FOREIGN_KEYS=true\n'
    printf 'SESSION_DRIVER=database\n'
    printf 'SESSION_LIFETIME=120\n'
    printf 'CACHE_STORE=file\n'
    printf 'QUEUE_CONNECTION=sync\n'
    printf 'BROADCAST_CONNECTION=log\n'
    printf 'FILESYSTEM_DISK=local\n'
    printf 'MAIL_MAILER=log\n'
    # Test-only cost factor: keeps the seeded login fast. Never a production value.
    printf 'BCRYPT_ROUNDS=4\n'
} > "$FIXTURE_DIR/.env"

# ─────────────────────────────────────────────────────────────────────────────
# 4. require the kit from this checkout (path repository → symlink)
# ─────────────────────────────────────────────────────────────────────────────

step "Requiring lvntr/laravel-starter-kit from $REPO_ROOT"

# `options.versions` pins the version instead of letting Composer guess it from
# git. CI checks out a detached HEAD, where the guesser produces an unstable
# dev-<sha> that no root constraint can name.
in_fixture composer config repositories.starter-kit \
    "{\"type\":\"path\",\"url\":\"$REPO_ROOT\",\"options\":{\"symlink\":true,\"versions\":{\"lvntr/laravel-starter-kit\":\"dev-main\"}}}"

in_fixture composer require "lvntr/laravel-starter-kit:dev-main" \
    --no-interaction --no-progress --with-all-dependencies

if [ ! -e "$FIXTURE_DIR/vendor/lvntr/laravel-starter-kit" ]; then
    die "The kit is not present at vendor/lvntr/laravel-starter-kit — the path repository did not resolve."
fi

step "Generating the application key"
artisan key:generate --force --no-interaction

# ─────────────────────────────────────────────────────────────────────────────
# 5. install the kit
# ─────────────────────────────────────────────────────────────────────────────

step "Running sk:install"

# --no-interaction auto-accepts every step (migrations, seeders, permissions,
# passport, admin user, npm install + build) and takes the ADDITIVE migration
# path — the destructive "fresh" branch is never offered without a TTY.
artisan sk:install --no-interaction

# ─────────────────────────────────────────────────────────────────────────────
# 6. frontend build (the installer's npm steps are best-effort — verify them)
# ─────────────────────────────────────────────────────────────────────────────

step "Verifying the frontend build"

frontend_built() {
    [ -f "$FIXTURE_DIR/public/build/manifest.json" ] || [ -f "$FIXTURE_DIR/public/build/.vite/manifest.json" ]
}

if frontend_built; then
    log "Build manifest is present."
else
    log "sk:install did not leave a build manifest — building explicitly…"
    in_fixture npm install --no-audit --no-fund
    artisan wayfinder:generate
    in_fixture npm run build

    frontend_built || die "No Vite manifest under public/build after \`npm run build\` — the fixture cannot render a page."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 7. E2E seeder
# ─────────────────────────────────────────────────────────────────────────────

step "Seeding the E2E admin"

if ! ls "$SEEDER_SOURCE_DIR"/*.php >/dev/null 2>&1; then
    die "No seeder found in $SEEDER_SOURCE_DIR — the E2E admin seeder must exist before the fixture can be seeded."
fi

cp "$SEEDER_SOURCE_DIR"/*.php "$FIXTURE_DIR/database/seeders/"

artisan db:seed --class="$E2E_SEEDER_CLASS" --force --no-interaction

# ─────────────────────────────────────────────────────────────────────────────
# 8. serve + wait for /login
# ─────────────────────────────────────────────────────────────────────────────

step "Starting the dev server"

mkdir -p "$(dirname "$SERVE_LOG")"
: > "$SERVE_LOG"

# Invoked by absolute path rather than through `cd`: ServeCommand sets its own
# working directory (the app's public/), so $! is the artisan process itself and
# not a wrapper subshell whose pid we could not use to reap the server.
nohup php "$FIXTURE_DIR/artisan" serve --host="$E2E_HOST" --port="$E2E_PORT" >> "$SERVE_LOG" 2>&1 &
SERVER_PID=$!
printf '%s\n' "$SERVER_PID" > "$PID_FILE"
log "pid $SERVER_PID → $SERVE_LOG"

deadline=$(( $(date +%s) + E2E_BOOT_TIMEOUT ))

until curl -fsS -o /dev/null --max-time 10 "$BASE_URL/login"; do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        die "The dev server exited before /login answered."
    fi

    if [ "$(date +%s)" -ge "$deadline" ]; then
        die "/login did not answer within ${E2E_BOOT_TIMEOUT}s."
    fi

    sleep 1
done

log "/login answered."

# ─────────────────────────────────────────────────────────────────────────────
# 9. hand off
# ─────────────────────────────────────────────────────────────────────────────

HANDOFF=1

printf '%s\n' "$BASE_URL" > "$URL_FILE"

if [ -n "${GITHUB_ENV:-}" ] && [ -w "${GITHUB_ENV:-}" ]; then
    printf 'E2E_BASE_URL=%s\n' "$BASE_URL" >> "$GITHUB_ENV"
    printf 'E2E_FIXTURE_DIR=%s\n' "$FIXTURE_DIR" >> "$GITHUB_ENV"
fi

step "Ready"
log "Stop the server with: kill \$(cat $PID_FILE)"

# The one and only stdout line: the base URL, for E2E_BASE_URL.
printf '%s\n' "$BASE_URL"
