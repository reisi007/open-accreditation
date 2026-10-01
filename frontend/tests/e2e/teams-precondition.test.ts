import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { ensureTeamsEnabled } from './helpers/teams-enabled';
import type { APIRequestContext } from '@playwright/test';

/**
 * The `teams_enabled` precondition, as behaviour and as structure (Position 38).
 *
 * ## The bug, measured on this machine before the fix
 *
 * `ownership.spec.ts` POSTed to `/api/admin/mandants/{id}/teams` without first
 * switching `teams_enabled` on. `DatabaseSeeder` creates both mandants with
 * `teams_enabled => false` and `TeamController::assertTeamsEnabled()` answers
 * every such POST with **422**, whose body carries no `data`. The spec was
 * therefore red on a fresh database, and green in the full suite only because
 * `admin-mandant.spec.ts` runs earlier and leaves the flag set — green from
 * inherited order, not from construction.
 *
 * The primary mandant was put into the seeder's state through the real API
 * (`PUT /api/admin/mandants/1 {"teams_enabled": false}`) and the spec was run
 * alone with `--workers=1`:
 *
 *     2 failed / 0 passed   (Desktop Chrome + Mobile Chrome)
 *     TypeError: Cannot read properties of undefined (reading 'id')
 *     at ownership.spec.ts:541     // teamId = team.id
 *
 * Note WHERE it failed, because it is not the place the board predicted: not at
 * `expect(refused).not.toBeNull()`, which is never reached — a 422 body has no
 * `data`, so the dereference dies first. Which is why the fix also asserts the
 * POST status: the old code reported a missing object instead of a refused
 * request, and the refusal was the diagnosable half.
 *
 * ## Why there is a vitest half at all
 *
 * The E2E measurement above needs a running backend and a database deliberately
 * moved off its seeded state. That is a real gate and it was run — but it is not
 * the gate that runs on every change, and §4's zero-pre-existing-failures rule is
 * about the cheap gate too. So this file carries it, in two halves:
 *
 * 1. **Behaviour, over a stub server.** The real `ensureTeamsEnabled()` is driven
 *    against a `node:http` server that records what actually arrives: an
 *    already-enabled mandant costs NO request at all, a disabled one produces
 *    exactly one `PUT` with `{"teams_enabled": true}` against the right URL, and a
 *    refused switch throws naming both the status and the mandant. No browser, no
 *    backend, no database.
 * 2. **Structure of the spec.** That `ownership.spec.ts` calls that helper at
 *    ITS OWN site and before the POST, and that neither it nor `admin-data.ts`
 *    carries a second copy of the switch. This half is honest about what it is: it
 *    observes the call, not a request. It is the guard against the silent variant
 *    — helper deleted, `PUT` copied inline again — which is the shape that keeps
 *    a suite green on a fresh database.
 *
 * ## Why importing this helper is affordable at all
 *
 * MEASURED, because it decided where the implementation had to live: importing
 * `@playwright/test` FOR REAL from under Vitest costs **121 s** on this machine
 * (plain `node` importing the same package: **526 ms**). So no vitest test in this
 * repo may touch a module with a runtime Playwright import — `helpers/admin-data.ts`
 * is such a module, which is why the behaviour half drives
 * `helpers/teams-enabled.ts` directly. That file uses `import type`, which is
 * erased, so it has no runtime Playwright dependency at all.
 *
 * The one thing the fake context cannot prove is that Playwright's own `put` takes
 * a JSON body as `{ data }`; that is not re-derived here, it is the shape
 * `loginAdminApi()` uses for its login POST and the shape the inline `PUT` this
 * helper replaced already used.
 */

/** One request the stub server saw. */
interface SeenRequest {
    method: string;
    url: string;
    body: string;
}

const seen: SeenRequest[] = [];
/** Status the server answers with; reset per test. */
let replyStatus = 200;
let server: http.Server;

/**
 * A context whose `put` records the call and answers with `replyStatus`.
 *
 * `APIRequestContext` has dozens of members, so the cast is unavoidable; it is
 * narrowed to the ONE method this helper uses, which is the whole reason the
 * helper takes the context instead of logging in itself.
 */
function recordingContext(): APIRequestContext {
    return {
        put: async (url: string, options: { data: unknown }): Promise<{ status: () => number }> => {
            seen.push({ method: 'PUT', url, body: JSON.stringify(options.data) });
            return { status: () => replyStatus };
        },
    } as unknown as APIRequestContext;
}

