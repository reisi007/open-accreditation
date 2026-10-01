#!/usr/bin/env node
/**
 * Reads the ui-review capture store and prints the per-run record: how many
 * section bands each capture produced, and how that compares to the PREVIOUS
 * generation next to it.
 *
 * Why this exists: the band count is a function of the page height, which is a
 * function of the data a capture was rendered against. While the seeds leaked,
 * three runs of unchanged code produced 35 → 45 → 66 bands — a review batch could
 * not be compared to the one before it, and a finding could not be reproduced.
 * Every capture writes its measurements to `<name>.meta.json` next to the images
 * (see `tests/screenshots/helpers/capture-store.ts`); this turns those sidecars
 * into the comparison a reviewer needs before handing a batch to the vision
 * subagent.
 *
 * Usage:
 *   node scripts/ui-review-captures.mjs [--dir test-artifacts/ui-review] [--json] [--only <route>]
 *
 * Exit code 0 always: a difference in band counts is information for a human, not
 * a CI failure. (The capture run itself fails loudly when a capture is broken.)
 * The one thing printed as a VERDICT rather than as a list of numbers is
 * `Reproduzierbar` — §7's acceptance criterion 1 ("three runs in a row produce
 * the same section count") as a single readable line, derived by `isReproducible`
 * and tested in `ui-review-captures.test.ts` against a synthetic store. It stays
 * a line and not an exit code on purpose: a moved page height is a fact about the
 * DATA, and the harness cannot tell a data change from a layout regression.
 *
 * It says `nein` — with the reason, from `whyNot` — for three different
 * situations, and the three are not the same finding: nothing was compared twice
 * (first run), not everything was compared (partial re-capture), or the batch is
 * not ONE generation at all (mixed run keys). Only the third of those is easy to
 * miss, because its rows all have predecessors and none of them moved.
 *
 * ## The stale-store warning, printed BEFORE anything else
 *
 * This report describes the store at `test-artifacts/ui-review`. Before the move
 * out of `test-results/` the same report described `test-results/ui-review`, and
 * a checkout that still has a batch there keeps it — untouched, plausible, out of
 * date, and NOT described by anything this script prints. So the report opens by
 * naming it: what is at the old path, how old, and where the current one is. See
 * `scripts/stale-store.mjs` for why this is a hint with an exit route and not a
 * cleanup.
 *
 * It is checked here as well as from the screenshot run's reporter, because the
 * two readers are different: the reporter catches a reviewer who is about to CAPTURE,
 * this catches one who is about to REVIEW what is already on disk — and the second
 * is the moment the wrong directory does damage. `--legacy DIR` moves the lookup
 * (the reason is testability and the same one `--dir` has: pointing the check at a
 * synthetic store instead of this checkout's).
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { LEGACY_CAPTURE_STORE_DIR, formatStaleStoreHint, inspectStaleCaptureStore } from './stale-store.mjs';

/**
 * The capture store, restated here because this script is plain ESM and must
 * stay runnable on its own — the same reason `scripts/po-catalog.mjs` exists
 * beside `check-i18n.mjs`.
 *
 * ## What keeps this and `store-paths.ts` in agreement
 *
 * `tests/screenshots/helpers/store-paths.ts` owns `CAPTURE_STORE_DIR`, which the
 * screenshot config, the capture store, the dataset marker and
 * `store-guard.spec.ts` all import. That leaves exactly ONE value in the repo
 * that nothing checked: this one.
 *
 * F2 measured what the previous arrangement was worth. Its comment claimed the
 * two were "kept honest by `store-guard.spec.ts`" — a test that does not do
 * that, and never did. `store-guard.spec.ts` imports `CAPTURE_STORE_DIR` and
 * asserts properties of the PATHS; it never reads this file. Nor could the claim
 * be rescued by inspection: `tsc -b` does not see a string inside an `.mjs`, and
 * `grep -rn ui-review-captures tests/ scripts/ *.ts` found **zero** references.
 * With `DEFAULT_DIR` pointed at `test-results/TOTALLY-WRONG-STORE` the whole
 * screenshot suite (75 passed) and the E2E specs (24 passed) stayed green — the
 * report simply described a directory that does not exist.
 *
 * So the value is exported and **compared**: `scripts/ui-review-captures.test.ts`
 * imports this module (possible because `main()` now runs only when this file is
 * the process entry point) and asserts `DEFAULT_DIR === CAPTURE_STORE_DIR`, and
 * that `parseArgs([])` really defaults to it rather than to a third literal.
 * That test runs in `pnpm test:run` — the vitest suite, on every change — which
 * is the gate the old comment implied and never had.
 *
 * ## What the test file covers beyond the path
 *
 * The path was the *only* thing it checked, which left the report itself
 * untested — a script whose output nobody has read is the twin of the bug above
 * one level up. `buildReport()` and `isReproducible()` are therefore exported and
 * driven over a synthetic store (`tmp/ui-review-*`): the previous-generation
 * lookup, the delta, the total, the `--only` filter, and the reproducibility
 * verdict in each of its four states (no predecessor / some rows without a
 * predecessor / mixed run generations / unchanged). Building the store in a temp
 * dir is what makes that possible without a browser, a backend and a database.
 */
