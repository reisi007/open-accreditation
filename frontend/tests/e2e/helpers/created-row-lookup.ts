import type { APIRequestContext } from '@playwright/test';

/**
 * Resolving the id of a row that a **FORM** created — the honest limit of the
 * ownership ledger, made into a helper (Position 22).
 *
 * ## What it is for
 *
 * The per-test ledger (`helpers/ownership.ts`) is the first net: a fixture
 * registers its id the moment it exists and the test's own `afterEach` gives it
 * back. That works wherever the create answers an id — a raw `api.post` puts
 * `data.id` right there.
 *
 * It does NOT work for a row created THROUGH THE UI. A create form answers
 * nothing the test can keep: the mandant's id shows up in the URL, a category's,
 * an event's, a venue's, a team's, a blacklist entry's and a sub-accreditation's
 * nowhere. MEASURED with `E2E_PURGE=off`, twice in a row for the mandant case:
 * `mandants 0 → 1 → 2` — cumulative and unbounded, out of a spec whose every
 * assertion is green. The serial name sweep does reclaim those rows eventually,
 * by prefix, which is exactly why the leak stays invisible for a whole run.
 *
 * So the row has to be FOUND afterwards, and the only handle a create form
 * leaves is the value the test typed. That value is worker-unique — every one of
 * these names carries a `uniqueSuffix()` stamp (`w<i>-p<pid>-<ms>`) — so an
 * EXACT match on it names exactly one row.
 *
 * ## Why EXACT and never "starts with"
 *
 * A prefix search returns the FIRST row whose value starts with the name, and
 * another worker's row does start with it: `E2E Team w1-p42-1789…` is a prefix
 * of `E2E Team w1-p42-1789…1`. That registers the WRONG id, and a teardown then
 * deletes a sibling's fixture while the real one survives — strictly worse than
 * registering nothing, because it destroys another test's data AND leaks its own.
 * So the comparison is `===`, with no normalisation of any kind.
 *
 * ## Why the mismatch is a THROW and not `null`
 *
 * `null` means "the list does not carry that value". At a create site that is
 * not information, it is a contradiction: the form reported success, the row is
 * in the database, and the only thing missing is the handle. Carrying on would
 * leave the row behind with a green test on top of it — the F1 shape this whole
 * harness exists to end. `exactMatchRowId` therefore THROWS on a hit it cannot
 * disambiguate, and the caller turns a `null` into a throw too
 * (`helpers/created-row.ts`).
 *
 * ## Why this file is its own module and typed
 *
 * Two measured reasons, both inherited rather than invented:
 *
 * 1. **The plain-JS parser.** `eslint.config.js` gives `tests/e2e/**` no TS
 *    parser — a parameter ANNOTATION there is a parse error — and JSDoc does
 *    not type a parameter in a `.ts` file either (measured: `TS7006`). So this
 *    file is listed in the TS-parser block of `eslint.config.js`, next to
 *    `helpers/teams-enabled.ts`, which needed the same treatment for the same
 *    reason. This file is that list's next entry, and the comment there says so.
 * 2. **Vitest must be able to drive it.** `import type` is erased, so this
 *    module has NO runtime dependency on `@playwright/test`. Importing that
 *    package for real from under Vitest costs **121 s** on this machine (vs
 *    **526 ms** in plain `node`), which is why no vitest test may touch a module
 *    that does — see `tests/e2e/teams-precondition.test.ts` and
 *    `tests/e2e/created-row-lookup.test.ts`. The self-logging convenience
 *    wrappers live in `helpers/created-row.ts` precisely because they DO import
 *    the session, and therefore are not driven by unit tests.
 */

/** One row as this module reads it: an `id` plus whatever the caller keys on. */
export interface CreatedRow {
    id?: unknown;
    [key: string]: unknown;
}

