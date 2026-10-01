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

/**
 * WHERE THE STORE USED TO LIVE, and the reason this module knows about it.
 *
 * The store was moved here by hand, once, out of `test-results/`. Nothing
 * versioned that move — the directory is gitignored — so every checkout that
 * still carries a store from before it keeps a **stale copy** at the old path,
 * and nothing warned about it. A reviewer who opens that path sees a complete,
 * plausible-looking, outdated review batch: not an error, but a correctly-looking
 * wrong finding.
 *
 * So the old path is DECLARED here, next to the two that matter, and
 * `scripts/stale-store.mjs` looks for it and prints a hint with an exit route.
 * The hint is deliberately not a cleanup: a gitignored directory is forgotten the
 * moment nobody looks at it, and deleting a batch silently destroys the one copy
 * somebody may still be comparing against.
 *
 * `scripts/stale-store.mjs` restates this string, because plain ESM and the
 * TS-side import graph cannot share a literal (`tsconfig.node.json` has no
 * `allowJs`, so `playwright.screenshots.config.ts` cannot reach into a `.mjs`).
 * `tests/screenshots/helpers/stale-store.test.ts` asserts the two are equal —
 * the same arrangement `scripts/ui-review-captures.test.ts` uses for
 * `DEFAULT_DIR`, because a comment cannot tie two literals and a failing
 * assertion can.
 */
export const LEGACY_CAPTURE_STORE_DIR = 'test-results/ui-review';

/** Absolute capture store root. */
export function captureStoreRoot(): string {
    return path.resolve(process.cwd(), CAPTURE_STORE_DIR);
}

/** Absolute Playwright scratch root. */
export function playwrightScratchDir(): string {
    return path.resolve(process.cwd(), PLAYWRIGHT_SCRATCH_DIR);
}

/** Absolute path of the store's location BEFORE it was moved out of `test-results/`. */
export function legacyCaptureStoreRoot(): string {
    return path.resolve(process.cwd(), LEGACY_CAPTURE_STORE_DIR);
}
