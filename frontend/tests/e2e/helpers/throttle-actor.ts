/**
 * The throttle-actor header, i.e. "which test actor am I?" — position 49.
 *
 * ## Why this exists
 *
 * The backend's ip-keyed rate-limit buckets (`login`, `register`, `activate`,
 * `public`, `verify` — `backend/app/Providers/AppServiceProvider.php`) count per
 * client ip. An E2E run has ONE ip no matter how many workers it uses, so every
 * worker spends from the same counter: the shared-bucket problem is a function
 * of the ip, and no worker count changes it (skill `playwright-parallel`,
 * „IP-based throttle via worker count“). Fewer workers only lowers the spike, and
 * a Playwright lock does not help at all — a lock serialises, it does not
 * throttle, so the sequential lane reaches the same requests-per-minute, only
 * slower. The prescribed fix is a throttle key per test actor.
 *
 * The backend honours the header in `local` and `testing` ONLY, where it
 * appends the actor to the key (`login:10.0.0.1` → `login:10.0.0.1@w2-p4821`).
 * In every other environment the header does not REACH the key at all: it stays
 * the plain per-ip string, so production brute-force semantics are untouched.
 * (Whether the provider even opens the header bag there is an implementation
 * detail no test pins — measured 2026-10-04, reading it before the environment
 * gate leaves the suite green. The gate is the property, and it is pinned.)
 * There is no credential in here: the value names a worker, and it is only ever
 * a bucket NAME on the other side.
 *
 * ## What it covers — and what it does not
 *
 * It is attached wherever the E2E harness builds its own API request context
 * (`request.newContext({ extraHTTPHeaders: throttleActorHeaders() })`), which is
 * where the login bursts come from. That is now BOTH kinds of call site, not just
 * the helper ones: MEASURED 2026-10-04, 18 contexts across 9 files, every one of
 * them wired — 7 in helper modules (`helpers/admin-data.ts` ×5, `api-session.ts`,
 * `ownership.ts` — `loginAdminApi()` and the fixture creators) and 11 that specs
 * build inline (`account-deletion` ×2, `admin-sub-resend` ×2, `auth` ×2,
 * `profile` ×3, `admin-users`, `ownership`). `throttle-actor.test.ts` reads those
 * sources and fails if a new context appears unwired — which is exactly what let
 * the previous version of this paragraph go stale.
 *
 * That scan walks `tests/e2e` RECURSIVELY (47 files measured 2026-10-04) rather
 * than two hand-listed directories, because two hand-listed directories were
 * blind: `ownership-probe/` sits beside `helpers/` and was never read, so a new
 * unwired context in one of its five files passed. Its own text now says so, with
 * the measured counter-check.
 *
 * Deliberately NOT covered — each with its reason, so neither reads as an
 * oversight:
 *
 * - **The ui-review screenshot harness' OWN request context**
 *   (`tests/screenshots/helpers/dataset.ts:465` — 1 context, no header). A
 *   separate Playwright config, and what flows through that context is a
 *   find-or-create of the STABLE review addresses. There are SEVEN of them
 *   (`REVIEW_USER_EMAILS`: reviewer, applicant, apply, freigaben, empty,
 *   druck-1, druck-2), so a run sends **7** `login` POSTs in the steady state
 *   and **14** against a cold database — each fresh address costs the failed
 *   login plus the one after activation. COUNTED 2026-10-04, and the previous
 *   number in this bullet ("one or two per run", "ONE stable address") was
 *   wrong on both counts; the conclusion it carried is unchanged, because 14 is
 *   still far below the 40/min `login` floor. The count is `withReviewUser`'s
 *   invocations on the build path, and the dataset is built ONCE per run
 *   (`dataset.ts` memoises per process and hands the record to the other
 *   workers), so 7 is per run, not per capture.
 *   Two neighbours are deliberately NOT part of this exclusion, so they cannot be
 *   counted against it: the harness' ADMIN logins run through `loginAdminApi()`,
 *   which wires the header exactly like every other spec, and each admin/user
 *   capture logs in through the application's own form (`loginViaUi`, once per
 *   capture) — that one spends from the same shared per-ip `login` bucket. What
 *   covers all of it is the backend's env-dependent floor, not a per-capture
 *   split: `login`/`register` at 40/30 and `public`/`verify` at 300/300 per min
 *   in `local`/`testing` (`AppServiceProvider.php:128,129,159,171` — raised for
 *   exactly this harness). A test-only header has no place in a harness whose job
 *   is to capture what a browser sees.
 * - **Browser contexts** (`browser.newContext` — 4 sites in `a11y.spec.ts` and
 *   `admin-mobile-layout.spec.ts`). Those specs log in through the application's
 *   own login FORM (`getByLabel('E-Mail')` → `Anmelden`, measured at
 *   `a11y.spec.ts:147-150` / `admin-mobile-layout.spec.ts:84-87`), so the `login`
 *   hit belongs to the app, not to a context the harness builds. Covering it means
 *   `extraHTTPHeaders` on `browser.newContext` — a different mechanism, not a
 *   missed call site. The scan in `throttle-actor.test.ts` cannot even mistake
 *   one for the other: `browser.newContext` is not `request.newContext` (4 sites,
 *   0 counted).
 *
 * Browser traffic therefore still spends from the shared per-ip bucket, which is
 * what the backend's `local`/`testing` headroom is there for.
 */

