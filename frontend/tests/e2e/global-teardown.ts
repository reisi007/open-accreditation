import { PurgeReclamationFailure, purgeAllE2EArtifacts } from './helpers/admin-data';

/**
 * Playwright global teardown: guarantees a clean slate for the next E2E run by
 * purging every E2E artifact (templates, images, categories, events, teams,
 * venues, mandants) left in the dev database. Runs once, serially, after all
 * tests.
 *
 * ## Two failure classes, and only one of them is swallowed
 *
 * The previous version wrapped everything in `try { … } catch { console.warn }`,
 * with the docblock's "a missing stack … must not fail the suite" read as a
 * blanket. F1 measured what that blanket actually cost: the purge matched 53
 * `E2E Heimstadion *` venues, every DELETE came back **409** because a leaked
 * team still referenced them, and the run reported success. One row per run, in
 * perpetuity, with no signal — which is the exact failure this teardown exists
 * to prevent, announced as a clean run.
 *
 * So the classes are separated, and the separator is a TYPE, not a string match
 * on the message:
 *
 * - **Infrastructure** — the stack is down, the login failed, no mandant
 *   exists. Warned. A run must not go red for a database that is not there; the
 *   functional results it just produced are still worth reporting.
 * - **Reclamation** — the purge MATCHED a row and could not delete it. Propagated,
 *   so the run is RED. Every such row is named in the message. This is the
 *   accumulation failure, and a green run that leaked is a lie.
 *
 * The distinction is `instanceof PurgeReclamationFailure`; the alternative —
 * refusing to fail a run for a down backend — is preserved exactly, and no louder.
 */
async function globalTeardown() {
    // The measurement escape hatch, and it is LOUD on purpose.
    //
    // `scripts/e2e-per-spec-leaks.mjs` has to measure what a SPEC leaves, not what
    // a RUN leaves. This sweep reclaims every `E2E %` row by name prefix at the end
    // of the run, so with it on both arms of that measurement read zero and the
    // difference between "the spec cleaned up" and "the sweep cleaned up
    // afterwards" is invisible — which is the whole attribution the exercise is
    // for. `E2E_PURGE=off` switches the serial net OFF so the delta is the spec's
    // own, and says so in the log rather than passing for a clean run.
    //
    // Never set in CI. The default is ON, and a sweep that could be switched off
    // without a trace would be the very invisibility this file's own F1 notes
    // are about.
    if (process.env.E2E_PURGE === 'off') {
        console.warn('[e2e-hygiene] E2E_PURGE=off — MEASUREMENT ARM, the serial purge did NOT run');
        return;
    }

    try {
        await purgeAllE2EArtifacts();
        console.log('[e2e-hygiene] purged all E2E artifacts');
    } catch (error) {
        if (error instanceof PurgeReclamationFailure) {
            // Deliberate: rethrown so the run is red. Swallowing this is what
            // turned 53 refused deletions into a clean report.
            throw error;
        }
        console.warn('[e2e-hygiene] global teardown purge failed:', error);
    }
}

export default globalTeardown;
export { globalTeardown };