export const DEFAULT_DIR = 'test-artifacts/ui-review';

/** The previous generation's subdirectory, kept by the store for the Δ report. */
export const PREVIOUS_DIR = 'prev';

export function parseArgs(argv) {
    const options = { dir: DEFAULT_DIR, json: false, only: null, legacy: null };
    for (let index = 0; index < argv.length; index += 1) {
        const arg = argv[index];
        if (arg === '--dir' || arg === '-d') {
            index += 1;
            if (index >= argv.length) {
                throw new Error('--dir braucht einen Wert');
            }
            options.dir = argv[index];
        } else if (arg === '--legacy') {
            index += 1;
            if (index >= argv.length) {
                throw new Error('--legacy braucht einen Wert');
            }
            options.legacy = argv[index];
        } else if (arg === '--json') {
            options.json = true;
        } else if (arg === '--only' || arg === '-g') {
            index += 1;
            if (index >= argv.length) {
                throw new Error('--only braucht einen Wert');
            }
            options.only = argv[index];
        } else if (arg === '--help' || arg === '-h') {
            options.help = true;
        } else {
            throw new Error(`unbekanntes Argument: ${arg}`);
        }
    }
    return options;
}

function readMeta(file) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (error) {
        const code = error && typeof error === 'object' ? error.code : null;
        if (code === 'ENOENT') {
            return null;
        }
        throw new Error(`${file} ist kein gültiges Sidecar: ${error instanceof Error ? error.message : error}`);
    }
}

function collect(root) {
    const entries = [];
    for (const state of fs.readdirSync(root, { withFileTypes: true })) {
        if (!state.isDirectory() || state.name === PREVIOUS_DIR) {
            continue;
        }
        for (const viewport of fs.readdirSync(path.join(root, state.name), { withFileTypes: true })) {
            if (!viewport.isDirectory()) {
                continue;
            }
            const dir = path.join(root, state.name, viewport.name);
            for (const file of fs.readdirSync(dir)) {
                if (!file.endsWith('.meta.json')) {
                    continue;
                }
                const meta = readMeta(path.join(dir, file));
                if (meta === null) {
                    continue;
                }
                const previous = readMeta(path.join(dir, PREVIOUS_DIR, file));
                entries.push({ dir, meta, previous });
            }
        }
    }
    return entries;
}

