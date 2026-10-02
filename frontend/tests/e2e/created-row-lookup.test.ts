import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { exactMatchRowId, findRowIdByExactValue } from './helpers/created-row-lookup';
import type { APIRequestContext } from '@playwright/test';

/**
 * The created-row lookup behind the per-test ownership ledger, as behaviour and
 * as structure (Position 22).
 *
 * ## What it is
 *
 * A create FORM answers no id, so the row has to be found afterwards under the
 * only handle the test has — the name it typed, which carries a
 * `uniqueSuffix()` stamp and is therefore worker-unique. This file pins that
 * lookup, because the whole position rests on it: if it matches the wrong row,
 * the teardown deletes a sibling's fixture while the real one survives, which is
 * worse than registering nothing.
 *
 * ## Why a vitest half at all, when the E2E specs use it for real
 *
 * The E2E measurement is real and was run, but it needs a browser, a backend and
 * a deliberately damaged ledger — it is the gate that proves the position landed,
 * not the gate that runs on every change. The three properties that matter can be
 * driven against a stub server in milliseconds, with no browser and no database,
 * which is the same split `tests/e2e/teams-precondition.test.ts` documents:
 * behaviour here, real execution there.
 *
 * ## Why importing this module is affordable at all
 *
 * It uses `import type` for `APIRequestContext`, which is erased, so it has NO
 * runtime dependency on `@playwright/test`. Importing that package for real from
 * under Vitest costs **121 s** on this machine (plain `node`: 526 ms). That is
 * exactly why the self-logging wrappers (`helpers/created-row.ts`) live in
 * another module — they DO import the session — and why no test here touches
 * them. What is covered instead is the part they delegate to, plus their shared
 * message, structurally, in the second `describe`.
 */

/** One request the stub server saw. */
interface SeenRequest {
    method: string;
    url: string;
    body: string;
}

const seen: SeenRequest[] = [];
/** The list the stub server answers, as a raw JSON body. */
let replyBody = '{"data":[]}';
/** Status the server answers with; reset per test. */
let replyStatus = 200;
let server: http.Server;

/**
 * A context whose `get` answers from `replyStatus`/`replyBody` and records the
 * URL, so a test can assert WHICH route the lookup asked.
 *
 * `APIRequestContext` has dozens of members, so the cast is unavoidable; it is
 * narrowed to the one method this helper uses — the same shape
 * `teams-precondition.test.ts` documents for `put`.
 */
function recordingContext(): APIRequestContext {
    return {
        get: async (url: string): Promise<{ status: () => number; json: () => Promise<unknown> }> => {
            seen.push({ method: 'GET', url, body: '' });
            return {
                status: () => replyStatus,
                json: async () => JSON.parse(replyBody) as unknown,
            };
        },
    } as unknown as APIRequestContext;
}

/** A context whose `get` performs a REAL HTTP round trip to the local server. */
function networkedContext(origin: string): APIRequestContext {
    return {
        get: async (url: string): Promise<{ status: () => number; json: () => Promise<unknown> }> => {
            const response = await fetch(`${origin}${url}`);
            const text = await response.text();
            return { status: () => response.status, json: async () => JSON.parse(text) as unknown };
        },
    } as unknown as APIRequestContext;
}

beforeAll(async () => {
    server = http.createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            seen.push({ method: req.method ?? '', url: req.url ?? '', body: Buffer.concat(chunks).toString('utf8') });
            res.writeHead(replyStatus, { 'Content-Type': 'application/json' });
            res.end(replyBody);
        });
    });
    await new Promise<void>((resolve) => {
        server.listen(0, '127.0.0.1', resolve);
    });
});

afterAll(async () => {
    await new Promise<void>((resolve) => {
        server.close(() => resolve());
    });
});

beforeEach(() => {
    seen.length = 0;
    replyBody = '{"data":[]}';
    replyStatus = 200;
});

/** The TCP address of the stub server, as `http://127.0.0.1:<port>`. */
function stubOrigin(): string {
    const address = server.address();
    if (address === null || typeof address === 'string') {
        throw new Error('the stub server has no TCP address');
    }
    return `http://127.0.0.1:${address.port}`;
}

