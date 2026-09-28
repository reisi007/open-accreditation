import { FRONTEND_BASE_URL, loginAdminApi } from './api-session';
import { PurgeReclamationFailure } from './purge-failure';

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
    // `users` sits FIRST, not last, and the reason is a constraint rather than a
    // preference: `users.mandant_id` is `nullOnDelete`, so a user SURVIVES the
    // mandant delete. Deleting the mandant first would orphan every user that
    // belonged to it. The order test in `namespace-isolation.spec.ts` is what
    // found this — it is written against `E2E_OWNED_FK_EDGES`, and a hand-kept
    // order would not have.
    //
    // It is also the one kind with NO delete route (see the bottom of this list),
    // so today nothing is sent; the position is correct for the day the account
    // DELETE route lands (board position 10) and needs no change then.
    { kind: 'users', route: null, actor: 'admin', okStatus: 204, reclaimable: false },
    // An `application` is only withdrawable while it is still `requested`; an
    // approved one answers 422 on the owner route. What takes an approved
    // application is the CASCADE from its accreditation, which is why
    // `accreditations` below is not optional bookkeeping.
    { kind: 'applications', route: '/api/applications', actor: 'owner', okStatus: 204, reclaimable: true },
    { kind: 'subApplications', route: '/api/sub-applications', actor: 'owner', okStatus: 204, reclaimable: true },
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
 * Registers a row of a kind that has NO delete route at all — today only
 * `users`, where the gap is the whole point of the function.
 *
 * The email is stored in the `id` slot on purpose: it is the only handle a
 * user row has that the E2E suite itself minted (`POST /api/auth/register`
 * answers a bare `{message}`, so there is no id in the response to keep), and
 * the teardown never addresses it — it COUNTS it and NAMES it, so the residue
 * is visible in the run log instead of being an invisible +15 per run.
 */
export function rememberUnreclaimableUser(email = '') {
    const list = E2E_OWNED.get('users');
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

    try {
        for (const step of E2E_OWNED_TEARDOWN) {
            const list = E2E_OWNED.get(step.kind);
            if (list === undefined || list.length === 0) {
                continue;
            }
            if (!step.reclaimable || step.route === null) {
                // Named, counted, and never silently dropped — see the `users`
                // entry in `E2E_OWNED_TEARDOWN`.
                unreclaimable.set(step.kind, list.length);
                continue;
            }

            for (const entry of list) {
                const url = step.route.replace('{parentId}', String(entry.parentId)) + `/${entry.id}`;

                // Owner-scoped kinds answer only for the owning account, so they
                // get their OWN session — and an admin session would answer 403,
                // not 404, which would read as a missing route. One session per
                // distinct owner, reused across that owner's rows: a test that
                // uploaded three portraits pays one login, not three.
                let session = null;
                if (step.actor === 'owner') {
                    if (!sessions.has(entry.email)) {
                        sessions.set(entry.email, await loginAsUser(entry.email, entry.password));
                    }
                    session = sessions.get(entry.email);
                } else {
                    session = await adminApi();
                }

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
                failures.push(
                    `DELETE ${url} answered ${status} (expected ${step.okStatus}/404) — the ${step.kind} row ` +
                        `${entry.id} stays, and the NEXT run inherits it. Body: ${shortBody}`,
                );
            }
        }
    } finally {
        for (const session of sessions.values()) {
            await session.dispose();
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
 */
async function loginAsUser(email = '', password = '') {
    const { request } = await import('@playwright/test');
    const session = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    const login = await session.post('/api/auth/login', { data: { email, password } });
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
