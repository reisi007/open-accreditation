import { request } from '@playwright/test';
import { throttleActorHeaders } from './throttle-actor';

/**
 * The E2E API session primitives, in a module of their own.
 *
 * ## Why this file exists at all: an import cycle, measured
 *
 * `helpers/admin-data.ts` holds the fixture creators, and each of them registers
 * the row it created with `helpers/ownership.ts`. The ownership teardown in turn
 * needs an admin session and the failure class — both of which lived in
 * `admin-data.ts`. So each would import the other.
 *
 * That cycle happens to work today (ESM live bindings, and neither module
 * touches the other at module scope), but "happens to work" is exactly the
 * property that breaks on the next edit and looks like a mystery then. These
 * three primitives have no dependency on either module, so they move here and
 * the graph becomes a DAG:
 *
 *     api-session ──► admin-data ──► ownership
 *          └────────────────────────►  ▲
 *
 * `admin-data.ts` re-exports all three, so every existing import in the specs
 * (`FRONTEND_BASE_URL`, `loginAdminApi`, `PurgeReclamationFailure`) keeps
 * working untouched — the move is invisible to callers, which is the point.
 */
export { PurgeReclamationFailure } from './purge-failure';

/**
 * Single source of truth for the origin the E2E **API helpers** drive.
 *
 * The browser navigates via `use.baseURL` in `playwright.config.ts`, which reads
 * the same `E2E_BASE_URL` with the same default. These two MUST agree: a helper
 * that hardcoded `http://localhost:5173` while the browser ran against
 * `E2E_BASE_URL=http://localhost:4173` would drive the browser against one stack
 * and set up its fixtures against ANOTHER — the symptom is a suite that fails
 * with "element not found" for reasons no assertion can explain.
 *
 * `playwright.config.ts` deliberately does NOT import this module (a Playwright
 * config must not pull in test helpers), so the expression is mirrored there.
 * Change one side, change the other.
 */
export const FRONTEND_BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173';

/**
 * Logs the bootstrap admin in via the API and returns a request context that
 * carries the session cookie for subsequent admin API calls.
 *
 * The context carries the throttle-actor header (position 49): the `login`
 * limiter is keyed per client ip, and every worker of a run shares one ip, so
 * without it N workers share one bucket and a login burst 429s — a cluster of
 * identical failures that looks like flakiness. The backend reads the header in
 * `local`/`testing` only; production keys stay plain per-ip. See
 * `helpers/throttle-actor.ts`.
 *
 * @returns {Promise<import('@playwright/test').APIRequestContext>}
 */
export async function loginAdminApi() {
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    const login = await api.post('/api/auth/login', { data: { email: 'admin@example.com', password: 'admin' } });
    if (login.status() !== 200) {
        await api.dispose();
        throw new Error(`Admin API login failed with status ${login.status()}`);
    }
    return api;
}
