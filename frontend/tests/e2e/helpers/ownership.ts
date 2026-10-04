import { FRONTEND_BASE_URL, loginAdminApi } from './api-session';
import { PurgeReclamationFailure } from './purge-failure';
import { throttleActorHeaders } from './throttle-actor';

/**
 * ## The per-test OWNERSHIP ledger
 *
 * A test creates rows. Somebody has to give them back. Until now that "somebody"
 * was the serial `globalTeardown` in `admin-data.ts`, which reclaims by NAME
 * PREFIX at the END of a run — and a name prefix can only find what a previous
 * run *named*. Three things follow, and all three are measured:
 *
 * 1. **A crashed run keeps its rows until the next run's teardown.** The prefix
 *    sweep does eventually reclaim them, but the run in between inherits them —
 *    and the ui-review band count is measured *between* runs.
 * 2. **A test cannot know whether it leaked.** F1's 54 teams and 53 venues were
 *    invisible to the specs that made them, because no spec held an id.
 * 3. **The prefix sweep cannot be the only net.** The measured residue after a
 *    full run was 758 `users` and 116 `user_media` rows — kinds the sweep does
 *    not name at all (a user has no name marker; its display name repeats).
 *
 * So the ledger below is the FIRST net: every fixture registers its id the
 * moment it exists, and the test's own `afterEach` gives it back. The serial
 * teardown stays as the net for a run that was KILLED before its `afterEach`
 * could run — that is a different failure and it needs a different net.
 *
 * ## Why this file has no parameters, and how it manages types anyway
 *
 * `tests/e2e/**` is linted with the PLAIN-JS parser (ES2020, no TS syntax) but
 * is built by the strict `tsc -b`. MEASURED, all four shapes:
 *
 * | shape                                   | eslint | tsc   |
 * |-----------------------------------------|--------|-------|
 * | `function f(id: number)`                | PARSE ERROR | —  |
 * | `function f(id)`                        | ok     | TS7006 implicit any |
 * | `function f(...ids)`                    | ok     | TS7019 implicit any[] |
 * | `function f(id = 0)`                    | ok     | ok    |
 *
 * A DEFAULT VALUE is what makes this work: it is ordinary ES2020 (so the plain
 * parser accepts it) and it gives `tsc` the parameter's type by inference. That
 * is the only shape available here, so it is the one used — and it is why every
 * parameter below has a default rather than a type annotation.
 *
 * ## Why the FK order is DATA, and what "order" means here
 *
 * `E2E_OWNED_TEARDOWN` is an ordered list, and the order is a correctness
 * requirement rather than a style choice:
 *
 * - `events.venue_id` and `teams.venue_id` are `restrictOnDelete`: the backend
 *   answers **409** while a team or an event still points at a venue.
 * - `accreditations.event_id` is `nullOnDelete`: deleting the event does NOT take
 *   the accreditation with it, so the accreditation must be dealt with on its
 *   own terms.
 * - `accreditations.category_id` is `cascadeOnDelete`: deleting the category
 *   DOES take it.
 *
 * Which is exactly why the list puts **categories before events** — the same
 * conclusion `E2E_PURGE_SWEEPS` reaches, for the same reason, and asserted by a
 * test in `namespace-isolation.spec.ts` rather than by a comment here.
 *
 * The order lives in data because a hand-written order with no test is exactly
 * the thing that rots silently. `E2E_OWNED_FK_EDGES` states the constraint graph
 * the list has to satisfy, and the test checks the list against the graph.
 */

/**
 * The rows one test owns, keyed by kind. A module-scope `Map` rather than an
 * instance because this directory forbids constructor parameters, and because a
 * Playwright worker runs ONE test at a time — so "reset in `beforeEach`,
 * reclaim in `afterEach`" gives exactly the per-test instance the shape would
 * have provided.
 *
 * Entry shape: `{ id, parentId }`, plus `email`/`password` for the OWNER-scoped
 * kinds (`userMedia`), whose DELETE route only answers for the owning account.
 */
const E2E_OWNED = new Map();

/**
 * The teardown plan, in the order the rows must be given back.
 *
 * Each entry: `kind` (the `E2E_OWNED` key), `route` (the API path; the literal
 * `{parentId}` is substituted per row), `actor` (`'admin'` = the bootstrap admin
 * session, `'owner'` = log in as the row's own user), `okStatus` (the success
 * status THIS route answers — they are not uniform, see below), `reclaimable`
 * (false for a kind with NO delete route at all — see the users note below), and
 * `creatableHere` (false for a kind the ledger-walk test cannot create a fixture
 * for — each such entry carries its reason inline, and
 * `namespace-isolation.spec.ts` pins the list by name so the set cannot grow
 * silently).
 *
 * ## The claim this table does NOT make
 *
 * "A kind in this plan is covered from the day it is entered" is true for the
 * ROUTE of every entry — `preflightTeardownRoutes` asks the backend about all of
 * them on every run, including the ones no test ever exercises — and FALSE for
 * the create-and-verify round trip of four of them (MEASURED 2026-09-28:
 * `subAccreditations`, `venues`, `badgeImages`, `mandants`, plus the two
 * mandant-scoped steps the walk skips structurally). Those carry
 * `creatableHere: false` with a reason, and that flag is the honest form of the
 * gap. Before it existed the walk test asserted `toBeGreaterThanOrEqual(5)`, the
 * count went from seven to five, and the floor stayed satisfied — so a plan entry
 * nobody verified looked exactly like one somebody did.
 *
 * ## `okStatus` is per kind, and that is measured, not stylistic
 *
 * MEASURED, one row of each kind against the running dev backend:
 * `DELETE /api/admin/{accreditations,events,categories,sub-accreditations,
 * blacklists,badge-templates,badge-images,venues,mandants}` and the two
 * mandant-scoped team/domain routes all answer **204**; the two applicant
 * withdraw routes answer **204**; and `DELETE /api/user/media/{id}` answers
 * **200** with a `{message}` body, because `UserMediaController::destroy` returns
 * a `JsonResponse` rather than `response()->noContent()`.
 *
 * That difference was found by the teardown itself, loudly: a hard-coded "204
 * everywhere" failed a PASSING `profile.spec.ts` with "DELETE answered 200
 * (expected 204/404) — the row stays" — a false accusation about a delete that had
 * in fact worked. The expectation now lives next to the route it describes,
 * which is the only place it can be kept true.
 *
 * 404 is tolerated for EVERY kind — but only the 404 that means THE ROW IS GONE
 * (a row another net already reclaimed, or a cascade that took a child with its
 * parent). That distinction is not a nicety: a plan entry with a typo answers
 * 404 too, and tolerating it silently is how the suite stayed green while
 * `mandants` grew by one row per run. `classifyNotFoundBody` reads the two
 * apart; a 404 that is neither is a failure.
 * 409 is NOT tolerated anywhere — that is the backend saying "something still
 * references this", which is precisely the ordering bug this must surface.
 */
