import { defineConfig, devices } from '@playwright/test';
import process from 'node:process';

/**
 * Dedicated Playwright config for the ui-review screenshot set.
 *
 * Deliberately SEPARATE from `playwright.config.ts` (testDir
 * `./tests/e2e`): this config only captures screenshots (empty/filled
 * states) for the ui-review skill and must NEVER run inside the standard
 * E2E suite. Run it via `pnpm test:screenshots`.
 */
export default defineConfig({
    testDir: './tests/screenshots',
    testMatch: '**/*.spec.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 4 : 4,
    timeout: 120000,
    reporter: [
        ['html', { open: 'never', outputFolder: 'playwright-report/ui-screenshots' }],
    ],
    use: {
        baseURL: 'http://localhost:5173',
        trace: 'off',
        video: 'off',
    },
    outputDir: 'test-results/ui-screenshots',
    projects: [
        { name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 950 } } },
        { name: 'Mobile Chrome', use: { ...devices['Galaxy A55'] } },
    ],
});
