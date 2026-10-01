import type { APIRequestContext } from '@playwright/test';

/**
 * The `teams_enabled` precondition, in one place.
 *
 * ## What it is for (Position 38)
 *
 * `POST /api/admin/mandants/{id}/teams` answers **422** while the mandant has
 * `teams_enabled => false`, which is exactly how `DatabaseSeeder` creates both
 * mandants. A spec that wants a team to EXIST has to switch the flag on first,
 * and two specs that both need that switch must not each carry their own copy
 * of the `PUT`: two copies are two truths, and the second one is the copy
 * nobody re-reads.
 *
 * `ownership.spec.ts` used to rely on the flag being set for it. MEASURED
 * 2026-10-01 on this machine, with the primary mandant put into the seeder's
 * state through the real API
 * (`PUT /api/admin/mandants/1 {"teams_enabled": false}`) and the spec run alone
 * (`--workers=1`): **2 failed / 0 passed** (one per browser project), at
 * `ownership.spec.ts:541`, with
 * `TypeError: Cannot read properties of undefined (reading 'id')` — a 422 body
 * carries no `data`, so the old `(await …).json()).data` never saw a team and
 * never saw a refusal either. In the full suite the spec is green because
 * `admin-mandant.spec.ts` runs earlier and leaves the flag on: green from
 * inherited order, not from construction.
 *
 * ## Why it is its own module and not a line inside `admin-data.ts`
 *
 * Because of how this directory is linted. MEASURED on this repo:
 * `eslint.config.js` gives `tests/e2e/**` the PLAIN-JS parser — a parameter
 * annotation in a file there is a PARSE ERROR (`Parsing error: Unexpected token
 * :`) — so a `.ts` file in this directory can only type a parameter through its
 * DEFAULT VALUE, which cannot produce an `APIRequestContext`. JSDoc does not
 * help either: `tests/e2e/helpers/__probe` with an unannotated parameter and a
 * `@param {string}` JSDoc failed `tsc` with `TS7006: Parameter 'value'
 * implicitly has an 'any' type`, because JSDoc types are only read in `.js`
 * files.
 *
 * So this file takes a typed parameter and is listed in the TS-parser block of
 * `eslint.config.js` (next to `tests/screenshots/**`, which got the same
 * treatment for the same reason). `import type` is erased at compile time, which
 * also means this module has **no runtime dependency on `@playwright/test`** —
 * measured, and the reason `tests/e2e/teams-precondition.test.ts` can drive the
 * real function against a stub server inside `pnpm test:run`. Importing
 * `@playwright/test` for REAL from under Vitest costs **121 s** on this machine
 * (vs **526 ms** in plain `node`), which is why no vitest test in this repo
 * touches a module that does.
 *
 * ## Why the flag is not restored afterwards
 *
 * It is not this suite's flag to put back: the mandant is shared, several specs
 * and the screenshot harness need teams enabled on it, and `migrate:fresh`
 * (via `scripts/e2e-up.sh`) is what resets it. Nothing here changes the product
 * default — `features/02-domain-model.md` keeps teams OPT-IN per mandant and the
 * seeder still writes `false`. The caller decides the mandant; this only turns
 * the switch on for the row it was handed.
 */
export async function ensureTeamsEnabled(
    api: APIRequestContext,
    mandant: { id: number; teams_enabled?: boolean | null },
): Promise<boolean> {
    if (mandant.teams_enabled) {
        return false;
    }
    const enable = await api.put(`/api/admin/mandants/${mandant.id}`, { data: { teams_enabled: true } });
    if (enable.status() !== 200) {
        throw new Error(
            `Enabling teams for mandant ${mandant.id} failed with status ${enable.status()} — ` +
                '`POST /api/admin/mandants/{id}/teams` answers 422 while the flag is off, so a caller that ' +
                'skipped this step gets an empty 422 body instead of a team, and no error naming the flag.',
        );
    }
    return true;
}