export const E2E_OWNED_TEARDOWN = [
    // ── children first ──────────────────────────────────────────────────────
    // An `application` is only withdrawable while it is still `requested`; a
    // DECIDED one answers 422 on the owner route (MEASURED 2026-10-01 against
    // the running dev backend, both bodies are listed in
    // `TEARDOWN_DECIDED_MESSAGES`). What takes a decided row is the CASCADE —
    // `applications.accreditation_id` and `applications.user_id` are both
    // `cascade` in `E2E_OWNED_FK_EDGES`, and both parents are registered by
    // the helpers that create the application.
    //
    // So the 422 is a HAND-OVER, not a failure — and `decidedStatus` +
    // `verifiedBy` are what make that a measured claim instead of the tolerance
    // this file exists to remove: the row is remembered as CARRIED, and
    // `verifyCarriedRows` proves after the plan has run that the cascade really
    // took it. Count a 422 as reclaimed without that proof and this is exactly
    // the F1 shape — a cleanup that reports success while the row stays.
    { kind: 'applications', route: '/api/applications', actor: 'owner', okStatus: 204, reclaimable: true, decidedStatus: 422, verifiedBy: '/api/admin/applications' },
    { kind: 'subApplications', route: '/api/sub-applications', actor: 'owner', okStatus: 204, reclaimable: true, decidedStatus: 422, verifiedBy: '/api/admin/sub-applications' },
    { kind: 'userMedia', route: '/api/user/media', actor: 'owner', okStatus: 200, reclaimable: true },
    { kind: 'subAccreditations', route: '/api/admin/sub-accreditations', actor: 'admin', okStatus: 204, reclaimable: true, creatableHere: false },
    { kind: 'accreditations', route: '/api/admin/accreditations', actor: 'admin', okStatus: 204, reclaimable: true },
    // ── categories BEFORE events (see the module docblock) ──────────────────
    { kind: 'categories', route: '/api/admin/categories', actor: 'admin', okStatus: 204, reclaimable: true },
    { kind: 'events', route: '/api/admin/events', actor: 'admin', okStatus: 204, reclaimable: true },
    { kind: 'teams', route: '/api/admin/mandants/{parentId}/teams', actor: 'admin', okStatus: 204, reclaimable: true },
    { kind: 'venues', route: '/api/admin/venues', actor: 'admin', okStatus: 204, reclaimable: true, creatableHere: false },
    { kind: 'blacklists', route: '/api/admin/blacklists', actor: 'admin', okStatus: 204, reclaimable: true },
    { kind: 'badgeImages', route: '/api/admin/badge-images', actor: 'admin', okStatus: 204, reclaimable: true, creatableHere: false },
    { kind: 'badgeTemplates', route: '/api/admin/badge-templates', actor: 'admin', okStatus: 204, reclaimable: true },
    // ── accounts LAST of the mandant-scoped kinds, and the reason is NOT the FK graph
    //
    // `users.mandant_id` is `nullOnDelete`, so a user SURVIVES the mandant
    // delete: the order test in `namespace-isolation.spec.ts` (written against
    // `E2E_OWNED_FK_EDGES`) is what pinned `users` before `mandants`, and this
    // position satisfies that.
    //
    // The binding constraint is the one the FK graph CANNOT see: the three
    // owner-scoped steps above log in AS the user they delete rows for
    // (`loginAsUser`), and a deleted account answers 401 to that login. MEASURED
    // — with `users` first, `profile.spec.ts` failed with "the ownership
    // teardown could not log in as profile-media-… (status 401); its rows
    // cannot be given back", i.e. the teardown was UNABLE to return the very
    // rows it was asked to return. So the account must outlive every kind that
    // needs it to still exist, and the graph's `cascade` edges say nothing about
    // that: they are free in both directions as far as the DATABASE is concerned,
    // and not free at all as far as the SESSIONS are.
    //
    // Two further notes:
    //  - the handle is an EMAIL, not a numeric id: `POST /api/auth/register`
    //    answers a bare `{message}`, so registration cannot register an id.
    //    `resolveIdBy` turns the email into the id through the admin list, which
    //    is also what makes a self-deleting test self-cleaning: the account the
    //    teardown looks for is already gone, and a gone account is the goal state.
    //  - `okStatus: 200`, not 204: `UserController::destroy` returns a
    //    `JsonResponse` with the deletion summary, like `UserMediaController`.
    //
    // `creatableHere: false` — NOT because the route is unproven: the preflight
    // probes it on every run, and the specs that register real users
    // (`auth.spec.ts`, `admin-users.spec.ts`, `account-deletion.spec.ts`)
    // exercise create → delete. It is because THIS walk is id-based end to end
    // (`rememberOwnedRow(step.kind, row.id)` straight off the create response)
    // and a user's create answers no id at all — the handle is an email, and
    // turning it back into an id is precisely the resolver the teardown itself
    // uses. A walk that reused the resolver would be checking the resolver
    // against itself, not checking a route.
    { kind: 'users', route: '/api/admin/users', actor: 'admin', okStatus: 200, reclaimable: true, resolveIdBy: 'email', creatableHere: false },
    { kind: 'mandantDomains', route: '/api/admin/mandants/{parentId}/domains', actor: 'admin', okStatus: 204, reclaimable: true },
    { kind: 'mandants', route: '/api/admin/mandants', actor: 'admin', okStatus: 204, reclaimable: true, creatableHere: false },
];