/**
 * The report AS DATA, so the numbers can be tested without running a capture.
 *
 * ## Why this split exists
 *
 * `main()` used to compute and print in one function, which made the whole
 * comparison untestable: the only way to learn what the script reports for a
 * given store was to produce that store, i.e. to run the screenshot harness with
 * a browser, a backend and a database. That is the same gap `scripts/po-catalog.mjs`
 * had and closed the same way — the computation is a pure function of a
 * directory, so it is exported and `ui-review-captures.test.ts` drives it over a
 * synthetic store with known sidecars.
 *
 * What that buys is exactly the property §7's acceptance criterion 1 rests on:
 * "the section count is written NEXT TO THE IMAGES, because a batch is
 * untraceable later without it". A number nobody has checked is a comment, and
 * this is the number — `bands`, its `previousBands` and the `delta` between
 * them are what a reviewer reads to decide whether a batch is comparable with the
 * one before it.
 *
 * Pure: it reads the store and returns. No printing, no exit code, no
 * `process.exitCode` — the printing stays in `main()`.
 *
 * @param {string} root  store root (`<state>/<viewport>/*.meta.json`)
 * @param {string|null} only  substring filter on the route name, or null for all
 */
export function buildReport(root, only = null) {
    const all = collect(root);
    const entries = only === null ? all : all.filter((entry) => entry.meta.route.includes(only));
    entries.sort((left, right) =>
        `${left.meta.state}/${left.meta.viewport}/${left.meta.route}`.localeCompare(
            `${right.meta.state}/${right.meta.viewport}/${right.meta.route}`,
        ),
    );

    const rows = entries.map((entry) => {
        const { meta, previous } = entry;
        const previousBands = previous === null ? null : previous.bands;
        const delta = previousBands === null ? null : meta.bands - previousBands;
        return {
            state: meta.state,
            viewport: meta.viewport,
            route: meta.route,
            bands: meta.bands,
            previousBands,
            delta,
            scrollHeightPx: meta.scrollHeightPx,
            compareWith: meta.compareWith ?? null,
            runKey: meta.runKey,
            capturedAt: meta.capturedAt,
        };
    });

    return {
        root,
        captures: rows.length,
        totalBands: rows.reduce((sum, row) => sum + row.bands, 0),
        totalPreviousBands: rows.reduce((sum, row) => sum + (row.previousBands ?? 0), 0),
        /** True when at least one capture has a predecessor to be compared with. */
        hasComparison: rows.some((row) => row.previousBands !== null),
        /** Only the captures whose band count MOVED — a 0 delta is not a change. */
        changed: rows.filter((row) => row.delta !== null && row.delta !== 0),
        /**
         * The distinct capture runs the current generation is made of, in
         * first-seen order. `length === 1` is "the whole batch is ONE run" —
         * see `isReproducible`, which is where that matters.
         */
        runKeys: runKeysOf(rows),
        rows,
    };
}

/**
 * The distinct run keys in `rows`, in first-seen order.
 *
 * A single entry means every capture on disk was written by the SAME capture
 * run; more than one means the current generation is a MIX — which §7 step 4
 * produces on purpose (`pnpm test:screenshots -g <route>` re-takes one route and
 * leaves the rest of the previous run's sidecars exactly where they are).
 */
export function runKeysOf(rows) {
    return [...new Set(rows.map((row) => row.runKey))];
}

