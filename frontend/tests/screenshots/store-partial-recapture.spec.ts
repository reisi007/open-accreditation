import { expect, test } from '@playwright/test';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { PREVIOUS_DIR_NAME, storeRouteCapture } from './helpers/capture-store';
import { CAPTURE_STORE_DIR, PLAYWRIGHT_SCRATCH_DIR, captureStoreRoot, playwrightScratchDir } from './helpers/store-paths';

/**
 * The §7 acceptance criterion for a PARTIAL re-capture, as a test.
 *
 * AGENTS.md §7, step 4, prescribes the fix loop:
 *
 *   > Fixes delegieren → Re-Capture **nur der betroffenen Routen**
 *   > (`pnpm test:screenshots -g <routenname>`) → `vision`-Subagent
 *   > vergleicht old vs new
 *
 * The second half of that sentence needs BOTH halves of the comparison to exist
 * on disk afterwards: every UNTOUCHED route's capture byte-for-byte, and the
 * RE-TAKEN route's previous image as the piece it is compared against. This file
 * is the acceptance check for that sentence, and it is deliberately written
 * against `storeRouteCapture` — the composed write path the spec uses — rather
 * than against the lower-level primitives, because the composed path is what
 * step 4 depends on and what a partial run actually exercises.
 *
 * ## What the store's PATH already guarantees, and what it does not
 *
 * The store lives in `test-artifacts/ui-review/`, outside `test-results/`
 * entirely, so the runner's recursive `outputDir` wipe cannot reach it
 * (`helpers/store-paths.ts`, `store-guard.spec.ts`). That is the protection
 * against the runner deleting the evidence, and it is asserted as a property of
 * the paths.
 *
 * It says NOTHING about the store's own behaviour, and that is what used to be
 * unverified: `archiveExisting()` renames the file it replaces into
 * `<dir>/prev/<file>`, and `storePngSeries()` retires a surplus when a page got
 * SHORTER. Both are exactly the places where a well-meant change deletes the
 * wrong thing — a `storeRouteCapture` that forgot to pass `dir`, a sweep whose
 * pattern also matched another route's series, an archive that wrote into the
 * current generation instead of `prev/`. None of that is reachable by asserting
 * a path, so it is asserted here by RUNNING the write.
 *
 * ## Why a scratch directory, and what the scratch is allowed to be
 *
 * Every write goes into `test-results/ui-screenshots/ui-review-store-spec/` —
 * inside the runner's own `outputDir`, which it wipes before the first test.
 * Two consequences, both intended:
 *
 *  - the real review batch is never written to, not even by a failing test, so
 *    the test cannot destroy the evidence of the loop it protects;
 *  - the scratch lives INSIDE the wipe, so if the store's path protection ever
 *    regressed to something under `test-results/`, the store-guard test would go
 *    red on its own. The two are complementary: the guard pins WHERE the store
 *    may be, this pins WHAT the store does once it is there.
 *
 * ## What is deliberately NOT asserted
 *
 * That the captures are pixel-identical across runs. A screenshot is a function
 * of the page, the viewport and the data, and the data is the dev database's —
 * so "identical" is a property of the DATA being fixed (`helpers/dataset.ts`),
 * not of the store. The store's contract is narrower and is the one that was
 * actually broken: *a write touches its own files and archives what it replaced*.
 */

/** Content bytes that are distinguishable per route, so a mix-up cannot hide. */
function bytesFor(route: string, generation: string): Buffer {
    return Buffer.from(`${route}/${generation}`, 'utf8');
}

function sha256(file: string): string {
    return createHash('sha256').update(fs.readFileSync(file)).digest('hex');
}

/** Every file in the tree, relative and sorted, with its digest. */
function fingerprintTree(root: string): Map<string, string> {
    const out = new Map<string, string>();
    const walk = (dir: string): void => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                walk(full);
                continue;
            }
            out.set(path.relative(root, full), sha256(full));
        }
    };
    walk(root);
    return out;
}

/**
 * Whether a stored file belongs to `route` — the four shapes the store writes
 * for a route: the full page, the sidecar, the section series, the printed-page
 * series. Anchored on the whole suffix, so `admin-mandants` does not claim
 * `admin-mandants-new-sec-1.png`.
 */
function belongsToRoute(file: string, route: string): boolean {
    const base = path.basename(file);
    return (
        base === `${route}.png` ||
        base === `${route}.meta.json` ||
        new RegExp(`^${route}-sec-\\d+\\.png$`).test(base) ||
        new RegExp(`^${route}-pdf-\\d+\\.png$`).test(base)
    );
}

