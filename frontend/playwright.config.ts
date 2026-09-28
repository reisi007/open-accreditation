import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
    // NOT the Playwright default (`test-results/`). Playwright **empties the
    // outputDir before every run**, and the screenshot config's outputDir is
    // `test-results/ui-screenshots` — i.e. a subdirectory of the default. One
    // single E2E run therefore deleted all 60 design-QA screenshots (measured:
    // capture → 60 PNG, `a11y.spec.ts` → 0 PNG), and with them the "alt" half of
    // the AGENTS.md §7 fix loop: step 4 requires the vision subagent to compare
    // old against new, which is the step that releases a change at all. Each
    // config now owns a sibling directory, so each run only clears its own.
    outputDir: 'test-results/e2e',
    // Paired on purpose. The teardown restores what the run borrowed, but only
    // if the run ends cleanly — a hard kill in the middle strands the primary
    // mandant's logo and the NEXT run's portal/logo-empty assertions inherit
    // it. The setup clears such stranded state once, before any test of this
    // run, so no spec has to reset state in the same place it asserts on it.
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 4 : 8,
    timeout: 120000,
    maxFailures: process.env.CI ? 10 : 0,
    reporter: [
        ['html', {open: 'never'}]
    ],
    use: {
        // Single source of truth for the origin the suite drives. Read from the
        // environment with the dev/preview default as fallback: a hardcoded port
        // is exactly the WP-9-a trap — `vite preview` serves 4173 unless
        // `vite.config.ts` pins it (it does today, with `strictPort`), so a
        // change there, or any origin served on another port, would otherwise
        // turn every test into connection-refused instead of a config error.
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
        trace: 'on-first-retry',
        video: 'off',
    },
    projects: [
        {name: 'Desktop Chrome', use: {...devices['Desktop Chrome'], viewport: {width: 1920, height: 950},}},
        {name: 'Mobile Chrome', use: {...devices['Galaxy A55']}},
    ],
});
