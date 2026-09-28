import type { FullConfig } from '@playwright/test';
import { assertCaptureRootOutsidePlaywrightOutput } from './helpers/capture-store';
import { CAPTURE_STORE_DIR, captureStoreRoot } from './helpers/store-paths';

/**
 * The capture store's residual precondition, against the RESOLVED config.
 *
 * ## What this is worth, stated plainly
 *
 * **Not the protection.** The protection is the path: the store is
 * `test-artifacts/ui-review/`, outside `test-results/` entirely, so
 * `pnpm test:screenshots --output=test-results` has nothing to delete. This hook
 * exists for a flag aimed AT the store.
 *
 * ## And it is measurably the WEAKER of the two checks
 *
 * Playwright wipes the resolved `outputDir` recursively BEFORE `globalSetup`
 * runs. MEASURED: while the store was still a sibling inside `test-results/`, a
 * run with `--output=test-results` took it from 192 PNG to 0 and this guard
 * fired only afterwards, announcing the loss. The check that actually refuses in
 * time lives at config-load time in `playwright.screenshots.config.ts`, because
 * the config file is evaluated before Playwright knows what to delete.
 *
 * So why keep this one? `outputDir` is resolved PER PROJECT here
 * (`configCLIOverrides.outputDir` first, then the project's, then the config's),
 * and a project may pin its own — which the config file cannot see. It is the
 * cheap half of a two-place check, not the load-bearing half.
 *
 * `store-guard.spec.ts` tests the guard function itself, including the
 * `--output=test-results` case, and pins the store/scratch paths against
 * `store-paths.ts` so a future move cannot quietly undo the protection.
 */
function globalSetup(config: FullConfig): void {
    for (const project of config.projects) {
        assertCaptureRootOutsidePlaywrightOutput(captureStoreRoot(), project.outputDir);
    }
}

export default globalSetup;
export { globalSetup, CAPTURE_STORE_DIR };