/**
 * ## Two 404s that are not the same 404
 *
 * 404 is tolerated for every kind on purpose — a row another net already
 * reclaimed, or a cascade that took a child with its parent, is the goal state
 * and not a fault. But that tolerance was TOTAL, and a total tolerance cannot
 * tell a wrong address from a gone row. One character typed into
 * `/api/admin/mandants` — `/api/admin/mandat` — makes the teardown's DELETE hit
 * no route at all, answer 404, get counted as "reclaimed", and leave the row
 * behind. MEASURED with that typo in place: the suite stayed **green** (3 + 29
 * + 3 passed) while `mandants` grew by one row per run, without bound.
 *
 * The two cases ARE distinguishable, and they are measured against the running
 * dev backend with an id that cannot exist. BOTH answer **404**; only the
 * `message` differs:
 *
 * | case                                              | `message`                                                     |
 * |---------------------------------------------------|---------------------------------------------------------------|
 * | row gone — the route exists, model binding failed | `No query results for model [App\Models\Category] 2147483647` |
 * | address wrong — no such route                     | `The route api/admin/mandat/2147483647 could not be found.`   |
 *
 * The two mandant-scoped steps are covered too: `{parentId}` is substituted with
 * `0`, so the MANDANT binding fails first and still yields the "row gone" shape.
 *
 * The verdict is read from `message` and never from the `file` field: that one
 * carries an ABSOLUTE path and would break the moment the checkout moves. The
 * `message` is framework text that survives `APP_DEBUG=0` — only the extra
 * fields are stripped there, not the message.
 */
export const TEARDOWN_NOT_FOUND = {
    ROW_ABSENT: 'row-absent',
    ROUTE_ABSENT: 'route-absent',
    UNKNOWN: 'unknown',
};

/**
 * The id the route preflight asks about. It cannot exist, which is the whole
 * safety argument for the preflight: every step's delete is a real `DELETE`
 * request, and the only way one of them could delete a row is if the backend
 * ignored the id — which is precisely what the preflight would then report.
 */
export const TEARDOWN_ROUTE_PROBE_ID = 2147483647;

/**
 * ## A 422 that means "not now" is not a 422 that means "gone"
 *
 * The two owner-scoped withdraw routes answer 422 for exactly one reason: the
 * row is DECIDED, and the applicant may no longer withdraw it
 * (`ApplicationController::destroy:68`, `SubApplicationController::destroy:60`).
 * A decided row is still reclaimable — by the CASCADE from the
 * `accreditation_id` / `user_id` it holds, both of which this same plan deletes.
 * So the 422 is a hand-over, and the honest ledger entry is "carried", not
 * "reclaimed" and not "failed".
 *
 * It is tolerated ONLY when the body is one of these two messages, and the list
 * is literal rather than a `startsWith`: the whole reason the 404 classifier
 * above exists is that a total tolerance cannot tell the intended answer from a
 * wrong one, and a substring rule on a German backend sentence is the same
 * mistake one layer down. MEASURED 2026-10-01, both bodies verbatim:
 *
 * | route                                  | body                                                 |
 * |----------------------------------------|------------------------------------------------------|
 * | `DELETE /api/applications/{id}`        | `Only pending (requested) applications can be withdrawn.` |
 * | `DELETE /api/sub-applications/{id}`    | `Only pending (requested) sub-applications can be withdrawn.` |
 *
 * `body` is `''` for a body that could not be read. That is `UNKNOWN`, and
 * `UNKNOWN` is a FAILURE downstream.
 */
export const TEARDOWN_DECIDED = {
    DECIDED: 'decided',
    UNKNOWN: 'unknown',
};

const TEARDOWN_DECIDED_MESSAGES = [
    'Only pending (requested) applications can be withdrawn.',
    'Only pending (requested) sub-applications can be withdrawn.',
];

/**
 * Which of the two this body is. Fail-closed by construction, and read from
 * the JSON `message` field and from NOTHING else, for the reason the 404
 * classifier reads only `message`: the debug payload carries an absolute path
 * and would break the moment the checkout moves.
 *
 * The verdict is a WHOLE-MESSAGE match, not a substring: a 422 that merely
 * mentions "pending" (a validation error, a throttle answering 422) is
 * `UNKNOWN`, and an `UNKNOWN` 422 fails the run.
 */
export function classifyNotWithdrawableBody(body = '') {
    let parsed = null;
    try {
        parsed = JSON.parse(body);
    } catch {
        return TEARDOWN_DECIDED.UNKNOWN;
    }
    if (parsed === null || typeof parsed !== 'object' || typeof parsed.message !== 'string') {
        return TEARDOWN_DECIDED.UNKNOWN;
    }
    for (const expected of TEARDOWN_DECIDED_MESSAGES) {
        if (parsed.message === expected) {
            return TEARDOWN_DECIDED.DECIDED;
        }
    }
    return TEARDOWN_DECIDED.UNKNOWN;
}

/**
 * Which kind of 404 this body is. Fail-closed by construction, in two places:
 *
 * 1. Anything that is neither of the two measured shapes is `unknown`, and
 *    `unknown` is a FAILURE downstream. A harness that tolerates a 404 it cannot
 *    classify is the shape this whole section exists to remove — it would be
 *    indistinguishable from the typo that motivated it.
 * 2. The verdict is read from the JSON `message` field and from NOTHING else —
 *    not from the whole body, not from the debug payload. MEASURED, and found by
 *    the test written for it: a body that carries the verdict in a field other
 *    than `message` must stay `unknown`, or the classifier is fail-OPEN.
 *
 * The fallback exists only as a measurement artefact: callers may pass a body
 * truncated for a failure message, which can cut the JSON in half and make
 * `JSON.parse` fail. A truncated body is `unknown` — and a body nobody can
 * classify is the correct verdict for it.
 */
export function classifyNotFoundBody(body = '') {
    let parsed = null;
    try {
        parsed = JSON.parse(body);
    } catch {
        // No verdict to read.
        return TEARDOWN_NOT_FOUND.UNKNOWN;
    }
    if (parsed === null || typeof parsed !== 'object' || typeof parsed.message !== 'string') {
        return TEARDOWN_NOT_FOUND.UNKNOWN;
    }
    // Order matters: the model message is checked first, because it is the one
    // that legitimately means "the row is gone".
    if (parsed.message.includes('No query results for model')) {
        return TEARDOWN_NOT_FOUND.ROW_ABSENT;
    }
    if (parsed.message.includes('could not be found')) {
        return TEARDOWN_NOT_FOUND.ROUTE_ABSENT;
    }
    return TEARDOWN_NOT_FOUND.UNKNOWN;
}

