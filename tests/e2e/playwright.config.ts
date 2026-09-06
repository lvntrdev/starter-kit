import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the package's E2E smoke suite.
 *
 * Scope is intentionally narrow: a single Chromium project covering one
 * critical admin flow (login → create user → assign role → settings →
 * logout). Cross-browser (Firefox/WebKit) and full-page coverage are out of
 * scope — see plan-docs/2026-09-06-playwright-e2e-smoke.md.
 *
 * The target server is provisioned separately by
 * scripts/bootstrap-fixture-app.sh, which prints/exports the base URL
 * consumed here via E2E_BASE_URL. This config never starts a server itself
 * (no `webServer` block) — bootstrap and test run are separate steps both
 * locally and in CI.
 */
export default defineConfig({
    testDir: './specs',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI ? [['html', { open: 'never' }], ['list']] : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
        video: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
