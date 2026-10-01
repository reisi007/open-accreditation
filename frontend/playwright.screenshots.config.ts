import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import process from 'node:process';
import { assertCaptureRootOutsidePlaywrightOutput } from './tests/screenshots/helpers/capture-store';
import { CAPTURE_STORE_DIR, PLAYWRIGHT_SCRATCH_DIR } from './tests/screenshots/helpers/store-paths';

/**
 * Dedicated Playwright config for the ui-review screenshot set.
 *
 * Deliberately SEPARATE from `playwright.config.ts` (testDir
 * `./tests/e2e`): this config only captures screenshots (empty/filled
 * states) for the ui-review skill and must NEVER run inside the standard
 * E2E suite. Run it via `pnpm test:screenshots`.
 *
 * Both paths come from `tests/screenshots/helpers/store-paths.ts`, so the
 * scratch space and the capture store cannot drift apart: the store is
 * `test-artifacts/ui-review/`, OUTSIDE `test-results/` entirely, and that is what
 * protects it — not the check below.
 */

/**
 * The residual check, at CONFIG-LOAD time, and only for a deliberately misaimed
 * `--output`.
 *
 * ## Why here and not only in a hook
 *
 * Playwright deletes the resolved `outputDir` recursively before the first test,
 * and `globalSetup` runs AFTER that deletion. MEASURED: a run with
 * `--output=test-results` while the store was still a sibling inside
 * `test-results/` went 192 PNG → 0 even with the guard in `globalSetup` — the
 * guard fired only to announce the loss. The config file itself is `require`d
 * while Playwright is still RESOLVING the config, i.e. before it knows what to
 * delete, so a refusal from here still leaves the evidence on disk.
 *
 * ## What it is worth now
 *
 * The store is outside `test-results/`, so no default and no habitual flag
 * reaches it; what is left is `--output` aimed AT the store, which takes intent.
 * That is a tripwire, not the protection, and `store-guard.spec.ts` says so
 * where it is tested. `--output` is resolved by Playwright before the project and
 * config values, so it is the only value this pre-flight has to look at.
 */
function effectiveOutputDir(): string {
    const argv = process.argv;
    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        if (argument === '--output') {
            const value = argv[index + 1];
            if (value !== undefined && !value.startsWith('-')) {
                return path.resolve(process.cwd(), value);
            }
        }
        if (argument !== undefined && argument.startsWith('--output=')) {
            return path.resolve(process.cwd(), argument.slice('--output='.length));
        }
    }
    return path.resolve(process.cwd(), PLAYWRIGHT_SCRATCH_DIR);
}

assertCaptureRootOutsidePlaywrightOutput(path.resolve(process.cwd(), CAPTURE_STORE_DIR), effectiveOutputDir());

export default defineConfig({
    testDir: './tests/screenshots',
    testMatch: '**/*.spec.ts',
    globalSetup: './tests/screenshots/global-setup.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    // 2 workers keep the suite under the backend's per-IP login throttle
    // (40/min in local): every test seeds data AND logs in through the UI, so
    // a higher concurrency bursts past the budget (429) in the full run.
    workers: process.env.CI ? 2 : 2,
    timeout: 120000,
    reporter: [
        // FIRST, because it is the only reporter that writes to the terminal and it
        // has to be read BEFORE the HTML report's own noise. It shouts once per run
        // when this checkout still carries a capture store at the path the harness
        // used before the move to `test-artifacts/` — a complete, plausible-looking
        // but OUTDATED review batch a reviewer could otherwise open by accident.
        // Silent when there is nothing there, which is the normal case.
        //
        // Registered BY PATH on purpose: the entry is a tuple whose NAME is a path, so
        // Playwright loads the module and `tsc` does not follow it — and the check
        // behind it is plain ESM (`scripts/stale-store.mjs`, shared with
        // `scripts/ui-review-captures.mjs`). `tsconfig.node.json` — the project that
        // type-checks THIS file — has no `allowJs`, so a real import would fail
        // `tsc -b`. Playwright requires every entry to be a tuple
        // (`config.reporter[0] must be a tuple`, measured), hence `['./path']` rather
        // than a bare string. See the reporter's own docblock.
        ['./tests/screenshots/helpers/stale-store-reporter.ts'],
        ['html', { open: 'never', outputFolder: 'playwright-report/ui-screenshots' }],
    ],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
        trace: 'off',
        video: 'off',
    },
    outputDir: PLAYWRIGHT_SCRATCH_DIR,
    projects: [
        { name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 950 } } },
        { name: 'Mobile Chrome', use: { ...devices['Galaxy A55'] } },
    ],
});