/**
 * Asks the BACKEND whether every route in the plan exists — one sentinel DELETE
 * per reclaimable step, and ONE admin login for all of them.
 *
 * ## Why this is not the same check as the 404 classification
 *
 * The classification only runs on a 404 the teardown actually receives, so a
 * step whose kind no test happened to own in this run is never questioned: a
 * typo in the `badgeImages` route stays invisible until some test owns a badge
 * image, which today is no test at all. This preflight asks about the PLAN, so
 * an unexercised entry is checked anyway. The two are complementary and neither
 * subsumes the other.
 *
 * ## Why it lives in a test rather than in the teardown's hot path
 *
 * It is a plan-level question, so it is asked once per RUN from
 * `ownership.spec.ts`, where its own assertions live and where a failure names
 * the offending kind. Folding it into every `reclaimOwnedRows()` would put
 * twelve extra DELETEs — and in the specs that reclaim without an admin session
 * an extra login, which is the budget the UI-heavy specs need — into a path that
 * runs after EVERY test.
 *
 * ## What it accepts, and why the probe is safe
 *
 * Only the "row absent" verdict, which for the sentinel means: the route is
 * registered and its model binding rejected an id that cannot exist. A 204 would
 * mean the sentinel was deleted (impossible for an id this large, and the only
 * way to reach that state is a route that ignores its id) and a 405 that the URI
 * exists under another method only — both are reported, not tolerated.
 */
export async function preflightTeardownRoutes() {
    const api = await loginAdminApi();
    const rejected = [];
    const checked = [];
    try {
        for (const step of E2E_OWNED_TEARDOWN) {
            if (!step.reclaimable || step.route === null) {
                continue;
            }
            const url = step.route.replace('{parentId}', '0') + `/${TEARDOWN_ROUTE_PROBE_ID}`;
            const response = await api.delete(url);
            const status = response.status();
            if (status === 404) {
                const verdict = classifyNotFoundBody(await response.text());
                if (verdict === TEARDOWN_NOT_FOUND.ROW_ABSENT) {
                    checked.push(`${step.kind} → ${url}`);
                    continue;
                }
                rejected.push(
                    `${step.kind}: DELETE ${url} answered 404 that is NOT "the row is gone" (${verdict}) — ` +
                        'the address in E2E_OWNED_TEARDOWN reaches no delete route, so every row of this kind is ' +
                        `silently kept. The route in the plan is: ${step.route}`,
                );
                continue;
            }
            let body = '';
            try {
                body = (await response.text()).slice(0, 200);
            } catch {
                body = '<unreadable body>';
            }
            rejected.push(
                `${step.kind}: DELETE ${url} answered ${status} for an id that cannot exist (expected a 404 ` +
                    `"saying the row is gone"). The route in the plan may not be a per-row delete at all: ` +
                    `${step.route}. Body: ${body}`,
            );
        }
    } finally {
        await api.dispose();
    }
    return { checked, rejected };
}

/**
 * The FK constraint graph the teardown order has to respect, as data.
 *
 * Read off the migrations and confirmed against the live schema
 * (`pg_constraint.confdeltype`: `c`ascade, `n`ull, `r`estrict):
 *
 *   applications.accreditation_id       → cascade
 *   applications.user_id               → cascade
 *   sub_accreditations.accreditation_id → cascade
 *   sub_applications.sub_accreditation_id → cascade
 *   sub_applications.application_id     → cascade
 *   sub_applications.user_id           → cascade
 *   user_media.user_id                 → cascade
 *   accreditations.category_id         → cascade
 *   accreditations.event_id            → nullOnDelete      ← does NOT cascade
 *   accreditations.team_id             → cascade
 *   events.venue_id / teams.venue_id   → restrictOnDelete  ← 409 while referenced
 *   categories.mandant_id, events.mandant_id, teams.mandant_id → cascade
 *   mandant_domains.mandant_id         → cascade
 *   users.mandant_id                   → nullOnDelete      ← a user survives its mandant
 *
 * `onDelete: 'cascade'` means the order is free for that pair (deleting the
 * parent takes the child). `'null'` and `'restrict'` mean it is NOT free: for
 * `null` the child survives its parent's delete, for `restrict` the parent's
 * delete is refused. The order test only constrains those two.
 */
export const E2E_OWNED_FK_EDGES = [
    { child: 'applications', parent: 'accreditations', onDelete: 'cascade' },
    { child: 'applications', parent: 'users', onDelete: 'cascade' },
    { child: 'subAccreditations', parent: 'accreditations', onDelete: 'cascade' },
    { child: 'subApplications', parent: 'subAccreditations', onDelete: 'cascade' },
    { child: 'subApplications', parent: 'applications', onDelete: 'cascade' },
    { child: 'subApplications', parent: 'users', onDelete: 'cascade' },
    { child: 'userMedia', parent: 'users', onDelete: 'cascade' },
    { child: 'accreditations', parent: 'categories', onDelete: 'cascade' },
    { child: 'accreditations', parent: 'events', onDelete: 'null' },
    { child: 'accreditations', parent: 'teams', onDelete: 'cascade' },
    { child: 'accreditations', parent: 'mandants', onDelete: 'cascade' },
    { child: 'events', parent: 'teams', onDelete: 'cascade' },
    { child: 'events', parent: 'venues', onDelete: 'restrict' },
    { child: 'events', parent: 'mandants', onDelete: 'cascade' },
    { child: 'teams', parent: 'venues', onDelete: 'restrict' },
    { child: 'teams', parent: 'mandants', onDelete: 'cascade' },
    { child: 'categories', parent: 'teams', onDelete: 'cascade' },
    { child: 'categories', parent: 'mandants', onDelete: 'cascade' },
    { child: 'venues', parent: 'mandants', onDelete: 'cascade' },
    { child: 'blacklists', parent: 'mandants', onDelete: 'cascade' },
    { child: 'badgeImages', parent: 'mandants', onDelete: 'cascade' },
    { child: 'badgeTemplates', parent: 'mandants', onDelete: 'cascade' },
    { child: 'mandantDomains', parent: 'mandants', onDelete: 'cascade' },
    { child: 'users', parent: 'mandants', onDelete: 'null' },
];

/**
 * Registers one created row. Call this IMMEDIATELY after the create response,
 * before any further step — that ordering is the whole half-failure guarantee:
 * if the next create throws, the rows registered so far are already owned.
 *
 * `parentId` is only needed by the kinds whose route is mandant-scoped
 * (`teams`, `mandantDomains`); it is the mandant the row belongs to.
 */
