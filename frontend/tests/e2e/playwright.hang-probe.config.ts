import path from 'node:path';
import { defineConfig } from '@playwright/test';

/**
 * A config for the HANG probe — the child that never returns.
 *
 * ## Why it is a separate config and not a `grep` on the probe config
 *
 * Two reasons, and the first is the important one. The `testMatch` of
 * `playwright.ownership-probe.config.ts` is `*.fixture.ts`, so a third fixture
 * file placed next to the other two would be collected by EVERY real driver run
 * and would sit out a deliberate timeout each time. A `grep` would avoid that,
 * but not the second reason:
 *
 * **The timeout this config exists to exercise must be short.** The driver kills
 * its child after 240 s because that is a generous ceiling for a two-second API
 * probe in a 300 s test. A hang test cannot afford 240 s per run, and it must not
 * inherit the 60 s the probe config uses for its own tests either. So the child
 * here is killed by the DRIVER after a few seconds, while the production value
 * stays 240 s and is a CONSTANT, not a parameter.
 *
 * `retries: 0` and one worker, for the same reason as the probe config: this run
 * is a machine-level experiment and its output is read by a human, not averaged.
 */
export default defineConfig({
    testDir: path.resolve(process.cwd(), 'tests/e2e/ownership-probe'),
    // Narrow on purpose: the hang fixture only. `half-failure.fixture.ts` must not
    // be collected here — it writes to the same hand-off files the real probe
    // uses, and a collision would make the real driver's next run read this
    // experiment's leftovers.
    testMatch: 'hang.probe.ts',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    forbidOnly: true,
    // Its OWN output dir for the same reason as the ownership-probe config: a
    // child runner empties `test-results/` by default, where the hand-off record
    // it is here to write lives.
    outputDir: path.resolve(process.cwd(), 'test-results/hang-probe'),
    // No test-level ceiling that could pre-empt the DRIVER's kill: a Playwright
    // timeout here would end the test first and mask the signal being measured.
    timeout: 600000,
    reporter: [['line']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    },
    projects: [{ name: 'hang-probe' }],
});