describe('the exact match the created-row lookup is built on', () => {
    it('returns the id of the one row whose value is EXACTLY the one it was given', () => {
        const rows = [
            { id: 4, name: 'E2E Kategorie w1-p42-1789000000000' },
            { id: 9, name: 'Presse' },
        ];
        expect(exactMatchRowId(rows, 'name', 'E2E Kategorie w1-p42-1789000000000')).toBe(4);
    });

    it('reads any column, not only a column called `name`', () => {
        // `events` is keyed by `title`, `teams` by `slug` would also work, and
        // `blacklists` by `email`. A helper that hard-coded `name` would have
        // forced a second one for the other three.
        const rows = [{ id: 11, title: 'E2E Event w1-p42-1789000000000' }];
        expect(exactMatchRowId(rows, 'title', 'E2E Event w1-p42-1789000000000')).toBe(11);
    });

    it('returns null when no row carries the value', () => {
        expect(exactMatchRowId([{ id: 1, name: 'Presse' }], 'name', 'E2E Kategorie w1')).toBeNull();
    });

    it('does NOT answer a row whose value merely STARTS WITH the one it was given', () => {
        // The measured reason this helper exists at all: `uniqueSuffix()` stamps
        // every name, so `…-1789000000000` is a PREFIX of a sibling worker's
        // `…-17890000000001`, and a prefix search would register THAT worker's id.
        const rows = [{ id: 77, name: 'E2E Kategorie w1-p42-17890000000001' }];
        expect(exactMatchRowId(rows, 'name', 'E2E Kategorie w1-p42-1789000000000')).toBeNull();
    });

    it('does NOT answer a row whose value merely CONTAINS the one it was given', () => {
        // `admin-event.spec.ts` renames its event, and `editedTitle` CONTAINS
        // `uniqueTitle` — so a substring search would match the renamed row and, on
        // a list with both, pick the wrong one.
        const rows = [{ id: 5, title: 'E2E Event w1-p42-1 bearbeitet' }];
        expect(exactMatchRowId(rows, 'title', 'E2E Event w1-p42-1')).toBeNull();
    });

    it('does NOT coerce a number into its string, or the other way round', () => {
        // `SubAccreditationResource.accreditation_id` is a NUMBER. A comparison
        // that stringified both sides would also make the literal `'null'` match a
        // null column — so the equality is strict and stays strict.
        expect(exactMatchRowId([{ id: 3, accreditation_id: 41 }], 'accreditation_id', '41')).toBeNull();
        expect(exactMatchRowId([{ id: 3, accreditation_id: '41' }], 'accreditation_id', 41)).toBeNull();
    });

    it('REFUSES to choose between two rows that carry the identical value', () => {
        // `GET /api/admin/mandants` is global for a `super_admin`, and the dev
        // database really does hold two same-named mandants — `admin-mandant-switch.spec.ts`
        // asserts two rows for one name on purpose. Picking the first would hand
        // the teardown a FOREIGN id.
        const rows = [
            { id: 1, mandant_id: 1, name: 'Hauptseite' },
            { id: 8, mandant_id: 2, name: 'Hauptseite' },
        ];
        expect(() => exactMatchRowId(rows, 'name', 'Hauptseite')).toThrow(/refusing to guess/);
        expect(() => exactMatchRowId(rows, 'name', 'Hauptseite')).toThrow(/2 rows/);
    });

    it('is not fooled by a row that is not an object, or a non-array list', () => {
        expect(exactMatchRowId([null, 'text', 42, { id: 1, name: 'Presse' }], 'name', 'Presse')).toBe(1);
        expect(exactMatchRowId(null, 'name', 'Presse')).toBeNull();
        expect(exactMatchRowId({ data: [] }, 'name', 'Presse')).toBeNull();
    });

    it('refuses a row whose id is not a number, rather than handing it to the teardown', () => {
        // The ledger addresses rows by id (`DELETE …/{id}`); an id it cannot use
        // would turn the teardown's DELETE into a 404 it counts as reclaimed.
        expect(() => exactMatchRowId([{ id: null, name: 'Presse' }], 'name', 'Presse')).toThrow(/numeric id/);
    });
});