/** A context whose `put` performs a REAL HTTP round trip to the local server. */
function networkedContext(origin: string): APIRequestContext {
    return {
        put: async (url: string, options: { data: unknown }): Promise<{ status: () => number }> => {
            const response = await fetch(`${origin}${url}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(options.data),
            });
            return { status: () => response.status };
        },
    } as unknown as APIRequestContext;
}

beforeAll(async () => {
    server = http.createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (chunk: Buffer) => chunks.push(chunk));
        req.on('end', () => {
            seen.push({
                method: req.method ?? '',
                url: req.url ?? '',
                body: Buffer.concat(chunks).toString('utf8'),
            });
            res.writeHead(replyStatus, { 'Content-Type': 'application/json' });
            res.end('{"data":{"teams_enabled":true}}');
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
    replyStatus = 200;
});

describe('the teams_enabled precondition, as behaviour', () => {
    it('costs NO request at all when the mandant already has teams enabled', async () => {
        // The idempotence is why the condition lives INSIDE the helper: every spec
        // that arranges a team would otherwise spend a PUT per call, on every run,
        // for a flag that is already set.
        expect(await ensureTeamsEnabled(recordingContext(), { id: 7, teams_enabled: true })).toBe(false);
        expect(seen).toEqual([]);
    });

    it('treats a missing flag as OFF, because that is what the seeder writes', async () => {
        expect(await ensureTeamsEnabled(recordingContext(), { id: 7 })).toBe(true);
        expect(seen).toHaveLength(1);
    });

    it('switches the flag on with exactly the PUT the backend documents', async () => {
        const switched = await ensureTeamsEnabled(recordingContext(), { id: 7, teams_enabled: false });
        expect(switched).toBe(true);
        expect(seen).toHaveLength(1);
        expect(seen[0].method).toBe('PUT');
        expect(seen[0].url).toBe('/api/admin/mandants/7');
        expect(JSON.parse(seen[0].body)).toEqual({ teams_enabled: true });
    });

    it('addresses the mandant it was handed, not a resolved "primary"', async () => {
        // The helper must not re-resolve the mandant: a caller that deliberately
        // works on a NON-primary mandant would silently get the primary one
        // switched, and the deviation would be invisible in the request.
        await ensureTeamsEnabled(recordingContext(), { id: 42, teams_enabled: false });
        expect(seen[0].url).toBe('/api/admin/mandants/42');
    });

    it('throws naming the status and the mandant when the switch is refused', async () => {
        // A silent failure here is the worse form of the whole position: the switch
        // would be "attempted", the POST below would still 422, and the 422 would
        // again be undiagnosable.
        replyStatus = 422;
        await expect(ensureTeamsEnabled(recordingContext(), { id: 7, teams_enabled: false })).rejects.toThrow(
            /status 422/,
        );
        await expect(ensureTeamsEnabled(recordingContext(), { id: 7, teams_enabled: false })).rejects.toThrow(
            /mandant 7/,
        );
    });

    it('really puts the request on the wire — URL, method and JSON body', async () => {
        // The tests above stub `put`, so they would also pass if the helper called
        // it with the wrong arguments. This one performs a real HTTP round trip and
        // asserts what the SERVER received.
        const address = server.address();
        if (address === null || typeof address === 'string') {
            throw new Error('the stub server has no TCP address');
        }
        await ensureTeamsEnabled(networkedContext(`http://127.0.0.1:${address.port}`), {
            id: 9,
            teams_enabled: false,
        });
        expect(seen).toHaveLength(1);
        expect(seen[0].method).toBe('PUT');
        expect(seen[0].url).toBe('/api/admin/mandants/9');
        expect(JSON.parse(seen[0].body)).toEqual({ teams_enabled: true });
    });

    it('surfaces a refused switch that came back over the wire', async () => {
        const address = server.address();
        if (address === null || typeof address === 'string') {
            throw new Error('the stub server has no TCP address');
        }
        replyStatus = 500;
        await expect(
            ensureTeamsEnabled(networkedContext(`http://127.0.0.1:${address.port}`), {
                id: 9,
                teams_enabled: false,
            }),
        ).rejects.toThrow(/status 500/);
        expect(seen).toHaveLength(1);
    });
});

/** A repo file, resolved from this test's own directory. */
function repoFile(relative: string): string {
    return path.resolve(process.cwd(), relative);
}

const OWNERSHIP_SPEC = fs.readFileSync(repoFile('tests/e2e/ownership.spec.ts'), 'utf8');
const ADMIN_DATA = fs.readFileSync(repoFile('tests/e2e/helpers/admin-data.ts'), 'utf8');
const HELPER = fs.readFileSync(repoFile('tests/e2e/helpers/teams-enabled.ts'), 'utf8');