export function rememberOwnedRow(kind = '', id = 0, parentId = 0) {
    const list = E2E_OWNED.get(kind);
    if (list === undefined) {
        throw new Error(
            `rememberOwnedRow("${kind}") is not a kind the teardown knows. Add it to ` +
                'E2E_OWNED_TEARDOWN in tests/e2e/helpers/ownership.ts, or the row is never given back.',
        );
    }
    list.push({ id, parentId });
}

/**
 * Registers a row whose DELETE route only answers for its OWNING account
 * (`userMedia`, and the two application kinds): the teardown has to log in as
 * that user, so the credentials travel with the row.
 */
export function rememberOwnedByUser(kind = '', id = 0, email = '', password = '') {
    const list = E2E_OWNED.get(kind);
    if (list === undefined) {
        throw new Error(`rememberOwnedByUser("${kind}") is not a kind the teardown knows.`);
    }
    list.push({ id, parentId: 0, email, password });
}

/**
 * Registers a row of a kind whose CREATE call answers no id — today only
 * `users`, where `POST /api/auth/register` replies with a bare `{message}`.
 *
 * ## What changed when the account DELETE route landed
 *
 * This used to be `rememberUnreclaimableUser`, and the name was the point: for
 * months the `users` kind had NO delete route (MEASURED: the admin surface
 * answered 405), so the helper registered the row and the teardown counted and
 * NAMED it instead of pretending to delete it. That gap is closed — the route
 * is `DELETE /api/admin/users/{user}` behind `can:users.delete` — so the row is
 * genuinely given back, and a name saying "unreclaimable" would now be a lie
 * the ledger contradicts one entry below.
 *
 * The EMAIL is stored in the `id` slot on purpose: it is the only handle the
 * create call produced, and `resolveIdBy: 'email'` in `E2E_OWNED_TEARDOWN`
 * turns it into an id at teardown time — by then the account is activated and
 * therefore visible in the admin list.
 */
export function rememberOwnedUserAccount(email = '') {
    const list = E2E_OWNED.get('users');
    if (list === undefined) {
        throw new Error('rememberOwnedUserAccount() is not a kind the teardown knows.');
    }
    list.push({ id: email, parentId: 0 });
}

/**
 * Empties the ledger. Called in `beforeEach`, BEFORE the first create — the
 * portal's "instance per test" expressed without an instance.
 */
export function resetOwnedRows() {
    for (const list of E2E_OWNED.values()) {
        list.length = 0;
    }
}

/**
 * How many rows of one kind the current test owns. Read by specs that assert
 * on their own footprint, and by the teardown's own report.
 */
export function ownedRowCount(kind = '') {
    const list = E2E_OWNED.get(kind);
    return list === undefined ? 0 : list.length;
}

/**
 * Gives back everything the current test registered, in `E2E_OWNED_TEARDOWN`
 * order, and reports what it could not.
 *
 * ## Nothing is swallowed
 *
 * The portal's equivalent (`E2ESessionHelper.deleteResources`) catches every
 * delete error into `console.warn` and returns. That is the F1 shape: 53 venues
 * refused with 409, every refusal discarded, the run reported success. Here a
 * delete that answers neither 204 nor 404 is COLLECTED and thrown as a
 * `PurgeReclamationFailure` — the same class the serial teardown uses, so a run
 * that cannot give a row back is RED in both nets.
 *
 * 404 is tolerated, and only the 404 that means "the row is gone": a row another
 * net already reclaimed, or a cascade that took a child with its parent, is the
 * goal state. A 404 that means the ROUTE does not exist is a different thing
 * entirely and fails the run — see `classifyNotFoundBody` for the two measured
 * shapes and why tolerating both is what let a one-character typo leak a row per
 * run. 409 is NOT tolerated anywhere: that is the backend saying "something still
 * references this", which is precisely the ordering bug this must surface.
 *
 * ## Owner-scoped kinds
 *
 * `applications`, `subApplications` and `userMedia` answer only for the owning
 * account, so the teardown logs in as that user. The login is per DISTINCT
 * user, not per row: a test that uploaded three portraits of one user pays one
 * login, not three, which matters because `login` is throttled per IP.
 *
 * Three outcomes are possible for those rows, and none of them is a shrug:
 *
 * 1. **the DELETE answers `okStatus`** — reclaimed.
 * 2. **the login answers 401** — the account may be gone. `ownerAccountIsGone`
 *    decides, with the admin list, and only for an account this suite created
 *    (see its docblock for the global-account hole this closes). Gone means the
 *    row went with the `users` cascade, so it is reclaimed without a DELETE;
 *    still there means the login was refused for another reason, and the run
 *    goes red rather than counting a leak as a success.
 * 3. **the DELETE answers 422** — the row is decided, so its owner may no
 *    longer withdraw it. That is a hand-over to the cascade, recorded in
 *    `carried` and PROVED by `verifyCarriedRows` after the plan has run.
 */