describe('the lookup asks the list route and turns its answer into an id', () => {
    it('reads `data` out of the list and matches on it', async () => {
        replyBody = JSON.stringify({ data: [{ id: 12, name: 'E2E Team w1-p42-7' }] });
        await expect(findRowIdByExactValue(recordingContext(), '/api/admin/categories', 'name', 'E2E Team w1-p42-7')).resolves.toBe(
            12,
        );
        expect(seen).toHaveLength(1);
    });

    it('asks the mandant-scoped route it was handed, and never a wider one', async () => {
        // The cross-mandant guarantee is a property of the ROUTE, not of the
        // comparison: MEASURED on the backend, `CategoryController::index`,
        // `EventController::index`, `VenueController::index`,
        // `BlacklistController::index` and `SubAccreditationController::indexAll`
        // all read `->forMandant($this->currentMandantId())`. A helper that
        // "helpfully" widened a mandant-scoped kind to a global list would destroy
        // that.
        await findRowIdByExactValue(recordingContext(), '/api/admin/mandants/1/teams', 'name', 'E2E Team w1-p42-7');
        expect(seen[0].url).toBe('/api/admin/mandants/1/teams');
    });

    it('reports the STATUS of a list it could not read, instead of "not found"', async () => {
        // The distinction is the whole point: a throttled login, an expired session
        // and a gate all produce a body with no `data`, and read past they would
        // all say "no row carries that value" — blaming the form for a refusal by
        // the API. Same reason `resolveUserIdByEmail` checks its own status.
        replyStatus = 429;
        await expect(
            findRowIdByExactValue(recordingContext(), '/api/admin/categories', 'name', 'E2E Kategorie w1'),
        ).rejects.toThrow(/answered 429/);
    });

    it('treats a `data` that is not an array as "no match", not as a crash', async () => {
        replyBody = '{"data":null}';
        await expect(
            findRowIdByExactValue(recordingContext(), '/api/admin/categories', 'name', 'E2E Kategorie w1'),
        ).resolves.toBeNull();
        replyBody = '{"message":"nope"}';
        await expect(
            findRowIdByExactValue(recordingContext(), '/api/admin/categories', 'name', 'E2E Kategorie w1'),
        ).resolves.toBeNull();
    });

    it('really GETs the route over the wire and reads the server body', async () => {
        // The tests above stub `get`, so they would also pass if the helper called
        // it with the wrong argument. This one puts the request on the wire and
        // asserts what the SERVER saw — the same trick as
        // `teams-precondition.test.ts`'s equivalent for `put`.
        replyBody = JSON.stringify({ data: [{ id: 33, title: 'E2E Event w1-p42-7' }] });
        await expect(
            findRowIdByExactValue(networkedContext(stubOrigin()), '/api/admin/events', 'title', 'E2E Event w1-p42-7'),
        ).resolves.toBe(33);
        expect(seen).toHaveLength(1);
        expect(seen[0].method).toBe('GET');
        expect(seen[0].url).toBe('/api/admin/events');
    });
});

/** A repo file, resolved from this test's own directory. */
function repoFile(relative: string): string {
    return path.resolve(process.cwd(), relative);
}

const ADMIN_MANDANT = fs.readFileSync(repoFile('tests/e2e/admin-mandant.spec.ts'), 'utf8');