/**
 * Must match `AppServiceProvider::TEST_ACTOR_HEADER`. It is a test-only header:
 * the backend lets it into the key in `local`/`testing` and nowhere else.
 */
export const THROTTLE_ACTOR_HEADER = 'X-Test-Actor';

/**
 * The actor id of the CURRENT worker: `w<workerIndex>-p<pid>`, or `p<pid>` where
 * Playwright exposes no worker index (the global-teardown process).
 *
 * Why the worker index AND the pid — the same reason `admin-data.ts`'s
 * `PORTAL_FIXTURE_KEY` carries both, and the reasoning is worth keeping in one
 * place: `TEST_WORKER_INDEX` restarts at 0 in every host process, so two
 * CONCURRENT `playwright test` runs on one machine (the screenshot suite beside
 * an E2E run, or two shells) both mint `w0` and would land in the SAME throttle
 * bucket — re-creating the shared-counter problem the actor key exists to
 * remove. The pid makes the id unique per (run, worker) pair.
 *
 * The shape is deliberately within the backend's accepted alphabet
 * (`[A-Za-z0-9._-]`, max 32 chars — `AppServiceProvider::TEST_ACTOR_PATTERN`); a
 * value outside it is DISCARDED by the backend and the request falls back to the
 * shared per-ip bucket, i.e. silently back to the old behaviour.
 */
export function throttleActorId() {
    const workerIndex = process.env.TEST_WORKER_INDEX;
    return workerIndex === undefined || workerIndex === ''
        ? `p${process.pid}`
        : `w${workerIndex}-p${process.pid}`;
}

/**
 * The `extraHTTPHeaders` VALUE for a helper-owned `request.newContext()`:
 *
 *     request.newContext({ baseURL, extraHTTPHeaders: throttleActorHeaders() })
 *
 * The `extraHTTPHeaders:` KEY is part of Playwright's option name and MUST be
 * written out at the call site. Spreading this function's result straight into
 * the options object (`{ baseURL, ...throttleActorHeaders() }`) looks
 * equivalent and sends NOTHING: it produces a top-level `X-Test-Actor` option
 * that Playwright silently ignores. Measured 2026-10-04 with Playwright 1.63
 * against an echo server — the request arrived with `user-agent`,
 * `accept`, `accept-encoding`, `host`, `connection` and no `x-test-actor`.
 * That is the failure mode this comment exists for: a wiring that compiles,
 * lints, type-checks and looks right while doing nothing.
 *
 * JSDoc, not an annotation: `tests/e2e/**` is linted with the plain-ES2020
 * parser on purpose, and a type annotation in this directory is a parse error
 * (see `eslint.config.js`).
 *
 * @returns {{[key: string]: string}}
 */
export function throttleActorHeaders() {
    return { [THROTTLE_ACTOR_HEADER]: throttleActorId() };
}