export async function reclaimOwnedRows() {
    // Cheap exit for the (many) tests that own nothing: no admin login, no
    // request. This hook is at FILE scope in every spec, so it runs for tests
    // that create no rows at all, and `login` is throttled per IP (40/min in
    // local) — paying one per test would eat the budget the UI-heavy specs
    // need. Measured reason, not a micro-optimisation.
    let owned = 0;
    for (const list of E2E_OWNED.values()) {
        owned += list.length;
    }
    if (owned === 0) {
        return { reclaimed: 0, unreclaimable: new Map(), failures: [] };
    }

    // The control arm, for measurement only. `E2E_OWNERSHIP=off` skips the
    // deletes and reports the rows instead, which is what lets
    // `scripts/e2e-per-spec-leaks.mjs` run the SAME spec twice and attribute the
    // difference to the teardown rather than to two different code paths. It is
    // never set in CI and the default is ON — a switch that quietly disabled the
    // guarantee would be exactly the kind of thing this harness exists to catch,
    // so it announces itself loudly instead of passing for a normal run.
    if (process.env.E2E_OWNERSHIP === 'off') {
        const skipped = new Map();
        for (const step of E2E_OWNED_TEARDOWN) {
            const list = E2E_OWNED.get(step.kind);
            if (list !== undefined && list.length > 0) {
                skipped.set(step.kind, list.length);
            }
        }
        const named = [];
        for (const [kind, count] of skipped) {
            named.push(`${count} ${kind}`);
        }
        console.log(
            `[e2e-ownership] E2E_OWNERSHIP=off — CONTROL ARM, ${owned} row(s) deliberately NOT reclaimed: ${named.join(', ')}`,
        );
        for (const list of E2E_OWNED.values()) {
            list.length = 0;
        }
        return { reclaimed: 0, unreclaimable: skipped, failures: [] };
    }

    // Typed by SEED rather than by annotation, which this directory forbids
    // (see the module docblock): `const x = []` infers `never[]` and a
    // `push(string)` is then a hard `tsc` error, while `const x = ['seed']`
    // infers `string[]`. The seed is dropped immediately, so the arrays start
    // empty — and `new Map()` infers `Map<any, any>`, which is exactly what a
    // kind-keyed tally wants.
    const failures = [''];
    failures.length = 0;
    const unreclaimable = new Map();
    let reclaimed = 0;
    // Decided rows the owner route refused, verified AFTER the plan has run.
    // Collected rather than counted at the moment they are seen: at that
    // moment the row demonstrably still EXISTS (the cascade that takes it is
    // steps away), so counting it there would be counting a wish.
    const carried = [{ kind: '', id: 0, listUrl: '' }];
    carried.length = 0;

    /**
     * Every session this teardown opens, in ONE map: the admin session under the
     * literal key `admin`, and one entry per distinct owner user keyed by email.
     *
     * One map rather than a variable plus a map, because neither alternative
     * type-checks in this directory: `let x = null` is an implicit `any` under
     * this config, and seeding an array (`[null]`, then `length = 0`) makes `tsc`
     * infer the SEED's type, so assigning a context to it is TS2322 (measured, all
     * three). `new Map()` infers `Map<any, any>`, which is exactly a keyed
     * collection of sessions, and it needs no annotation to say so.
     *
     * The admin session is created on FIRST USE, not up front: `login` is
     * throttled per IP (40/min in local) and the whole suite runs behind one IP,
     * so a login this teardown does not need comes out of the budget the UI-heavy
     * specs need. A test that owns only `users` rows (no route to call), only
     * owner-scoped rows, or nothing at all, therefore pays no login at all.
     */
    const sessions = new Map();

    async function adminApi() {
        if (!sessions.has('admin')) {
            sessions.set('admin', await loginAdminApi());
        }
        return sessions.get('admin');
    }

    /**
     * Resolves a stored EMAIL to the numeric id the delete route addresses.
     *
     * `POST /api/auth/register` answers a bare `{message}` — there is no id to
     * register — so the email is the only handle a user fixture has. This is the
     * one place that turns it back into an id, and it is deliberately strict:
     *
     *  - the search is a LIKE (`UserController@index`), so the result is
     *    filtered down to an EXACT email match. A substring hit would delete
     *    somebody else's account, which is the worst possible failure of a
     *    cleanup helper.
     *  - no exact match means the account is already gone (the test deleted it
     *    itself — the self-service flow is its own cleanup) → `null`, and the
     *    caller counts it as reclaimed.
     *  - more than one exact match is impossible (emails are unique per
     *    mandant) and is reported as a FAILURE rather than guessed, because
     *    guessing here means deleting a row nobody registered.
     *
     * It opens the admin session ITSELF through `adminApi()` instead of taking
     * one as a parameter: the only step with `resolveIdBy` is the `users` step,
     * whose actor is `admin`, and a parameter would have to be defaulted to
     * satisfy both parsers (plain-JS lint, strict `tsc`) — a default of `null`
     * then rejects the real session at the call site (MEASURED: TS2345). A loop
     * instead of `filter`, for the same reason.
     */
    async function resolveUserIdByEmail(email = '') {
        const session = await adminApi();
        const response = await session.get(`/api/admin/users?search=${encodeURIComponent(email)}`);
        if (response.status() !== 200) {
            throw new Error(
                `[e2e-ownership] could not resolve the account id for ${email}: the admin user list answered ` +
                    `${response.status()}. Without the id the users row cannot be given back.`,
            );
        }
        const body = JSON.parse(await response.text());
        const rows = Array.isArray(body.data) ? body.data : [];
        const exact = [];
        for (const row of rows) {
            if (row && row.email === email) {
                exact.push(row);
            }
        }
        if (exact.length === 0) {
            return null;
        }
        if (exact.length > 1) {
            throw new Error(
                `[e2e-ownership] ${exact.length} accounts answer to the exact email ${email}; refusing to guess ` +
                    'which one to delete.',
            );
        }
        return exact[0].id;
    }

    /**
     * Is the owner account GONE — as opposed to merely refusing this login?
     *
     * A 401 from `/api/auth/login` means "no such account under this host" OR
     * "wrong password" (MEASURED: `AuthController::login` answers the same body
     * and status for both, and the docblock says so — it deliberately removed
     * the existence oracle). Those two have to be told apart, and the ONLY
     * probe this harness has is the mandant-scoped admin list. That is enough,
     * but only under a condition, and the condition is the interesting part:
     *
     * - `resolveUserIdByEmail` reads `GET /api/admin/users?search=…`, which
     *   filters on a mandant-scoped `role_user` row
     *   (`UserController::index`). A GLOBAL account (`mandant_id = null` — the
     *   bootstrap `super_admin`) holds no such row, so it is invisible to that
     *   list: for one of those, "not in the list" would read as "gone" while
     *   the account and its rows are alive. MEASURED and stated, because it is a
     *   hole and not an assumption.
     * - It is nevertheless unreachable for anything this suite creates, and
     *   this function is what makes that structural rather than hopeful:
     *   `POST /api/auth/register` binds every account it creates to the CURRENT
     *   mandant (`AuthController::register`: `'mandant_id' => $mandant->id`)
     *   and REFUSES an address that collides with a system-wide account
     *   (the closure in the same `email` rule). So an account this suite
     *   registered is mandant-scoped by contract, and the admin list is a
     *   complete probe for it.
     * - Therefore the "gone" reading requires the ledger to OWN the account
     *   (`rememberOwnedUserAccount` for the same email). An owner-scoped row
     *   whose account the test never registered cannot be judged that way —
     *   nobody knows whether it is mandant-scoped — so this throws instead of
     *   counting it. That is the fail-closed direction, and it is the direct
     *   answer to the global-account hole: the harness refuses to guess about
     *   an account it did not create.
     *
     * Measured 2026-10-01: every live owner-scoped registration in the suite
     * pairs with a `rememberOwnedUserAccount` for the same email —
     * `admin-data.ts` (all four sites) and `profile.spec.ts` — so this guard
     * costs nothing today and would only fire on a fixture that registers a row
     * it cannot account for.
     */
    async function ownerAccountIsGone(email = '') {
        const accounts = E2E_OWNED.get('users') ?? [];
        let registered = false;
        for (const account of accounts) {
            if (account && account.id === email) {
                registered = true;
            }
        }
        if (!registered) {
            throw new Error(
                `[e2e-ownership] the teardown could not log in as ${email} (status 401), and the ledger never `
                    + 'registered that account — so there is no evidence that it is gone rather than merely refusing '
                    + 'the login. Counting its rows as reclaimed would be a guess, and this harness does not guess.',
            );
        }
        return (await resolveUserIdByEmail(email)) === null;
    }

    /**
     * PROVE that every row the owner route refused as DECIDED really was taken
     * by the cascade — the step that turns "tolerated" into "measured".
     *
     * It has to run after the whole plan, not where the 422 was seen: at that
     * moment the row still exists (its accreditation and its account are
     * deleted later in this same run), so an assertion there would fail on
     * every correctly-behaving teardown. The lists it reads are the mandant-
     * scoped ADMIN ones, which is what makes the check independent of the owner
     * session that just failed — and a row that IS still listed is a real leak,
     * not a tolerance question.
     */
    async function verifyCarriedRows(rows = [{ kind: '', id: 0, listUrl: '' }]) {
        if (rows.length === 0) {
            return;
        }
        const session = await adminApi();
        const listed = new Map();
        const survivors = [];
        for (const row of rows) {
            if (!listed.has(row.listUrl)) {
                const response = await session.get(row.listUrl);
                if (response.status() !== 200) {
                    throw new PurgeReclamationFailure(
                        `[e2e-ownership] cannot verify the decided ${row.kind} rows: ${row.listUrl} answered `
                            + `${response.status()}. Without the list there is no proof that the cascade took them, `
                            + 'and "the cascade probably did" is not a result.',
                    );
                }
                const body = await response.json();
                listed.set(row.listUrl, Array.isArray(body.data) ? body.data : []);
            }
            const current = listed.get(row.listUrl);
            for (const entry of current) {
                if (entry && entry.id === row.id) {
                    survivors.push(`${row.kind} ${row.id}`);
                }
            }
        }
        if (survivors.length > 0) {
            throw new PurgeReclamationFailure(
                `[e2e-ownership] ${survivors.length} DECIDED row(s) were neither withdrawable by their owner nor `
                    + 'taken by the cascade from their accreditation or their account — so nothing gave them back '
                    + `and the NEXT run inherits them:\n${survivors.join('\n')}`,
            );
        }
        reclaimed += rows.length;
        console.log(
            `[e2e-ownership] ${rows.length} decided row(s) were carried by the cascade and VERIFIED gone: `
                + rows.map((row) => `${row.kind} ${row.id}`).join(', '),
        );
    }

    try {
        for (const step of E2E_OWNED_TEARDOWN) {
            const list = E2E_OWNED.get(step.kind);
            if (list === undefined || list.length === 0) {
                continue;
            }
            if (!step.reclaimable || step.route === null) {
                // Named, counted, and never silently dropped — the net for a
                // kind whose route does not exist (there is none today; the
                // branch stays because a plan entry may declare it again).
                unreclaimable.set(step.kind, list.length);
                continue;
            }

            for (const entry of list) {
                // Owner-scoped kinds answer only for the owning account, so they
                // get their OWN session — and an admin session would answer 403,
                // not 404, which would read as a missing route. One session per
                // distinct owner, reused across that owner's rows: a test that
                // uploaded three portraits pays one login, not three.
                let session = null;
                if (step.actor === 'owner') {
                    if (!sessions.has(entry.email)) {
                        const opened = await loginAsUser(entry.email, entry.password);
                        // `null` means 401 and NOTHING else (see `loginAsUser`):
                        // either the account is gone — so every row it owned went
                        // with it through the `users` cascade, and the ledger
                        // caches that verdict under this email — or the login
                        // failed for a reason that leaves the rows behind, which
                        // is a red run rather than a quiet count.
                        sessions.set(entry.email, opened);
                        if (opened === null && !(await ownerAccountIsGone(entry.email))) {
                            throw new Error(
                                `the ownership teardown could not log in as ${entry.email} (status 401) and the ` +
                                    'account IS still listed for this mandant — so its rows are still there and ' +
                                    'this teardown cannot give them back. Refusing to count them as reclaimed.',
                            );
                        }
                    }
                    session = sessions.get(entry.email);
                    if (session === null) {
                        // The account is gone (established once per owner above),
                        // and every owner-scoped row has a `cascade` edge from
                        // `users` in `E2E_OWNED_FK_EDGES` — so this row went with
                        // it and needs no DELETE of its own.
                        reclaimed += 1;
                        continue;
                    }
                } else {
                    session = await adminApi();
                }

                if (step.resolveIdBy === 'email') {
                    const userId = await resolveUserIdByEmail(entry.id);
                    if (userId === null) {
                        // The account is already gone — that is the goal state,
                        // and for a self-deleting test it is the NORMAL one.
                        reclaimed += 1;
                        continue;
                    }
                    entry.id = userId;
                }

                const url = step.route.replace('{parentId}', String(entry.parentId)) + `/${entry.id}`;

                const response = await session.delete(url);
                const status = response.status();
                if (status === step.okStatus) {
                    reclaimed += 1;
                    continue;
                }
                // The FULL body for the classifier and a 300-char slice for the
                // message — the other way round is a real bug, not a style
                // question: with `APP_DEBUG=1` a model-not-found body carries a
                // full stack trace, so 300 characters cuts the JSON in half,
                // `JSON.parse` fails, and every legitimately cascaded row would be
                // reported as an unreadable 404.
                let body = '';
                try {
                    body = await response.text();
                } catch {
                    body = '<unreadable body>';
                }
                const shortBody = body.slice(0, 300);
                if (status === 404) {
                    // THE fix for the tolerance that could not tell two 404s
                    // apart (see `classifyNotFoundBody`): "the row is gone" is the
                    // goal state, "the address reaches nothing" is a typo in the
                    // plan, and the second one used to pass for the first.
                    const verdict = classifyNotFoundBody(body);
                    if (verdict === TEARDOWN_NOT_FOUND.ROW_ABSENT) {
                        reclaimed += 1;
                        continue;
                    }
                    failures.push(
                        `DELETE ${url} answered 404 that is NOT "the row is gone" (${verdict}) — the ` +
                            `${step.kind} row ${entry.id} was never addressed at all, so it stays and the NEXT ` +
                            'run inherits it. A 404 here means either the route in E2E_OWNED_TEARDOWN is wrong ' +
                            'or something in front of the API answers 404 for everything. Body: ' +
                            shortBody,
                    );
                    continue;
                }
                if (status === step.decidedStatus && step.verifiedBy !== undefined) {
                    // The row is DECIDED, so its owner may no longer withdraw it.
                    // It is not lost: the cascade from its accreditation or from
                    // its account — both of them steps in this same plan — takes
                    // it. The proof that it did is `verifyCarriedRows` below, not
                    // this comment.
                    if (classifyNotWithdrawableBody(body) === TEARDOWN_DECIDED.DECIDED) {
                        carried.push({ kind: step.kind, id: entry.id, listUrl: step.verifiedBy });
                        continue;
                    }
                    failures.push(
                        `DELETE ${url} answered ${status} but NOT with the "this row is decided" body — a 422 that `
                            + `means something else leaves the ${step.kind} row ${entry.id} behind, and this teardown `
                            + `does not guess which. Body: ${shortBody}`,
                    );
                    continue;
                }
                failures.push(
                    `DELETE ${url} answered ${status} (expected ${step.okStatus}/404) — the ${step.kind} row ` +
                        `${entry.id} stays, and the NEXT run inherits it. Body: ${shortBody}`,
                );
            }
        }

        // The proof for the hand-over above, and it runs LAST on purpose: at the
        // moment the owner route answered 422 the row demonstrably still
        // existed, so the only honest moment to ask "is it gone now?" is after
        // the parents that cascade to it have been deleted.
        await verifyCarriedRows(carried);
    } finally {
        for (const session of sessions.values()) {
            // A `null` here is the "this account is gone" verdict, cached under
            // its email by the owner branch above. There is no context to
            // dispose — and calling `.dispose()` on it would throw out of the
            // `finally` that empties the ledger, which is the one place that
            // must not be interrupted.
            if (session !== null) {
                await session.dispose();
            }
        }
        // The ledger is EMPTIED here, in the same `finally` that disposes the
        // sessions — so a reclamation failure (thrown just below) cannot poison the
        // NEXT test in this worker process. Without it, a teardown that threw
        // would leave its ids behind and the following test's `afterEach` would
        // delete rows it never created, blaming that test for this one's leak.
        // The ids are lost instead, which is right: the run is already red, and
        // the serial teardown still has the name sweep as a second net.
        for (const list of E2E_OWNED.values()) {
            list.length = 0;
        }
    }

    // Report the gap loudly even when nothing failed: a kind that grows by ~15
    // per run is invisible precisely because nothing ever mentions it.
    if (unreclaimable.size > 0) {
        const named = [];
        for (const [kind, count] of unreclaimable) {
            named.push(`${count} ${kind}`);
        }
        console.log(`[e2e-ownership] this test created rows with NO delete route: ${named.join(', ')}`);
    }

    if (failures.length > 0) {
        throw new PurgeReclamationFailure(
            `[e2e-ownership] ${failures.length} row(s) this test created could not be given back. ` +
                'A run that cannot return its own fixtures is not a run that can be trusted to report on them:\n' +
                failures.join('\n'),
        );
    }

    // A plain object, not a `Map`: the three fields have different shapes, and
    // a `Map` literal is checked against its FIRST entry's value type and
    // rejects the other two (measured: TS2769). Object-literal inference is
    // per-field, so each keeps its own type — and the fields are read by fixed
    // name, so no index signature is ever needed.
    return { reclaimed, unreclaimable, failures };
}

