import { expect, test } from '@playwright/test';
import path from 'node:path';
import { assertCaptureRootOutsidePlaywrightOutput } from './helpers/capture-store';
import { CAPTURE_STORE_DIR, PLAYWRIGHT_SCRATCH_DIR } from './helpers/store-paths';

/**
 * The capture store's structural precondition — and, deliberately, a statement of
 * which part of it is a PATH and which part is a check.
 *
 * AGENTS.md §7's fix loop needs the "old" half of every comparison to survive a
 * re-capture. Three generations of this harness, each of which looked sufficient:
 *
 *   1. store inside `outputDir`        → partial re-capture: **126 → 11 PNG**
 *   2. store as a SIBLING of it        → `--output=test-results`: **192 → 0**
 *   3. store OUTSIDE `test-results/`    → the runner has nothing to delete
 *
 * So the assertions below come in two kinds, and the file says which is which.
 * The first test is the one that matters: it would still hold if the guard were
 * deleted, because it is a property of the paths. The rest test the guard, which
 * covers only what is left — a flag aimed at the store on purpose.
 *
 * Browser-free, fixture-free: a precondition of the run, not a capture, and it
 * must not depend on the dataset lock or on a dev server.
 */
test.describe('capture store precondition', () => {
    test('THE PROTECTION: the store is outside test-results/ entirely, by path', () => {
        // Not "not inside outputDir" — outside the whole tree, which is the only
        // statement that survives `--output=<anything under test-results>`.
        expect(CAPTURE_STORE_DIR.split('/')[0]).not.toBe('test-results');
        expect(PLAYWRIGHT_SCRATCH_DIR.split('/')[0]).toBe('test-results');

        // And the committed pair passes the guard for the structural reason, not
        // because the guard is lenient about siblings.
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput(
                path.resolve(process.cwd(), CAPTURE_STORE_DIR),
                path.resolve(process.cwd(), PLAYWRIGHT_SCRATCH_DIR),
            ),
        ).not.toThrow();
    });

    test('THE ACCEPTANCE CASE: --output=test-results cannot reach the store', () => {
        // The reproduction that measured 192 → 0, re-checked against the new
        // path. This is the scenario §7's fix loop actually depends on.
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput(
                path.resolve(process.cwd(), CAPTURE_STORE_DIR),
                path.resolve(process.cwd(), 'test-results'),
            ),
        ).not.toThrow();
    });

    test('the guard still refuses a --output aimed AT the store', () => {
        // What the guard is for now: intent, not default. Two shapes —
        // `--output=test-artifacts` (the store's parent) and `--output=.` (which
        // contains everything).
        for (const aimed of ['test-artifacts', '.', 'test-artifacts/ui-review']) {
            expect(
                () =>
                    assertCaptureRootOutsidePlaywrightOutput(
                        path.resolve(process.cwd(), CAPTURE_STORE_DIR),
                        path.resolve(process.cwd(), aimed),
                    ),
                `--output=${aimed} must be refused`,
            ).toThrow(/inside the Playwright outputDir/);
        }
    });

    test('an identical outputDir is refused (the degenerate case of the same bug)', () => {
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput(
                path.resolve(process.cwd(), CAPTURE_STORE_DIR),
                path.resolve(process.cwd(), CAPTURE_STORE_DIR),
            ),
        ).toThrow();
    });

    test('a store outside the scratch space is accepted, however far away', () => {
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput('/tmp/anywhere/captures', '/var/tmp/playwright-scratch'),
        ).not.toThrow();
    });

    test('a SIBLING with a shared name prefix is accepted', () => {
        // The distinction the old docblock got wrong: `ui-review` and
        // `ui-review-scratch` share a prefix but neither contains the other, and
        // prefix-sharing is not the hazard — containment is.
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput('/w/test-artifacts/ui-review', '/w/test-artifacts/ui-review-scratch'),
        ).not.toThrow();
    });

    test('the guard is not vacuous: the same check on the OLD path fails', () => {
        // Non-negotiable: without this, "the new path passes" could mean "the
        // check does nothing". The old store path, against the same guard, must
        // be refused — that is the 192 → 0 measurement, still enforced.
        expect(() =>
            assertCaptureRootOutsidePlaywrightOutput(
                path.resolve(process.cwd(), 'test-results/ui-review'),
                path.resolve(process.cwd(), 'test-results'),
            ),
        ).toThrow(/inside the Playwright outputDir/);
    });
});
