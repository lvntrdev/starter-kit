# End-to-End Smoke Testing (Playwright)

This is developer-facing documentation for the package repository itself — it has nothing to do with a consumer app's test suite, and none of the files it describes ship to consumers.

## What this suite covers

A single Playwright spec drives a real Chromium browser through one critical admin path, start to finish, as one authenticated session:

1. Log in with a seeded admin account.
2. Create a uniquely-named user and confirm it appears in the list.
3. Assign that user a non-admin role and confirm the assignment persisted.
4. Change one harmless, reversible Settings field and confirm the success flash.
5. Log out and confirm the redirect to `/login`.

This is a **smoke test, not coverage** — it exists to catch real-browser regressions (Inertia round-trips, redirects, session handling) that the jsdom-based `stubs/package.json` → `npm run test` (Vitest) suite structurally cannot see. It deliberately does not attempt per-page coverage, cross-browser runs (Firefox/WebKit), or visual regression — see `plan-docs/2026-09-06-playwright-e2e-smoke.md` for the scoping rationale.

## Running it locally

From the repo root:

```bash
bash scripts/bootstrap-fixture-app.sh && npm run test:e2e
```

`scripts/bootstrap-fixture-app.sh` provisions a throwaway Laravel skeleton, requires this checkout into it as a Composer path repository, runs the package installer non-interactively, migrates a scratch SQLite database, seeds the E2E admin user, builds frontend assets, and starts a dev server. It prints the fixture app's base URL, which `npm run test:e2e` (Playwright, `tests/e2e/`) reads via `E2E_BASE_URL`.

The bootstrap script is safe to re-run against a fresh scratch directory; it does not touch this checkout beyond reading it as a path-repo source.

## Environment variables

| Variable | Used by | Default | Notes |
|---|---|---|---|
| `FIXTURE_DIR` | `scripts/bootstrap-fixture-app.sh` | a fresh temp directory | Where the throwaway Laravel skeleton is provisioned |
| `E2E_PORT` | `scripts/bootstrap-fixture-app.sh` | `8000` | Port the fixture app's dev server binds to |
| `E2E_BASE_URL` | `tests/e2e/playwright.config.ts` | printed by the bootstrap script | Base URL the Playwright spec navigates against |
| `E2E_ADMIN_EMAIL` | `scripts/e2e/fixtures/E2EAdminSeeder.php` | a fixed test-only address | Seeded admin login used by the "log in" step |
| `E2E_ADMIN_PASSWORD` | `scripts/e2e/fixtures/E2EAdminSeeder.php` | a fixed test-only string | Test-only credential for a disposable SQLite fixture — never a real credential against a real system |

## Why this lives in the package repo, not `stubs/`

Playwright, the bootstrap script, and the seeder are this package repository's own developer tooling for testing the kit's installed behavior — they are not a dependency a consumer app should ever install. That is why the new devDependency and `test:e2e` script were added to the **root** `package.json` only, and why `scripts/e2e/` and `tests/e2e/` sit outside `stubs/`, the tree that `sk:install`/`sk:update` copy into consumer apps (see `CLAUDE.md` § "The three code trees").

## CI

A dedicated `e2e` job in `.github/workflows/ci.yml` runs the bootstrap script and the smoke spec on every PR, independently of (and in parallel with) the existing test jobs. On failure it uploads the Playwright test report as a build artifact for post-mortem review.
