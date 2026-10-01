import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { afterEach, describe, expect, it } from 'vitest';
import { CAPTURE_STORE_DIR, PLAYWRIGHT_SCRATCH_DIR } from '../tests/screenshots/helpers/store-paths';
import { DEFAULT_DIR, PREVIOUS_DIR, buildReport, isReproducible, parseArgs } from './ui-review-captures.mjs';

/**
 * The bridge between the capture-store path and the report that reads it — and,
 * since the report itself was untested, between the sidecars on disk and the
 * numbers a reviewer reads off them.
 *
 * ## The gap this exists to close (F2)
 *
 * `scripts/ui-review-captures.mjs` restates the capture-store path so it can run
 * as a standalone ESM script, and its comment used to claim that the two copies
 * were "kept honest by `store-guard.spec.ts`". That test exists, and it does not
 * do that: it imports `CAPTURE_STORE_DIR` and asserts properties of the resolved
 * paths. Nothing in the repo ever read the `.mjs`, which is why
 * `tsc -b` could not have caught a divergence either — it does not type-check a
 * string literal inside plain ESM.
 *
 * The consequence was not a compile error, it was a **green suite reporting on
 * the wrong directory**: with `DEFAULT_DIR` aimed at a path that does not exist,
 * the entire screenshot suite and every E2E spec still passed, and the band-count
 * report — the one artefact §7's fix loop depends on for its Δ — described a
 * store that was never written.
 *
 * ## Why vitest and not a Playwright spec
 *
 * The same pattern is already established for the sibling guard:
 * `scripts/po-catalog.test.ts` unit-tests `scripts/po-catalog.mjs`, and
 * `vitest.config.ts` includes every `test.ts` under `scripts/`. So this needs no
 * new wiring, it runs in `pnpm test:run` — the gate that runs on every change —
 * and it needs neither a dev server nor a browser. A Playwright spec would be a
 * heavier home for a string comparison and would only run in the E2E suite.
 *
 * ## What it does and does not prove
 *
 * The first block proves the two values are the same string AND that the script
 * actually uses its own constant as the default (the second assertion is what
 * stops the "declared but shadowed by a third literal" variant). It does not prove
 * the store is where the captures land — that is `store-guard.spec.ts`, and the two
 * are complementary: one pins the paths' properties, this one pins the
 * agreement between the module and the script that reports on it.
 *
 * The second block covers the REPORT, which is the half that had no test at all.
 * §7's acceptance criterion 1 is "three runs in a row produce the same section
 * count, **and the number is written next to the images**" — and a number whose
 * producer has never been executed is a number nobody has checked. The store here
 * is SYNTHETIC (written into a temp dir with known sidecars), which is what makes
 * the comparison testable: the previous-generation lookup, the delta, the total,
 * the `--only` filter and the reproducibility verdict are all reachable without a
 * browser, a backend and a database.
 *
 * ## The limit of the reproducibility verdict, stated where it is used
 *
 * `isReproducible()` says the page heights did not move between two generations
 * of the store. It cannot say the DATA behind them is fixed — that is
 * `helpers/dataset.ts`'s job, and a stable band count over foreign data is
 * exactly the "green by coincidence" shape this file's sibling tests exist to
 * rule out. So the verdict is a line in a report, not an exit code, and the test
 * below pins that it refuses to call a store with no predecessor reproducible.
 */

/** A temp store that cleans itself up, so a failed assertion leaves nothing behind. */
const created: string[] = [];

function makeStore(): string {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ui-review-report-test-'));
    created.push(root);
    return root;
}

/** The report script's own path, resolved from this test's directory. */
function scriptPath(): string {
    return path.resolve(process.cwd(), 'scripts/ui-review-captures.mjs');
}