/**
 * Whether every capture has the SAME band count as its predecessor.
 *
 * This is the §7 acceptance criterion 1 as a predicate: "three runs in a row
 * produce the same section count". The number next to the images is the record;
 * this is the check, and it is deliberately a function rather than a sentence in
 * a report — a reviewer comparing two batches should not have to re-derive it.
 *
 * Three conditions, and the second one is the one this file was wrong about:
 * EVERY row needs a predecessor, the whole batch must come from ONE run, and
 * nothing moved.
 *
 * 1. **Every row needs a predecessor, not one.** `hasComparison` alone was the
 *    vacuous-true this function was written to avoid, in a narrower form: a
 *    PARTIAL re-capture (`-g <route>`) leaves the other routes' current sidecars
 *    in place with no `prev/` beside them, so one compared row was enough to
 *    report "Reproduzierbar: ja" for a batch where 60 of 64 captures had never
 *    been seen twice. MEASURED 2026-10-01 on a store built by a `-g konto` run
 *    over a full one: 4 rows with a predecessor, 60 without, verdict "ja".
 *
 * 2. **One generation, not just one comparison each.** Fixing (1) closed the
 *    vacuous form but left its other half: a partial run that lands on a route
 *    which HAD been re-captured before gives every row a predecessor while the
 *    rows still come from two different runs. MEASURED 2026-10-01 on the real
 *    store after a `-g home` run: **60 rows `run-76874` + 4 rows `run-79538`, 0
 *    rows without a predecessor, 0 changed → "Reproduzierbar: ja"**. Those 60
 *    rows are last run's numbers, so the verdict described a batch that does not
 *    exist. `runKeys.length === 1` is that condition, and the `Run-Key` line of
 *    the report already printed the evidence.
 *
 * 3. **Nothing moved** — the original comparison.
 *
 * Honest about its own limit, which is stated in the report output too: a stable
 * delta proves the PAGE HEIGHTS did not move, not that the data behind them is
 * fixed. `helpers/dataset.ts` is what fixes the data; this only says the two
 * agree.
 */
export function isReproducible(report) {
    return (
        report.rows.length > 0 &&
        report.rows.every((row) => row.previousBands !== null) &&
        report.runKeys.length === 1 &&
        report.changed.length === 0
    );
}

/**
 * Why the verdict is `nein`, one clause per unmet condition, in the order the
 * conditions are stated on `isReproducible`.
 *
 * A bare `nein` is not actionable: on a store a partial re-capture just touched
 * it reads as a defect in the page, while it is really a statement about which
 * routes THIS run covered. So the clause names the situation, and the run-key
 * clause names the keys — the same evidence the `Run-Key` line carries, repeated
 * where the verdict is read.
 */
export function whyNot(report) {
    const reasons = [];
    const withoutPrevious = report.rows.filter((row) => row.previousBands === null);
    if (withoutPrevious.length > 0) {
        reasons.push(
            `${withoutPrevious.length} von ${report.rows.length} ohne Vorergeneration — Lauf 1 oder partieller Re-Capture`,
        );
    }
    if (report.runKeys.length > 1) {
        reasons.push(
            `${report.runKeys.length} Lauf-Keys im Batch (${report.runKeys.join(', ')}) — ` +
                'partieller Re-Capture, übersprungene Route oder Fehlschlag: der Batch ist keine EINHEITLICHE Generation',
        );
    }
    if (report.changed.length > 0) {
        reasons.push(`${report.changed.length} mit geänderter Bandzahl — siehe Liste unten`);
    }
    return reasons;
}

/**
 * The stale store, looked up under the legacy path — or `null`.
 *
 * Resolved from `process.cwd()` unless `--legacy` says otherwise, so the default
 * is this checkout's real old location. `--dir` does NOT move the lookup: a
 * reviewer pointing the report at some other store still has a stale batch in
 * THEIR checkout, and the warning is about the checkout, not about `--dir`.
 *
 * @param {{ dir: string, legacy: string|null }} options
 * @returns {import('./stale-store.mjs').StaleStoreFinding|null}
 */
function staleStoreOf(options) {
    const legacyDir = options.legacy ?? LEGACY_CAPTURE_STORE_DIR;
    return inspectStaleCaptureStore({
        legacyRoot: path.resolve(process.cwd(), legacyDir),
        legacyDir,
        currentRoot: path.resolve(process.cwd(), options.dir),
        currentDir: options.dir,
    });
}