function storeInto(dir: string, route: string, generation: string, bandCount: number): void {
    storeRouteCapture({
        state: 'filled',
        viewport: 'desktop',
        route,
        fullPage: bytesFor(route, generation),
        bands: Array.from({ length: bandCount }, (_unused, index) =>
            Buffer.from(`${route}/${generation}/band-${index + 1}`, 'utf8'),
        ),
        measurements: { scrollHeightPx: 1000 + bandCount, viewportHeightPx: 950 },
        runKey: `run-${generation}`,
        dataset: { users: 1 },
        entityIds: {},
        contentCount: bandCount,
        pathname: `/${route}`,
        dir,
    });
}

/**
 * Routes the partial run must leave alone, and one it re-takes.
 *
 * `konto` is in the untouched list on purpose, not as filler: it is the route
 * board position 43 added to the manifest, and its acceptance criterion is
 * literally "a `-g` run for ANY OTHER ROUTE leaves /konto's captures
 * bit-identical". Naming it here makes that criterion a measurement in this file
 * rather than a claim in a commit message — the property is route-agnostic, and
 * a test that never names the newest route would pass just as well if that route
 * had been captured in a directory this run happened not to touch.
 */
const UNTOUCHED = ['home', 'akkreditierungen', 'admin-users', 'admin-mandant-detail', 'konto'] as const;
const RETAKEN = 'admin-categories';