afterEach(() => {
    while (created.length > 0) {
        const dir = created.pop();
        if (dir !== undefined) {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    }
});

interface Sidecar {
    route: string;
    bands: number;
    scrollHeightPx?: number;
    runKey?: string;
    capturedAt?: string;
    compareWith?: string;
}

/**
 * Writes one sidecar, and optionally the PREVIOUS generation next to it — the
 * shape `capture-store.ts` produces (`<dir>/prev/<route>.meta.json`).
 *
 * The fixture writes `state` and `viewport` INTO the sidecar rather than leaving
 * the report to infer them from the directory names, because that is what the
 * real store does (`CaptureMeta` carries both) and the report reads them from the
 * sidecar. A first version of this file omitted them, and the test failed with
 * `undefined/undefined/home` — which is the right outcome: the fixture, not the
 * report, was wrong, and the failure named it in one line.
 *
 * The previous generation goes through the same helper rather than inline,
 * because writing it wrong is the mistake this file exists to catch: a report that
 * read its own current file as its predecessor would report every delta as 0.
 */
function writeCapture(
    root: string,
    state: string,
    viewport: string,
    meta: Sidecar,
    previous?: Sidecar,
): void {
    const dir = path.join(root, state, viewport);
    fs.mkdirSync(dir, { recursive: true });
    const full = {
        state,
        viewport,
        scrollHeightPx: 2000,
        sectionScrollStep: 0.8,
        runKey: 'run-test',
        capturedAt: '2026-10-01T00:00:00.000Z',
        ...meta,
    };
    fs.writeFileSync(path.join(dir, `${meta.route}.meta.json`), `${JSON.stringify(full)}\n`);
    if (previous === undefined) {
        return;
    }
    const prevDir = path.join(dir, PREVIOUS_DIR);
    fs.mkdirSync(prevDir, { recursive: true });
    const prevFull = {
        state,
        viewport,
        scrollHeightPx: 2000,
        sectionScrollStep: 0.8,
        runKey: 'run-before',
        capturedAt: '2026-09-30T00:00:00.000Z',
        ...previous,
    };
    fs.writeFileSync(path.join(prevDir, `${previous.route}.meta.json`), `${JSON.stringify(prevFull)}\n`);
}

describe('the capture-report script and the capture-store module name the same directory', () => {
    it('uses the same store path as CAPTURE_STORE_DIR', () => {
        expect(DEFAULT_DIR).toBe(CAPTURE_STORE_DIR);
    });

    it('really defaults to that constant, not to a second literal', () => {
        // Without this, a script could export the right constant and still parse
        // its arguments against a different one — the declaration would look
        // maintained while the behaviour was not.
        expect(parseArgs([]).dir).toBe(DEFAULT_DIR);
    });

    it('reports on the store, not on Playwright scratch space', () => {
        // The failure F2 measured was a store pointed INTO `test-results/`, which
        // is the one directory Playwright wipes before the first test. Assert the
        // relationship rather than restate the rule, so a future move of either
        // path is caught here too.
        expect(DEFAULT_DIR).not.toBe(PLAYWRIGHT_SCRATCH_DIR);
        expect(DEFAULT_DIR.startsWith('test-results/')).toBe(false);
    });

    it('an explicit --dir still wins over the default', () => {
        // Otherwise the fix could be "hardcode the store everywhere", which would
        // make the equality above pass for the wrong reason.
        expect(parseArgs(['--dir', 'somewhere-else']).dir).toBe('somewhere-else');
    });

    it('rejects an unknown argument instead of ignoring it', () => {
        // The alternative is a typo in a flag that silently widens or narrows the
        // report, and the reviewer's only clue would be a missing row.
        expect(() => parseArgs(['--bandz', '3'])).toThrow(/unbekanntes Argument/);
        expect(() => parseArgs(['--dir'])).toThrow(/braucht einen Wert/);
    });
});

describe('the band-count report — the number §7 requires next to the images', () => {
    it('reads the current sidecars and finds NO predecessor in a first run', () => {
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 });
        writeCapture(root, 'filled', 'mobile', { route: 'home', bands: 3 });

        const report = buildReport(root);
        expect(report.captures).toBe(2);
        expect(report.totalBands).toBe(5);
        expect(report.hasComparison).toBe(false);
        expect(report.changed).toEqual([]);
        for (const row of report.rows) {
            expect(row.previousBands, 'a first run has nothing to compare against').toBeNull();
            expect(row.delta, 'and therefore no delta').toBeNull();
        }
    });

    it('reads the PREVIOUS generation out of prev/ and reports the delta against it', () => {
        // The regression this pins: a report that resolved `previous` to the
        // current file would report every delta as 0, and a batch that had grown
        // by eleven bands would read as stable. `prev/` is written by a separate
        // call so the two generations cannot be the same object.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 5 }, { route: 'home', bands: 2 });

        const report = buildReport(root);
        const [row] = report.rows;
        expect(row.bands).toBe(5);
        expect(row.previousBands).toBe(2);
        expect(row.delta).toBe(3);
        expect(report.hasComparison).toBe(true);
        expect(report.totalBands).toBe(5);
        expect(report.totalPreviousBands).toBe(2);
        expect(report.changed).toHaveLength(1);
        expect(report.changed[0].route).toBe('home');
    });

    it('a page that got SHORTER reports a negative delta — the direction that reads as "same" when it is 0-clamped', () => {
        // `Math.max(0, …)` here would make a shrinking page look stable, and a
        // shrinking page is a band count that went somewhere a reviewer must see.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'admin-users', bands: 1 }, { route: 'admin-users', bands: 4 });

        const report = buildReport(root);
        expect(report.rows[0].delta).toBe(-3);
        expect(report.changed).toHaveLength(1);
    });

    it('does NOT count an unchanged capture as changed', () => {
        // The `delta === 0` case is the whole point of the comparison: three runs
        // at 24 bands each are reproducible, and a report that listed all 24 of
        // them as "Geändert" would drown the one row that moved.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 4 }, { route: 'home', bands: 4 });
        writeCapture(root, 'filled', 'desktop', { route: 'login', bands: 1 }, { route: 'login', bands: 1 });

        const report = buildReport(root);
        expect(report.changed).toEqual([]);
        expect(report.totalBands).toBe(5);
        expect(report.totalPreviousBands).toBe(5);
    });

    it('orders the rows by state/viewport/route so two reports can be diffed line by line', () => {
        // A report whose order depends on `readdir` is not comparable with the
        // one before it, which would defeat the purpose of printing it.
        const root = makeStore();
        writeCapture(root, 'filled', 'mobile', { route: 'home', bands: 3 });
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 });
        writeCapture(root, 'empty', 'desktop', { route: 'home', bands: 1 });
        writeCapture(root, 'filled', 'desktop', { route: 'akkreditierungen', bands: 1 });

        const report = buildReport(root);
        expect(report.rows.map((row) => `${row.state}/${row.viewport}/${row.route}`)).toEqual([
            'empty/desktop/home',
            'filled/desktop/akkreditierungen',
            'filled/desktop/home',
            'filled/mobile/home',
        ]);
    });

    it('--only filters by substring on the route name, and the totals follow the filter', () => {
        // §7 step 4 re-captures ONE route and reads its Δ. A `--only` that
        // filtered the rows but not the totals would print a total that describes
        // a batch the reviewer is not looking at.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'admin-badge-editor-edit', bands: 3 }, { route: 'admin-badge-editor-edit', bands: 5 });
        writeCapture(root, 'filled', 'desktop', { route: 'admin-media', bands: 1 }, { route: 'admin-media', bands: 1 });

        const report = buildReport(root, 'editor');
        expect(report.captures).toBe(1);
        expect(report.totalBands).toBe(3);
        expect(report.totalPreviousBands).toBe(5);
        expect(report.changed).toHaveLength(1);
    });

    it('ignores the prev/ directory as a state, so the previous generation is not counted as a capture of its own', () => {
        // A `collect()` that descended into `prev/` would double every capture
        // with a previous generation and report the band total twice over.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 5 }, { route: 'home', bands: 2 });
        expect(fs.existsSync(path.join(root, 'filled', 'desktop', PREVIOUS_DIR))).toBe(true);

        const report = buildReport(root);
        expect(report.captures).toBe(1);
        expect(report.rows).toHaveLength(1);
        expect(report.rows[0].previousBands).toBe(2);
    });

    it('carries the print pair through, so a reviewer is told which two views belong together', () => {
        // `badge-print.ts` writes `compareWith`, and it is the only thing that
        // connects `admin-badge-print.pdf-N.png` to the editor capture of the same
        // template. Losing it in the report would break the §7 print pair.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', {
            route: 'admin-badge-print',
            bands: 2,
            compareWith: 'admin-badge-editor-edit',
        });

        const report = buildReport(root);
        expect(report.rows[0].compareWith).toBe('admin-badge-editor-edit');
    });

    it('an EMPTY store is a report with no rows, not a crash', () => {
        // The state a first-ever run on a fresh checkout leaves behind, and the
        // one a `--dir` pointing at a real-but-empty directory produces.
        const report = buildReport(makeStore());
        expect(report.captures).toBe(0);
        expect(report.totalBands).toBe(0);
        expect(report.rows).toEqual([]);
    });

    it('a corrupt sidecar names the file instead of reporting a wrong number', () => {
        // A truncated or hand-edited sidecar is the shape a reviewer hits after
        // copying a batch around. Silently skipping it would drop a capture from
        // the total — the report would be shorter and look like a smaller batch.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 });
        fs.writeFileSync(path.join(root, 'filled', 'desktop', 'broken.meta.json'), '{ not json');

        expect(() => buildReport(root)).toThrow(/broken\.meta\.json/);
    });
});

