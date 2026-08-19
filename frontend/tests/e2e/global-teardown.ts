import { purgeAllE2EArtifacts } from './helpers/admin-data';

/**
 * Playwright global teardown: guarantees a clean slate for the next E2E run by
 * purging every E2E artifact (templates, events, teams, categories, mandants)
 * left in the dev database. Runs once, serially, after all tests.
 *
 * Best-effort: a missing stack or an already-removed row must not fail the
 * suite, so every error is logged and swallowed.
 */
async function globalTeardown() {
    try {
        await purgeAllE2EArtifacts();
        console.log('[e2e-hygiene] purged all E2E artifacts');
    } catch (error) {
        console.warn('[e2e-hygiene] global teardown purge failed:', error);
    }
}

export default globalTeardown;
export { globalTeardown };
