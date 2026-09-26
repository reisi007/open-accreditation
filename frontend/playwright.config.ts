import {defineConfig, devices} from '@playwright/test';
import process from 'node:process';

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.ts',
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