describe('isReproducible — §7 acceptance criterion 1 as a predicate', () => {
    it('is true when every capture kept its band count and every one has a predecessor', () => {
        // The measured shape: 24/24/24 over three runs.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 }, { route: 'home', bands: 2 });
        writeCapture(root, 'filled', 'mobile', { route: 'home', bands: 2 }, { route: 'home', bands: 2 });

        const report = buildReport(root);
        expect(isReproducible(report)).toBe(true);
    });

    it('is FALSE for a first run, because "no comparison" is not "stable"', () => {
        // The vacuous-true case, and the one a naive `changed.length === 0` gets
        // exactly wrong: an empty delta list on a store with no predecessor at all
        // means nothing has been observed twice yet.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 });

        const report = buildReport(root);
        expect(report.changed).toEqual([]);
        expect(isReproducible(report), 'a single uncompared run proves nothing').toBe(false);
    });

    it('is FALSE when only SOME captures have a predecessor — the partial re-capture', () => {
        // MEASURED 2026-10-01, and the reason this test exists: a `-g konto` run
        // over a full batch leaves the other routes' sidecars in place with no
        // `prev/` beside them. A verdict keyed on "at least one row has a
        // predecessor" reported **ja** for that store — 4 rows compared, 60 not.
        // A partial run is exactly the situation §7 step 4 creates, so it is
        // exactly where a "mostly stable" verdict is worthless: the untouched
        // routes' numbers are last run's, not this run's.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'konto', bands: 0 }, { route: 'konto', bands: 0 });
        writeCapture(root, 'filled', 'mobile', { route: 'konto', bands: 0 }, { route: 'konto', bands: 0 });
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 4 });
        writeCapture(root, 'filled', 'desktop', { route: 'admin-users', bands: 2 });

        const report = buildReport(root);
        expect(report.hasComparison, 'two rows DO have a predecessor').toBe(true);
        expect(isReproducible(report), 'but 2 of 4 do not, so nothing is reproducible here').toBe(false);
        expect(
            report.rows.filter((row) => row.previousBands === null).map((row) => row.route),
        ).toEqual(['admin-users', 'home']);
    });

    it('is FALSE when every row has a predecessor but the batch is TWO RUNS', () => {
        // The residual half of the defect above, and the one that survived its
        // fix: a partial re-capture that lands on a route which HAD been
        // re-captured before gives every row a predecessor while the rows still
        // come from two different runs. MEASURED 2026-10-01 on the real store
        // after a `-g home` run: **60 rows `run-76874` + 4 rows `run-79538`, 0
        // rows without a predecessor, 0 changed → "Reproduzierbar: ja"**. The 60
        // are last run's numbers, so the verdict described a batch that does not
        // exist — and this is the state §7 step 4 creates on purpose.
        const root = makeStore();
        writeCapture(
            root,
            'filled',
            'desktop',
            { route: 'home', bands: 2, runKey: 'run-76874' },
            { route: 'home', bands: 2, runKey: 'run-76874' },
        );
        writeCapture(
            root,
            'filled',
            'mobile',
            { route: 'home', bands: 2, runKey: 'run-79538' },
            { route: 'home', bands: 2, runKey: 'run-79538' },
        );
        writeCapture(
            root,
            'empty',
            'desktop',
            { route: 'konto', bands: 0, runKey: 'run-76874' },
            { route: 'konto', bands: 0, runKey: 'run-76874' },
        );

        const report = buildReport(root);
        // The two conditions that DO hold, stated so the failure below cannot be
        // mistaken for the first-run case: this is not "nothing was compared".
        expect(report.rows.every((row) => row.previousBands !== null), 'every row HAS a predecessor').toBe(true);
        expect(report.changed, 'and nothing moved').toEqual([]);
        expect(report.runKeys).toEqual(['run-76874', 'run-79538']);
        expect(isReproducible(report), 'yet the batch is two generations, so no').toBe(false);
    });

    it('the CLI names BOTH run keys when the batch mixes generations', () => {
        // The count of missing predecessors is already in the parenthetical; the
        // KEYS are what a reviewer needs here, because "run-79538" is the `-g`
        // run and everything else is last run's. A bare "nein" here reads as a
        // layout regression on a page that was never touched.
        const root = makeStore();
        writeCapture(
            root,
            'filled',
            'desktop',
            { route: 'home', bands: 2, runKey: 'run-76874' },
            { route: 'home', bands: 2, runKey: 'run-76874' },
        );
        writeCapture(
            root,
            'filled',
            'mobile',
            { route: 'home', bands: 2, runKey: 'run-79538' },
            { route: 'home', bands: 2, runKey: 'run-79538' },
        );

        const result = spawnSync(process.execPath, [scriptPath(), '--dir', root], { encoding: 'utf8' });
        expect(result.stdout).toContain('Reproduzierbar: nein (');
        expect(result.stdout).toContain('2 Lauf-Keys im Batch (run-76874, run-79538)');
        expect(result.stdout).not.toContain('ohne Vorergeneration');
        // …and the evidence line agrees with the verdict.
        expect(result.stdout).toContain('Run-Key      : run-76874, run-79538');
    });

    it('the CLI names how many rows lack a predecessor, so "nein" is actionable', () => {
        // A bare "nein" on a store that a partial re-capture just touched would
        // read as a defect in the page. The count says which situation it is.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'konto', bands: 0 }, { route: 'konto', bands: 0 });
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 4 });

        const result = spawnSync(process.execPath, [scriptPath(), '--dir', root], { encoding: 'utf8' });
        expect(result.stdout).toContain('Reproduzierbar: nein (1 von 2 ohne Vorergeneration');
    });

    it('is FALSE for an empty store', () => {
        // Same reason, degenerate case: zero rows is not a stable measurement.
        expect(isReproducible(buildReport(makeStore()))).toBe(false);
    });

    it('is FALSE as soon as ONE capture moved, and names it', () => {
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 }, { route: 'home', bands: 2 });
        writeCapture(root, 'filled', 'mobile', { route: 'home', bands: 4 }, { route: 'home', bands: 2 });

        const report = buildReport(root);
        expect(isReproducible(report)).toBe(false);
        expect(report.changed.map((row) => `${row.route}/${row.viewport}`)).toEqual(['home/mobile']);
    });
});

