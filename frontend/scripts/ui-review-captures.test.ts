import { describe, expect, it } from 'vitest';
import { CAPTURE_STORE_DIR, PLAYWRIGHT_SCRATCH_DIR } from '../tests/screenshots/helpers/store-paths';
import { DEFAULT_DIR, parseArgs } from './ui-review-captures.mjs';

/**
 * The bridge between the capture-store path and the report that reads it.
 *
 * ## The gap this exists to close (F2)
 *
 * `scripts/ui-review-captures.mjs` restates the capture-store path so it can run
 * as a standalone ESM script, and its comment used to claim that the two copies
 * were "kept honest by `store-guard.spec.ts`". That test exists, and it does not
 * do that: it imports `CAPTURE_STORE_DIR` and asserts properties of the resolved
 * paths. Nothing in the repo ever read the `.mjs`, which is why
 * `tsc -b` could not have caught a divergence either — it does not type-check a
 * string literal inside plain ESM.
 *
 * The consequence was not a compile error, it was a **green suite reporting on
 * the wrong directory**: with `DEFAULT_DIR` aimed at a path that does not exist,
 * the entire screenshot suite and every E2E spec still passed, and the band-count
 * report — the one artefact §7's fix loop depends on for its Δ — described a
 * store that was never written.
 *
 * ## Why vitest and not a Playwright spec
 *
 * The same pattern is already established for the sibling guard:
 * `scripts/po-catalog.test.ts` unit-tests `scripts/po-catalog.mjs`, and
 * `vitest.config.ts` includes every `test.ts` under `scripts/`. So this needs no
 * new wiring, it runs in `pnpm test:run` — the gate that runs on every change —
 * and it needs neither a dev server nor a browser. A Playwright spec would be a
 * heavier home for a string comparison and would only run in the E2E suite.
 *
 * ## What it does and does not prove
 *
 * It proves the two values are the same string AND that the script actually uses
 * its own constant as the default (the second assertion is what stops the
 * "declared but shadowed by a third literal" variant). It does not prove the
 * store is where the captures land — that is `store-guard.spec.ts`, and the two
 * are complementary: one pins the paths' properties, this one pins the
 * agreement between the module and the script that reports on it.
 */
describe('the capture-report script and the capture-store module name the same directory', () => {
    it('uses the same store path as CAPTURE_STORE_DIR', () => {
        expect(DEFAULT_DIR).toBe(CAPTURE_STORE_DIR);
    });

    it('really defaults to that constant, not to a second literal', () => {
        // Without this, a script could export the right constant and still parse
        // its arguments against a different one — the declaration would look
        // maintained while the behaviour was not.
        expect(parseArgs([]).dir).toBe(DEFAULT_DIR);
    });

    it('reports on the store, not on Playwright scratch space', () => {
        // The failure F2 measured was a store pointed INTO `test-results/`, which
        // is the one directory Playwright wipes before the first test. Assert the
        // relationship rather than restate the rule, so a future move of either
        // path is caught here too.
        expect(DEFAULT_DIR).not.toBe(PLAYWRIGHT_SCRATCH_DIR);
        expect(DEFAULT_DIR.startsWith('test-results/')).toBe(false);
    });

    it('an explicit --dir still wins over the default', () => {
        // Otherwise the fix could be "hardcode the store everywhere", which would
        // make the equality above pass for the wrong reason.
        expect(parseArgs(['--dir', 'somewhere-else']).dir).toBe('somewhere-else');
    });
});