/**
 * The id of the ONE row whose `key` is EXACTLY `value`, or `null` if there is
 * none.
 *
 * ## The three verdicts, and why each is what it is
 *
 * - **exactly one hit** → its id. The whole contract.
 * - **no hit** → `null`. Distinct from a failure: this is what "the list does not
 *   carry it" looks like, and the caller decides whether that is fatal (at a
 *   create site it always is — see the module docblock).
 * - **two or more hits** → THROWS. Two rows carrying the identical value means
 *   the list is not mandant-scoped for this kind (`GET /api/admin/mandants` is
 *   global for a `super_admin`, and the dev database really does hold two
 *   same-named mandants — see `admin-mandant-switch.spec.ts`'s own comment).
 *   Picking the first of them would register a foreign id, and deleting THAT is
 *   the worst thing a cleanup helper can do, so the helper refuses to choose.
 *
 * ## Why not a `startsWith`/`includes`
 *
 * See the module docblock: a prefix search answers a sibling worker's row. The
 * one thing that makes the exact match safe is that the value carries
 * `uniqueSuffix()`, so no two rows in the list can hold it — and that guarantee
 * only holds while the comparison stays exact.
 *
 * Non-array input is `null` rather than a throw: an API list is `{data: [...]}`
 * and a `data` that is not an array is a shape change, which the caller's own
 * "no row carries" message then names with its URL. The `id` type check is the
 * exception — a hit whose id is not a number cannot be deleted, so registering
 * it would be worse than reporting nothing.
 */
export function exactMatchRowId(rows: unknown, key: string, value: unknown): number | null {
    if (!Array.isArray(rows)) {
        return null;
    }
    const exact: CreatedRow[] = [];
    for (const entry of rows) {
        if (entry === null || typeof entry !== 'object') {
            continue;
        }
        const row = entry as CreatedRow;
        if (row[key] === value) {
            exact.push(row);
        }
    }
    if (exact.length > 1) {
        throw new Error(
            `${exact.length} rows carry ${key} "${String(value)}" — refusing to guess which one to register. A `
                + 'lookup that picks one of them would hand the teardown a FOREIGN id, and deleting that is worse '
                + 'than registering nothing at all.',
        );
    }
    if (exact.length === 0) {
        return null;
    }
    const id = exact[0].id;
    if (typeof id !== 'number') {
        throw new Error(
            `a row matches ${key} "${String(value)}" but carries no numeric id (${String(id)}) — the ownership `
                + "ledger addresses rows by id, so there is nothing it could be given back under.",
        );
    }
    return id;
}

/**
 * `GET listUrl` and hand the rows to `exactMatchRowId`. `null` when the list
 * carries no exact match.
 *
 * ## Why the status is checked instead of being read past
 *
 * The list route can answer something other than 200 for reasons that have
 * nothing to do with the row — the `login` rate limiter (40/min, per IP), a
 * session that expired, a gate. Every one of those produces a body with no
 * `data`, which the matcher would report as "no row carries that value", and
 * the caller would then throw the create-site message — blaming the form for a
 * refusal by the API. Naming the status makes the two tellable apart, which is
 * the same reason `resolveUserIdByEmail` in `helpers/ownership.ts` checks its
 * own status.
 *
 * `listUrl` is used AS GIVEN and is never widened: the caller passes the
 * mandant-scoped route (`/api/admin/categories`) for a mandant-scoped kind, and
 * the helper has no way to "helpfully" substitute a global one. That is the
 * property the cross-mandant direction depends on — MEASURED on the backend, all
 * of these read `->forMandant($this->currentMandantId())`:
 * `CategoryController::index`, `EventController::index`, `VenueController::index`,
 * `BlacklistController::index`, `SubAccreditationController::indexAll`, and
 * `TeamController::index` (mandant-scoped path).
 */
export async function findRowIdByExactValue(
    api: APIRequestContext,
    listUrl: string,
    key: string,
    value: unknown,
): Promise<number | null> {
    const response = await api.get(listUrl);
    if (response.status() !== 200) {
        throw new Error(
            `The list ${listUrl} answered ${response.status()}, so it cannot say whether a row carries `
                + `${key} "${String(value)}". Without the list there is no id, and without the id the row cannot be `
                + 'given back — so this refuses instead of reporting "not found".',
        );
    }
    const body: unknown = await response.json();
    if (body === null || typeof body !== 'object') {
        return null;
    }
    return exactMatchRowId((body as { data?: unknown }).data, key, value);
}