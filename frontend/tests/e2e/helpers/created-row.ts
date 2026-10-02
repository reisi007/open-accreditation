import { loginAdminApi } from './api-session';
import { findRowIdByExactValue } from './created-row-lookup';

/**
 * The create-site half of the created-row lookup, in one importable place.
 *
 * ## Why this is a second module and not more of `created-row-lookup.ts`
 *
 * `created-row-lookup.ts` is typed and takes the session as a parameter, so
 * that `pnpm vitest` can drive it without ever importing `@playwright/test` for
 * real (measured: **121 s** under Vitest vs **526 ms** in plain `node`). Opening
 * a session is the one thing it deliberately does not do, so the self-logging
 * wrappers below live here, where importing `loginAdminApi()` costs nothing —
 * no vitest test touches this module, and the module docblock of
 * `created-row-lookup.ts` says so.
 *
 * The dependency graph stays a DAG, as `api-session.ts` documents:
 *
 *     api-session ──► created-row ──► created-row-lookup
 *          └────────────────────────►  ▲
 *
 * ## Why ONE session per lookup, and why that is the price
 *
 * `admin-mandant.spec.ts` established the shape: one call per create, right
 * after its submit, so the second create throwing cannot lose the first row —
 * that ordering IS the half-failure guarantee. A session held across all three
 * creates would put a live resource between a create and its registration, and
 * one lookup per create costs three logins where one would do.
 *
 * That is the price and it is bounded: `login` is throttled per IP (40/min in
 * local), and a login that IS refused now fails loudly by name, instead of the
 * previous behaviour where a throttled lookup read as "the row is not there".
 *
 * ## Why TWO wrappers, and not one that coerces
 *
 * The comparison is `===`, and `===` never coerces: a row whose
 * `accreditation_id` is the NUMBER 41 must not match the STRING `"41"`. One
 * wrapper with a `String(...)` around the comparison would make that match, and
 * would also make `"null"` match a null column. So the value keeps its own type,
 * and `tests/e2e/**` can only say that through a parameter's DEFAULT VALUE
 * (measured, four shapes, in `helpers/ownership.ts`'s module docblock:
 * `function f(id: number)` is an ESLint parse error, `function f(id)` is `TS7006`,
 * `function f(...ids)` is `TS7019`, `function f(id = 0)` type-checks). Hence one
 * wrapper per value type, and both share the one message below.
 */

/**
 * The message a create site throws when the list carries no exact match.
 *
 * Throwing rather than warning is the point, and it is inherited from
 * `admin-mandant.spec.ts`'s original private copy of this lookup: the form
 * reported success, so the row is in the database, and "the id is unknown" is
 * not a reason to keep going — it is the reason the run should be red.
 *
 * Un-annotated parameters for the reason in `created-row-lookup.ts`'s docblock.
 */
function lookupMiss(listUrl = '', key = '', value = '', kind = '') {
    return (
        `the form for a ${kind} reported success, but no row in ${listUrl} carries ${key} ` +
        `"${value}". Its id is therefore unknown, so it cannot be given back, and this test ` +
        'refuses to continue on top of a row it would leak.'
    );
}

/**
 * The id of the row a TEXT create just made, found by its exact value.
 *
 * Registers nothing — the `rememberOwnedRow('<kind>', …)` call belongs at the
 * create site in the spec, with the kind as a LITERAL, because that is what lets
 * the gate in `namespace-isolation.spec.ts` read which kinds a test registers
 * (`UI_CREATE_SITES`). See that file's docblock and
 * `admin-mandant.spec.ts`'s, which says the same about its own call sites.
 */
export async function findCreatedRowId(listUrl = '', key = '', value = '', kind = '') {
    const api = await loginAdminApi();
    try {
        const id = await findRowIdByExactValue(api, listUrl, key, value);
        if (id !== null) {
            return id;
        }
    } finally {
        await api.dispose();
    }
    throw new Error(lookupMiss(listUrl, key, value, kind));
}

/**
 * The same lookup for a NUMERIC column — today `subAccreditations.accreditation_id`,
 * which is the only handle that sub-accreditation has: `SubAccreditationResource`
 * carries `id`, `accreditation_id`, `type`, `quota`, `deadline_*`, `auto_approve`
 * and `active`, and NOT ONE of the others is unique. `type` is `'park'` for
 * every park sub-accreditation in the mandant, so keying on it would make
 * `exactMatchRowId` throw on a second one from another spec's accreditation —
 * which is the helper working, but it would be this test's red for a row it did
 * not create.
 *
 * `accreditation_id` IS unique here, and not by luck: the accreditation was
 * created by the very helper that this test called at its own top
 * (`ensurePrimaryMandantAccreditation`, which registers it), so no other spec
 * hangs a sub-accreditation off it. The moment that stops being true the helper
 * throws instead of deleting somebody else's row — the fail-closed direction.
 */
export async function findCreatedRowIdByNumber(listUrl = '', key = '', value = 0, kind = '') {
    const api = await loginAdminApi();
    try {
        const id = await findRowIdByExactValue(api, listUrl, key, value);
        if (id !== null) {
            return id;
        }
    } finally {
        await api.dispose();
    }
    throw new Error(lookupMiss(listUrl, key, String(value), kind));
}