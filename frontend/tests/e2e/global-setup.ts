import { acquirePrimaryMandantLogoLock, resetPrimaryMandantLogoForRun } from './helpers/admin-data';

/**
 * Playwright global setup: clears state that a previous run may have STRANDED
 * before a single test of this run starts.
 *
 * The mirror image of `global-teardown.ts`. The teardown only runs when a run
 * ends cleanly, so a hard kill (SIGKILL, CI timeout, closed laptop) between
 * `admin-mandant.spec.ts`'s logo upload and its removal leaves the primary
 * mandant with that logo forever, and the next run's `portal.spec.ts`
 * "static fallback logo" assertion, the "Kein Bild hinterlegt." empty state and
 * the screenshot suite's documented baseline all fail on inherited state.
 * Resetting here — at the RUN boundary, as a fixture step and not inside any
 * assertion — closes that hole without making the assertion self-fulfilling:
 * within a run the two real writers still take turns on
 * `acquirePrimaryMandantLogoLock()`, so "no logo" keeps testing "the upload
 * window was not active".
 *
 * The lock is taken for the same reason: this is a THIRD writer of that one
 * shared row, and the only case it can collide with is a second `playwright
 * test` process on the same machine and dev database whose upload test is
 * inside its critical section. Locking turns that into "wait a few seconds"
 * instead of "delete the logo out from under that run".
 *
 * Best-effort, like the teardown: a missing stack must not fail a run that
 * would otherwise report on its own merits, so every error is logged and
 * swallowed. A failed reset degrades to exactly today's behaviour — the
 * assertion then fails loudly on the inherited logo instead of silently
 * passing.
 */
async function globalSetup() {
    // Un-annotated on purpose: this directory is linted with the PLAIN-JS
    // parser (`eslint.config.js` gives `tests/e2e/**` no TS parser), so a type
    // annotation here would be a parse error. Same reason `logoLockIsOurs()`
    // in the helpers takes no parameter. `undefined` is the "lock never
    // acquired" sentinel for the `finally` below.
    let releaseLogoLock;
    console.log('[e2e-hygiene] global setup: clearing stranded state before the run');
    try {
        releaseLogoLock = await acquirePrimaryMandantLogoLock();
        const removed = await resetPrimaryMandantLogoForRun();
        if (removed) {
            console.log('[e2e-hygiene] removed a stranded primary mandant logo left by an earlier run');
        }
    } catch (error) {
        console.warn('[e2e-hygiene] global setup logo reset failed:', error);
    } finally {
        if (releaseLogoLock !== undefined) {
            releaseLogoLock();
        }
    }
}

export default globalSetup;
export { globalSetup };