/**
 * A logged-in session for one E2E throwaway user. Separate from
 * `loginAdminApi()` because the owner-scoped delete routes compare
 * `auth('api')->id()` with the row's `user_id` — an admin session is refused
 * with a 403, not merely a 404, so using the wrong session here would look like
 * a missing route.
 *
 * ## Why ONE status comes back as `null` and every other one throws
 *
 * A 401 during an owner-scoped teardown has two readings that must not be
 * confused, and they need opposite handling:
 *
 *  - **the account is gone** — the test deleted it (that is the goal state of
 *    `account-deletion.spec.ts` and of the self-service flow), and every row it
 *    owned went with it through the `users` cascade. Nothing is left to give
 *    back, so this is a success.
 *  - **the credentials were refused** — the account is alive and its rows are
 *    alive, so a teardown that shrugged here would report success over a leak.
 *
 * So a 401 is RETURNED (as `null`) and the caller decides with
 * `ownerAccountIsGone`; everything else still throws. MEASURED 2026-10-01 for
 * the statuses that are deliberately not swallowed: 403 is
 * `AuthController::login`'s answer for an account that is not activated yet and
 * for one that is not a member of this host, 429 is the `login` limiter (40/min
 * in local, `AppServiceProvider`), and 422 is the validation answer. None of
 * those three means "the account is gone".
 *
 * The return is a union on purpose: `null` is the ONLY way this function says
 * "refused", so the caller cannot read a status by accident and cannot forget
 * to classify it.
 */
