import fs from 'node:fs';
import path from 'node:path';
import {
    PROBE_CONTROL_RECORD_PATH,
    PROBE_RECORD_PATH,
    clearProbeRecord,
    readProbeRecord,
} from './ownership-probe/probe-record';

/**
 * A probe config for the "the teardown runs even when the test dies" proof.
 *
 * ## Why a separate config, and why it has NO global teardown
 *
 * The proof has to run a spec that FAILS ON PURPOSE and then check the database.
 * Three properties make a separate config the only way to an honest result:
 *
 * 1. **It must not be picked up by the normal suite.** `testMatch` selects
 *    `fixture.ts` here and `spec.ts` in `playwright.config.ts`, so the probe never
 *    reaches the push gate or the nightly. It runs only when `ownership.spec.ts`
 *    asks for it — which is what keeps the suite green while the probe itself
 *    genuinely fails. (The globs are written without their leading `*` on purpose:
 *    a literal closing delimiter inside this docblock ends the comment early —
 *    the same trap `namespace-isolation.spec.ts` records having hit while being
 *    written.)
 * 2. **It must have NO `globalSetup` and NO `globalTeardown`.** This is the
 *    load-bearing one. The serial teardown in `admin-data.ts` reclaims every
 *    `E2E %` row by name prefix, so with it wired up the probe's leftover would
 *    be swept at the end of the child run and "the row is gone" would hold
 *    *whether or not the per-test teardown ever ran* — a test that cannot fail,
 *    which is the entire thing being tested against. With no global teardown the
 *    per-test `afterEach` is the only candidate.
 * 3. **The name must stay inside the marker namespace**, so a crashed FULL run
 *    still reclaims the row and the coverage guard in `namespace-isolation.spec.ts`
 *    stays green. `E2E Halbfehlschlag ` is registered in `E2E_PURGE_MARKERS`.
 *
 * `outputDir` is its own directory because Playwright empties `outputDir` before
 * every run — the measured reason `playwright.config.ts` does not use the
 * default, and the reason the probe's record file lives one directory above it.
 */
export default {
    // Absolute, and that is not a style choice: Playwright resolves a RELATIVE
    // `testDir` against the CONFIG FILE's directory, and this config lives in
    // `tests/e2e/`, so `'./tests/e2e/ownership-probe'` resolved to
    // `tests/e2e/tests/e2e/ownership-probe` and the child run collected **zero
    // tests** — measured, and silently: a config that matches nothing is an empty
    // green run, not an error. `process.cwd()` is the frontend root both when
    // `ownership.spec.ts` spawns this and when a human runs it by hand.
    testDir: path.resolve(process.cwd(), 'tests/e2e/ownership-probe'),
    testMatch: '*.fixture.ts',
    // One worker: the probe writes ids to files and the driver reads them back,
    // and a single worker keeps that a straight line.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    forbidOnly: true,
    // No 5-minute UI timeouts to sit through — the probe is pure API.
    timeout: 60000,
    reporter: [['line']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    },
    projects: [{ name: 'ownership-probe' }],
    // The record paths are re-exported from here so the driver and the config
    // cannot disagree about where the hand-off file lives.
    probeRecordPath: PROBE_RECORD_PATH,
    probeControlRecordPath: PROBE_CONTROL_RECORD_PATH,
};

/**
 * The two record paths, as absolute paths, resolved the same way the probe
 * resolves them (`process.cwd()` is the frontend root under both invocations).
 * Exported for the driver, which must clear them before and after its run.
 */
export const PROBE_RECORD_PATHS = [PROBE_RECORD_PATH, PROBE_CONTROL_RECORD_PATH];

/** Read a probe record, or `null`. Re-exported so the driver imports one module. */
export function readProbe(filePath = '') {
    return readProbeRecord(filePath);
}

/** Remove a probe record, idempotently. */
export function clearProbe(filePath = '') {
    clearProbeRecord(filePath);
}

/** Create the directory the records live in, if a previous run left none. */
export function ensureProbeRecordDir() {
    fs.mkdirSync(path.dirname(PROBE_RECORD_PATH), { recursive: true });
}