describe('ownership.spec.ts establishes the precondition at its OWN site', () => {
    it('imports the shared helper rather than copying the PUT', () => {
        expect(OWNERSHIP_SPEC).toMatch(
            /import\s*\{[^}]*\bensureTeamsEnabled\b[^}]*\}\s*from\s*'\.\/helpers\/admin-data'/s,
        );
        expect(
            OWNERSHIP_SPEC,
            'an inline `{ teams_enabled: true }` here would be the second copy of the switch — the thing ' +
                'this helper exists to prevent',
        ).not.toMatch(/teams_enabled\s*:\s*true/);
    });

    it('calls the helper BEFORE the POST it is a precondition of', () => {
        const call = OWNERSHIP_SPEC.indexOf('await ensureTeamsEnabled(api, primary)');
        const post = OWNERSHIP_SPEC.indexOf('api.post(`/api/admin/mandants/${mandantId}/teams`');
        expect(call, 'ownership.spec.ts must establish teams_enabled itself').toBeGreaterThan(-1);
        expect(post).toBeGreaterThan(-1);
        expect(call, 'the switch has to happen before the POST it guards').toBeLessThan(post);
    });

    it('the call and the POST are in the SAME test as the assertion that needs them', () => {
        // Both offsets must sit after this spec's own title, or the guard above
        // would happily accept a call in an unrelated test of the same file.
        const title = OWNERSHIP_SPEC.indexOf('a row the teardown CANNOT delete takes the run down with it');
        expect(title).toBeGreaterThan(-1);
        expect(OWNERSHIP_SPEC.indexOf('await ensureTeamsEnabled(api, primary)')).toBeGreaterThan(title);
        expect(OWNERSHIP_SPEC.indexOf('api.post(`/api/admin/mandants/${mandantId}/teams`')).toBeGreaterThan(
            title,
        );
    });

    it('the POST status is asserted, so a future 422 is named and not dereferenced', () => {
        // Without this the regression returns in its WORSE form: a 422 body has no
        // `data`, so `(await …).json()).data` yields `undefined` and the failure
        // reads as a missing object instead of a refused request.
        const post = OWNERSHIP_SPEC.indexOf('api.post(`/api/admin/mandants/${mandantId}/teams`');
        const dereference = OWNERSHIP_SPEC.indexOf('const team = (await teamCreate.json()).data;');
        expect(post).toBeGreaterThan(-1);
        expect(dereference, 'the response must not be dereferenced blind').toBeGreaterThan(-1);
        expect(OWNERSHIP_SPEC.slice(post, dereference), 'a status assertion must sit between them').toMatch(
            /toBe\(201\)/,
        );
    });

    it('the switch exists ONCE in the whole suite, and only in the helper', () => {
        // The consolidation this position produced: there were TWO inline copies of
        // `if (!primary.teams_enabled) { … }` in `admin-data.ts` — one in
        // `ensurePrimaryMandantHasTeam`, one in `ensurePrimaryMandantActivePortalEvent` —
        // and both now delegate to `ensureTeamsEnabled`, so that file carries none.
        // A count is the guard; a comment is not: the count also covers a third copy
        // added somewhere else later.
        expect(
            ADMIN_DATA.match(/teams_enabled\s*:\s*true/g) ?? [],
            'admin-data.ts delegates; it must not hold its own copy',
        ).toHaveLength(0);
        expect(HELPER.match(/teams_enabled\s*:\s*true/g) ?? []).toHaveLength(1);
    });

    it('does NOT put the switch in global-setup.ts, which swallows its errors', () => {
        // `global-setup.ts:45-47` catches and warns. A switch there could fail
        // silently and turn the 422 back into an invisible precondition — the worse
        // form of the same bug, which the position rules out.
        const globalSetup = fs.readFileSync(repoFile('tests/e2e/global-setup.ts'), 'utf8');
        expect(globalSetup).not.toMatch(/teams_enabled/);
    });
});

describe('the switch is not "fixed" by flipping the flag for everybody', () => {
    it('no spec turns teams on mandant-wide itself', () => {
        // `features/02-domain-model.md` keeps teams OPT-IN per mandant. A global flip
        // would give every mandant in the dev database teams, which is a product
        // default wearing a test's clothes — and it would hide the very regression
        // this position is about. The guard is narrow on purpose: the helper may
        // touch ONE mandant, by id.
        const entries = fs.readdirSync(repoFile('tests/e2e'), { withFileTypes: true });
        const specs = entries
            .filter((entry) => entry.isFile() && entry.name.endsWith('.spec.ts'))
            .map((entry) => fs.readFileSync(repoFile(`tests/e2e/${entry.name}`), 'utf8'));
        expect(specs.length, 'the specs directory was not readable — a vacuous pass').toBeGreaterThan(10);
        for (const spec of specs) {
            expect(spec, 'no spec may PUT teams_enabled itself; that is the helper').not.toMatch(
                /teams_enabled\s*:\s*true/,
            );
        }
    });

    it('the helper writes to a mandant-scoped URL, never a collection endpoint', () => {
        expect(HELPER).toMatch(/api\.put\(`\/api\/admin\/mandants\/\$\{mandant\.id\}`/);
        expect(HELPER).not.toMatch(/api\.put\('\/api\/admin\/mandants'/);
    });
});