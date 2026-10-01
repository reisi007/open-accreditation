import process from 'node:process';
import type { FullConfig, Reporter, Suite } from '@playwright/test/reporter';
import {
    LEGACY_CAPTURE_STORE_DIR,
    inspectStaleCaptureStore,
    formatStaleStoreHint,
} from '../../../scripts/stale-store.mjs';
import type { StaleStoreFinding } from '../../../scripts/stale-store.mjs';
import {
    CAPTURE_STORE_DIR,
    LEGACY_CAPTURE_STORE_DIR as LEGACY_STORE_DIR,
    captureStoreRoot,
    legacyCaptureStoreRoot,
} from './store-paths';

/**
 * A reporter whose only job is to say, loudly and once per run, when this
 * checkout still carries a ui-review capture store at the path it used to have.
 *
 * ## Why a reporter and not a `console.log` inside the capture spec
 *
 * Because the reader is a HUMAN, not the test. §7's visual loop starts with a
 * person assembling a review batch, and the whole hazard is that they open the
 * wrong directory — a complete, plausible-looking, outdated batch under
 * `test-results/ui-review/`, while the real one sits under
 * `test-artifacts/ui-review/`. Nothing in the app, nothing in the page and no
 * assertion can tell that person anything; the run's own output is the one place
 * they are already looking, before they go looking for images.
 *
 * `onBegin` rather than `onEnd`, and this is not cosmetic: Playwright wipes
 * `outputDir` before the first test, so a check that ran later could report on a
 * directory a previous `--output` had already emptied, and a reviewer reading the
 * summary at the bottom of the run has by then scrolled past the top.
 *
 * ## What it does when there is nothing to say
 *
 * Nothing at all — no line, no blank line, no "0 stale stores". The fresh-checkout
 * case and the already-migrated case must cost a reviewer nothing, and a warning
 * that fires unconditionally is a warning nobody reads.
 *
 * ## Registered BY PATH in `playwright.screenshots.config.ts`
 *
 * As a tuple whose NAME is this file: `['./tests/.../stale-store-reporter.ts']`. A
 * reporter entry has to be a tuple (MEASURED: `config.reporter[0] must be a tuple
 * [name, optionalArgument]`), and a tuple's name is loaded by Playwright rather than
 * by `tsc`. That is deliberate: the check lives in a `.mjs` (so
 * `scripts/ui-review-captures.mjs` can share it without a build step) and
 * `tsconfig.node.json` — which type-checks the config file — has no `allowJs`, so a
 * real import here would fail `tsc -b`.
 * `tests/screenshots/helpers/stale-store.test.ts` asserts both the registration and
 * that this module emits the banner, so a broken wiring is a failing test rather than
 * a silent no-op. It asserts the registration from the config's SOURCE rather than by
 * importing it: importing the config would drag in `@playwright/test`, which costs
 * 121 s under Vitest (measured).
 */

/** The paths a check uses, as the reviewer types them plus their absolute form. */
export interface StaleStorePaths {
    legacyRoot: string;
    legacyDir: string;
    currentRoot: string;
    currentDir: string;
}

/** The committed default: this checkout's old path against this checkout's new one. */
export function committedStaleStorePaths(): StaleStorePaths {
    return {
        legacyRoot: legacyCaptureStoreRoot(),
        legacyDir: LEGACY_STORE_DIR,
        currentRoot: captureStoreRoot(),
        currentDir: CAPTURE_STORE_DIR,
    };
}

/**
 * The banner for whatever is at `paths`, or `null` when there is nothing.
 *
 * Split out from the reporter class so a test can drive it against a synthetic
 * store without touching the real `test-results/` — and so the reporter's own
 * body stays the two lines that have to be read, not the logic.
 */
export function staleStoreBanner(paths: StaleStorePaths): string | null {
    const finding: StaleStoreFinding | null = inspectStaleCaptureStore(paths);
    return finding === null ? null : formatStaleStoreHint(finding);
}

/**
 * Writes the banner through `write` when there is a stale store; returns whether
 * it wrote anything.
 */
export function reportStaleStore(paths: StaleStorePaths, write: (text: string) => void): boolean {
    const banner = staleStoreBanner(paths);
    if (banner === null) {
        return false;
    }
    write(`${banner}\n`);
    return true;
}

/**
 * Resolves the stale store once per run and writes the banner to stdout.
 *
 * stdout rather than stderr because this is information for a reader, not a
 * diagnostic, and this is the only reporter in
 * `playwright.screenshots.config.ts` that writes to the terminal — an unknown
 * reporter's stderr is easy to lose, its stdout is not.
 *
 * `onBegin` and not `onEnd`, and that is not cosmetic: Playwright wipes `outputDir`
 * before the first test, so a later check could report on a directory a previous
 * `--output` had already emptied, and a reviewer reading the summary at the bottom
 * of the run has by then scrolled past the top.
 */
export class StaleCaptureStoreReporter implements Reporter {
    onBegin(_config: FullConfig, _suite: Suite): void {
        reportStaleStore(committedStaleStorePaths(), (text) => {
            process.stdout.write(text);
        });
    }
}

export default StaleCaptureStoreReporter;

// Re-exported so a consumer can compare the two declarations of the legacy path
// without importing `store-paths.ts` itself; `LEGACY_CAPTURE_STORE_DIR` comes from
// the `.mjs` (plain ESM, shared with `scripts/ui-review-captures.mjs`) and
// `LEGACY_STORE_DIR` from `store-paths.ts`. The test asserts they are equal.
export { LEGACY_CAPTURE_STORE_DIR, LEGACY_STORE_DIR };