describe('the lookup exists ONCE, and every UI-creating spec imports it', () => {
    it('admin-mandant.spec.ts imports the shared helper instead of keeping its own copy', () => {
        // This spec was the lookup's FIRST user and held it as a private function.
        // Position 22 needed the same thing in five more specs; a second copy would
        // be a second truth about the one rule that decides whether a UI-created
        // row can be given back — and `namespace-isolation.spec.ts`'s
        // `UI_CREATE_SITES` table cannot see two copies, because both satisfy it.
        expect(ADMIN_MANDANT).toMatch(/import\s*\{[^}]*\bfindCreatedRowId\b[^}]*\}\s*from\s*'\.\/helpers\/created-row'/s);
        expect(
            ADMIN_MANDANT,
            'a second copy of the lookup in a spec is the drift this consolidation exists to prevent',
        ).not.toMatch(/(async\s+)?function\s+findCreatedRowId\b/);
    });

    it('the five specs Position 22 named import the same helper, and register the kind as a LITERAL', () => {
        // The literal is not style: `UI_CREATE_SITES` reads which kinds a test
        // registers by matching `rememberOwnedRow('kind'`, so a generic
        // `rememberOwnedRow(kind, …)` would satisfy nothing and the helper would
        // have to carry the kind itself — which the gate could not read.
        const specs = [
            'admin-category.spec.ts',
            'admin-event.spec.ts',
            'admin-venue.spec.ts',
            'approvals.spec.ts',
            'admin-mandant.spec.ts',
        ];
        const distinct = new Map<string, string[]>();
        const perKind = new Map<string, string[]>();
        for (const file of specs) {
            const source = fs.readFileSync(repoFile(`tests/e2e/${file}`), 'utf8');
            expect(source, `${file} must import the shared lookup`).toMatch(
                /import\s*\{[^}]*\bfindCreatedRowId\b[^}]*\}\s*from\s*'\.\/helpers\/created-row'/s,
            );
            const kinds = [...source.matchAll(/rememberOwnedRow\(\s*(['"])([A-Za-z]+)\1/g)].map((match) => match[2]);
            perKind.set(file, kinds);
            distinct.set(
                file,
                [...new Set(kinds)].sort(),
            );
        }
        expect(distinct.get('admin-category.spec.ts')).toEqual(['categories']);
        expect(distinct.get('admin-event.spec.ts')).toEqual(['events']);
        expect(distinct.get('admin-venue.spec.ts')).toEqual(['teams', 'venues']);
        expect(distinct.get('approvals.spec.ts')).toEqual(['blacklists', 'subAccreditations']);
        expect(distinct.get('admin-mandant.spec.ts')).toEqual(['mandantDomains', 'mandants', 'teams']);

        // …and the COUNTS, which are the number Position 22 is about: a create
        // form answers no id, so every row needs its own registration, and a spec
        // that creates three rows and registers two has still left one to the
        // sweep. `admin-venue.spec.ts` is the interesting one — two venues in two
        // tests plus a team — and `admin-mandant.spec.ts` is the reference
        // implementation the others were built from (3 creates, 5 registrations:
        // the third test creates its mandant and domain through the API, where
        // the id is in the response).
        expect(perKind.get('admin-category.spec.ts')).toEqual(['categories', 'categories']);
        expect(perKind.get('admin-event.spec.ts')).toEqual(['events']);
        expect(perKind.get('admin-venue.spec.ts')).toEqual(['venues', 'venues', 'teams']);
        expect(perKind.get('approvals.spec.ts')).toEqual(['blacklists', 'subAccreditations']);
        expect(perKind.get('admin-mandant.spec.ts')).toHaveLength(5);
    });

    it('the numeric wrapper exists for the one column that needs it, and the specs do not hand-roll one', () => {
        // `SubAccreditationResource` has no unique name column, so
        // `approvals.spec.ts` keys on `accreditation_id`. If a second spec ever
        // needed a numeric key it must use `findCreatedRowIdByNumber`, not copy it.
        const wrapper = fs.readFileSync(repoFile('tests/e2e/helpers/created-row.ts'), 'utf8');
        expect(wrapper.match(/export async function findCreatedRowIdByNumber\(/g) ?? []).toHaveLength(1);
        expect(ADMIN_MANDANT).not.toMatch(/findCreatedRowIdByNumber/);
        const approvals = fs.readFileSync(repoFile('tests/e2e/approvals.spec.ts'), 'utf8');
        expect(approvals).toMatch(/findCreatedRowIdByNumber\(\s*'\/api\/admin\/sub-accreditations'/);
    });

    it('the lookup refuses rather than registering something it cannot name', () => {
        // A comment in a spec saying "this row is registered" is worth nothing,
        // which is the F2 shape. What is worth something is that the code the
        // registration depends on has no non-throwing branch: `findRowIdByExactValue`
        // throws on a status it cannot read and on an ambiguous match, and the
        // only `return` of an id is the one exact hit.
        const lookup = fs.readFileSync(repoFile('tests/e2e/helpers/created-row-lookup.ts'), 'utf8');
        const code = lookup.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
        expect(code.match(/throw new Error\(/g) ?? []).toHaveLength(3);
        expect(code).not.toMatch(/console\.(warn|log)/);
    });
});