async function loginAsUser(email = '', password = '') {
    const { request } = await import('@playwright/test');
    const session = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    const login = await session.post('/api/auth/login', { data: { email, password } });
    if (login.status() === 401) {
        await session.dispose();
        return null;
    }
    if (login.status() !== 200) {
        await session.dispose();
        throw new Error(
            // Deliberately NOT starting with "E2E ": the marker scan in
            // `namespace-isolation.spec.ts` reads any template literal whose head
            // carries the E2E namespace as a constructed entity name, and this is
            // a log message, not a fixture name. The scan over-approximates on
            // purpose (see its docblock), so the wording yields rather than the
            // guard growing an exception list.
            `the ownership teardown could not log in as ${email} (status ${login.status()}); ` +
                'its rows cannot be given back',
        );
    }
    return session;
}

/** The ledger, read-only, for the specs that assert on their own footprint. */
export function ownedRows() {
    return E2E_OWNED;
}

/**
 * Seeds the ledger's keys. Runs at import time so `rememberOwnedRow` can reject
 * an unknown kind instead of silently accepting it — an unregistered kind is a
 * row nobody will ever give back, which is the F1 shape with a new name.
 */
for (const step of E2E_OWNED_TEARDOWN) {
    E2E_OWNED.set(step.kind, []);
}