function main() {
    const usage =
        'Aufruf: node scripts/ui-review-captures.mjs [--dir DIR] [--json] [--only ROUTE] [--legacy DIR]\n';
    let options;
    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        process.stderr.write(`FEHLER: ${error instanceof Error ? error.message : error}\n\n`);
        process.stderr.write(usage);
        return 2;
    }
    if (options.help) {
        process.stdout.write(usage);
        return 0;
    }
    if (!fs.existsSync(options.dir)) {
        process.stderr.write(
            `FEHLER: ${options.dir} existiert nicht — zuerst "pnpm test:screenshots" laufen lassen.\n`,
        );
        return 2;
    }

    // BEFORE the report, and before the `--json` branch: a stale batch at the old
    // path is the finding, and a machine consumer has to see it in the JSON too.
    // Under `--json` the block goes to STDERR, because stdout is the payload —
    // printing a banner into it would make the output unparsable, which is its own
    // kind of "looks fine, is wrong".
    const staleStore = staleStoreOf(options);
    if (staleStore !== null) {
        const stream = options.json ? process.stderr : process.stdout;
        stream.write(`${formatStaleStoreHint(staleStore)}\n`);
    }

    const report = buildReport(options.dir, options.only);
    const { rows, totalBands, totalPreviousBands, hasComparison, changed, captures } = report;

    if (options.json) {
        process.stdout.write(
            `${JSON.stringify({ root: options.dir, totalBands, staleStore, rows }, null, 2)}\n`,
        );
        return 0;
    }

    process.stdout.write(`Store        : ${options.dir}\n`);
    process.stdout.write(`Captures     : ${captures}\n`);
    process.stdout.write(
        `Bänder total : ${totalBands}` +
            (hasComparison ? ` (vorher ${totalPreviousBands})` : ' (kein Vergleich — erster Lauf)') +
            '\n',
    );
    process.stdout.write(`Reproduzierbar: ${isReproducible(report) ? 'ja' : `nein (${whyNot(report).join('; ')})`}\n`);
    process.stdout.write(`Run-Key      : ${report.runKeys.join(', ') || '—'}\n\n`);
    for (const row of rows) {
        const bands = String(row.bands).padStart(3);
        const previous = row.previousBands === null ? '  –' : String(row.previousBands).padStart(3);
        const delta = row.delta === null ? '   ' : row.delta === 0 ? '   ' : String(row.delta).padStart(3);
        const pair = row.compareWith === null ? '' : `  ↔ ${row.compareWith}`;
        process.stdout.write(
            `${row.state.padEnd(6)} ${row.viewport.padEnd(7)} ${row.route.padEnd(30)} ${bands} (vorher ${previous}, Δ${delta})` +
                `  ${String(row.scrollHeightPx).padStart(6)}px${pair}\n`,
        );
    }
    if (changed.length > 0) {
        process.stdout.write(`\nGeänderte Bandzahl (${changed.length}) — Seite ist höher/kürzer geworden:\n`);
        for (const row of changed) {
            process.stdout.write(`  ${row.route} (${row.state}/${row.viewport}): ${row.previousBands} → ${row.bands}\n`);
        }
    }
    return 0;
}

/**
 * Whether THIS file is the process entry point.
 *
 * The reason it is not simply "run on import": the test next to this script
 * imports it to read `DEFAULT_DIR`, and an unconditional `main()` would make
 * that import print a report, or — worse — set `process.exitCode = 2` because
 * the capture store has not been built yet, and fail the vitest run for a
 * reason that has nothing to do with the assertion under test. This is the same
 * split `po-catalog.mjs` / `check-i18n.mjs` already make.
 *
 * `import.meta.url` rather than `process.argv[1]` alone, because a runner
 * (vitest, `node --test`) enters through its own binary and `process.argv[1]`
 * is then that runner.
 */
const invokedDirectly = (() => {
    const entry = process.argv[1];
    if (entry === undefined) {
        return false;
    }
    return path.resolve(entry) === fileURLToPath(import.meta.url);
})();

if (invokedDirectly) {
    process.exitCode = main();
}