describe('the CLI — a missing store is a named failure, not a stack trace', () => {
    it('exits 2 and says which directory is missing when the store was never built', () => {
        // The state of a fresh checkout, and the one a reviewer hits when they
        // run the report before the capture suite. `main()` guards it with
        // `existsSync`; this pins the guard, because the alternative — an ENOENT
        // from inside `collect()`'s `readdirSync` — reads as a broken script
        // rather than as "you have not captured anything yet".
        const missing = path.join(makeStore(), 'never-captured');
        const result = spawnSync(
            process.execPath,
            [scriptPath(), '--dir', missing],
            { encoding: 'utf8' },
        );
        expect(result.status).toBe(2);
        expect(result.stderr).toContain(missing);
        expect(result.stderr).toContain('pnpm test:screenshots');
    });

    it('prints the reproducibility verdict and exits 0 on a store with a comparison', () => {
        // The end-to-end shape of §7's acceptance criterion 1: a reviewer runs
        // the script, and the batch's reproducibility is a LINE in the output
        // rather than something they have to re-derive from the rows. Driven
        // through the real entry point, so `main()`'s own formatting is covered
        // and not only the pure `buildReport()` the unit tests above drive.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 }, { route: 'home', bands: 2 });

        const result = spawnSync(process.execPath, [scriptPath(), '--dir', root], { encoding: 'utf8' });
        expect(result.status).toBe(0);
        expect(result.stdout).toContain('Bänder total : 2 (vorher 2)');
        expect(result.stdout).toContain('Reproduzierbar: ja');
    });

    it('says "nein" when a capture moved, and names it', () => {
        // The verdict a reviewer acts on. A report that printed the numbers but
        // not the conclusion would be the same defect in a different costume: the
        // reader has to do the comparison the tool already did.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 }, { route: 'home', bands: 2 });
        writeCapture(root, 'filled', 'mobile', { route: 'home', bands: 5 }, { route: 'home', bands: 2 });

        const result = spawnSync(process.execPath, [scriptPath(), '--dir', root], { encoding: 'utf8' });
        expect(result.status).toBe(0);
        expect(result.stdout).toContain('Reproduzierbar: nein');
        expect(result.stdout).toContain('Geänderte Bandzahl (1)');
        expect(result.stdout).toContain('home (filled/mobile): 2 → 5');
    });

    it('says "nein" with the reason on a first run, rather than a bare no', () => {
        // "Reproduzierbar: nein" alone would read as a defect on the very first
        // run, which is the state every fresh checkout starts in. The parenthetical
        // names the actual situation — here the whole batch, since a first run has
        // no predecessor for anything.
        const root = makeStore();
        writeCapture(root, 'filled', 'desktop', { route: 'home', bands: 2 });

        const result = spawnSync(process.execPath, [scriptPath(), '--dir', root], { encoding: 'utf8' });
        expect(result.stdout).toContain('Reproduzierbar: nein (1 von 1 ohne Vorergeneration');
        expect(result.stdout).toContain('Lauf 1 oder partieller Re-Capture');
    });
});
