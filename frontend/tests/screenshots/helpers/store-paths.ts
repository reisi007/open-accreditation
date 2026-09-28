import path from 'node:path';
import process from 'node:process';

/**
 * The two directories the ui-review harness uses, in one place, because the
 * relationship between them is the whole point.
 *
 * ## Why this file exists
 *
 * The store used to be `test-results/ui-review/` — a SIBLING of Playwright's
 * `outputDir`, which protected it against the DEFAULT and nothing else.
 * MEASURED, in this order:
 *
 *   1. No guard at all, store inside `outputDir`: a partial re-capture
 *      (`-g "screenshot home"`) took it from **126 PNG to 11**. The "old" half of
 *      every comparison was gone, which is what made the §7 fix loop unrunnable.
 *   2. Store moved next to `outputDir`, guard added in `globalSetup`:
 *      `--output=test-results` (Playwright's DEFAULT `outputDir`, and the store's
 *      own parent) still took it from **192 PNG to 0** — because the runner wipes
 *      `outputDir` BEFORE `globalSetup` runs. A sibling inside `test-results/`
 *      protects against the standard, not against the possibility.
 *   3. Store OUT of `test-results/` entirely: `--output=test-results` leaves it
 *      untouched, because the runner has nothing to delete there.
 *
 * So the protection is the PATH, not a watcher. This module exists so the path
 * and the guard can never drift apart again: they are declared once, imported by
 * the config, the store, the dataset marker/lock and the guard's spec.
 *
 * ## What is left for the watcher, honestly
 *
 * `--output` can still be aimed at the store (`--output=test-artifacts`,
 * `--output=.`). That now takes *intent* rather than a default, and
 * `assertCaptureRootOutsidePlaywrightOutput()` (see `capture-store.ts`) refuses it
 * at config-load time, i.e. before the wipe. It is a tripwire, not the
 * protection — `store-guard.spec.ts` says so where it is tested.
 */

/** The capture store: one level under `frontend/`, outside `test-results/`. */
export const CAPTURE_STORE_DIR = 'test-artifacts/ui-review';

/** Playwright's scratch space for the screenshot suite. Configured, not default. */
export const PLAYWRIGHT_SCRATCH_DIR = 'test-results/ui-screenshots';

/** Absolute capture store root. */
export function captureStoreRoot(): string {
    return path.resolve(process.cwd(), CAPTURE_STORE_DIR);
}

/** Absolute Playwright scratch root. */
export function playwrightScratchDir(): string {
    return path.resolve(process.cwd(), PLAYWRIGHT_SCRATCH_DIR);
}