test.describe('a partial re-capture keeps the rest of the batch — §7 step 4', () => {
    // One scratch tree per WORKER PROCESS, created before the first test and
    // removed after the last. A `beforeEach` would wipe the previous test's
    // captures, which is precisely the state these assertions compare against.
    //
    // The per-process path is load-bearing, and it was a measured failure: this
    // file runs under BOTH projects (`Desktop Chrome` and `Mobile Chrome`), and
    // the config sets `workers: 2`, so two `beforeAll` hooks run CONCURRENTLY.
    // With one shared directory the second `rmSync` deleted the tree the first
    // worker was mid-assertion on, and the run reported `1 failed` with no code
    // change at all — a flake manufactured by the test itself. The directory
    // name therefore carries the worker index, and only a name unique per
    // process may be wiped.
    let scratch: string;

    test.beforeAll(({}, workerInfo) => {
        scratch = path.join(playwrightScratchDir(), `ui-review-store-spec-w${workerInfo.workerIndex}`);
        fs.rmSync(scratch, { recursive: true, force: true });
        fs.mkdirSync(scratch, { recursive: true });
    });

    test.afterAll(() => {
        fs.rmSync(scratch, { recursive: true, force: true });
    });

    test('re-taking one route leaves every other capture byte-for-byte, and gives the re-taken one its "old"', () => {
        // Generation 1: a full batch, written exactly as a first full run would.
        for (const route of [...UNTOUCHED, RETAKEN]) {
            storeInto(scratch, route, 'gen1', 2);
        }
        const before = fingerprintTree(scratch);
        expect(before.size, 'the seeded batch must not be empty — an empty comparison proves nothing').toBeGreaterThan(0);

        // The partial run: ONE route, and a different band count in both
        // directions, because the two ways a series can disagree are different
        // code paths (`storeArtifact` per band vs. the surplus sweep).
        storeInto(scratch, RETAKEN, 'gen2-longer', 4);
        const after = fingerprintTree(scratch);

        // 1. Every untouched route is bit-identical. Compared by NAME and DIGEST,
        //    so a file that vanished and one that changed are different failures.
        //
        //    The membership test is the EXACT series a route writes, not a
        //    `startsWith(route)` prefix. The manifest already has route names that
        //    are prefixes of one another — `admin-mandants` / `admin-mandants-new`,
        //    and three routes that all sit on `/admin/badge-templates` — and a
        //    prefix test would silently pull a neighbour's files into this route's
        //    "untouched" set. The failure mode is a false RED the day someone adds
        //    the neighbour, not a false green, which is why it is a comment and not
        //    a separate test.
        for (const route of UNTOUCHED) {
            const owned = [...before.keys()].filter((key) => belongsToRoute(key, route));
            expect(owned.length, `${route} must own files in the seeded batch, or this compares nothing`).toBeGreaterThan(0);
            for (const name of owned) {
                expect(after.get(name), `${name} must still exist after a partial run`).toBe(before.get(name));
            }
        }

        // 2. The re-taken route has its previous image as the comparison piece.
        //    `prev/` is the mechanism (`PREVIOUS_DIR_NAME`), and the assertion is
        //    on CONTENT, not on the file merely existing: an archive that wrote
        //    the new bytes into `prev/` would satisfy a mere existence check.
        const previousFullPage = path.join(scratch, PREVIOUS_DIR_NAME, `${RETAKEN}.png`);
        expect(fs.existsSync(previousFullPage), `the re-taken route must have an "old" at ${previousFullPage}`).toBe(true);
        expect(fs.readFileSync(previousFullPage).toString('utf8')).toBe(bytesFor(RETAKEN, 'gen1').toString('utf8'));

        // 3. The current generation is the NEW capture, not the old one.
        expect(fs.readFileSync(path.join(scratch, `${RETAKEN}.png`)).toString('utf8')).toBe(
            bytesFor(RETAKEN, 'gen2-longer').toString('utf8'),
        );

        // 4. The previous generation's bands survive too — the vision subagent
        //    compares bands, not just the full page (§7: a page several
        //    viewports tall is unreadable as one scaled image).
        for (const index of [1, 2]) {
            const previousBand = path.join(scratch, PREVIOUS_DIR_NAME, `${RETAKEN}-sec-${index}.png`);
            expect(fs.existsSync(previousBand), `band ${index} of the previous generation must survive`).toBe(true);
            expect(fs.readFileSync(previousBand).toString('utf8')).toBe(
                Buffer.from(`${RETAKEN}/gen1/band-${index}`, 'utf8').toString('utf8'),
            );
        }

        // 5. The new bands are there, and `prev/` holds exactly ONE generation:
        //    a second partial run replaces the comparison piece rather than
        //    growing an unbounded history (the store's documented one-deep rule).
        for (const index of [1, 2, 3, 4]) {
            expect(fs.existsSync(path.join(scratch, `${RETAKEN}-sec-${index}.png`)), `new band ${index}`).toBe(true);
        }
        expect(fs.existsSync(path.join(scratch, PREVIOUS_DIR_NAME, `${RETAKEN}-sec-3.png`))).toBe(false);
    });

    test('a page that got SHORTER retires the surplus into prev/ instead of leaving a stale band behind', () => {
        // The other direction, and it is a different code path: `storePngSeries`
        // sweeps the series for indices the new capture does not have and
        // archives each one. A sweep that deleted instead of archived would pass
        // every test above and break exactly the comparison this file exists for
        // — a page that shrank is the moment a reviewer most wants the old bands.
        const dir = path.join(scratch, 'shrinking');
        fs.rmSync(dir, { recursive: true, force: true });
        fs.mkdirSync(dir, { recursive: true });

        storeRouteCapture({
            state: 'filled',
            viewport: 'desktop',
            route: 'admin-freigaben',
            fullPage: bytesFor('admin-freigaben', 'tall'),
            bands: [1, 2, 3, 4, 5].map((index) => Buffer.from(`tall/band-${index}`, 'utf8')),
            measurements: { scrollHeightPx: 5000, viewportHeightPx: 950 },
            runKey: 'run-tall',
            dataset: { users: 1 },
            entityIds: {},
            contentCount: 5,
            pathname: '/admin/freigaben',
            dir,
        });
        storeRouteCapture({
            state: 'filled',
            viewport: 'desktop',
            route: 'admin-freigaben',
            fullPage: bytesFor('admin-freigaben', 'short'),
            bands: [1, 2].map((index) => Buffer.from(`short/band-${index}`, 'utf8')),
            measurements: { scrollHeightPx: 2000, viewportHeightPx: 950 },
            runKey: 'run-short',
            dataset: { users: 1 },
            entityIds: {},
            contentCount: 2,
            pathname: '/admin/freigaben',
            dir,
        });

        expect(fs.existsSync(path.join(dir, 'admin-freigaben-sec-5.png')), 'band 5 is gone from the current generation').toBe(false);
        const retired = path.join(dir, PREVIOUS_DIR_NAME, 'admin-freigaben-sec-5.png');
        expect(fs.existsSync(retired), 'band 5 is ARCHIVED, not deleted — the reviewer needs it').toBe(true);
        expect(fs.readFileSync(retired).toString('utf8')).toBe('tall/band-5');
    });

    test('the scratch this file writes to is inside the runner wipe, and the real store is outside it', () => {
        // The premise the two halves rest on, asserted rather than assumed:
        //
        //  - THIS file's scratch must be inside `test-results/`, so it can never
        //    be mistaken for the review batch and is cleaned up by the runner
        //    even if the process dies;
        //  - the review batch must be outside `test-results/` entirely, which is
        //    what stops a partial run from deleting it (the 95 → 4 PNG
        //    measurement, and `store-guard.spec.ts` owns that invariant).
        //
        // Written as a containment check on the resolved paths rather than as a
        // string comparison, because the string comparison is the version that
        // passed for `test-results/ui-review/` (a sibling of the scratch, i.e.
        // outside the scratch but inside the tree the runner wipes).
        const resolvedScratch = path.resolve(process.cwd(), PLAYWRIGHT_SCRATCH_DIR);
        const resolvedStore = captureStoreRoot();
        expect(
            resolvedStore.startsWith(`${resolvedScratch}${path.sep}`) || resolvedStore === resolvedScratch,
            'this spec writes into the scratch space, so the real store must NOT be inside it',
        ).toBe(false);
        expect(
            resolvedStore.startsWith(`${path.resolve(process.cwd(), 'test-results')}${path.sep}`),
            'the review batch must live outside test-results/ — that is the protection against a partial run',
        ).toBe(false);
        expect(CAPTURE_STORE_DIR.split('/')[0]).not.toBe('test-results');
    });
});
