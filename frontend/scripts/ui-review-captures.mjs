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
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

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
 */
export const DEFAULT_DIR = 'test-artifacts/ui-review';

/** The previous generation's subdirectory, kept by the store for the Δ report. */
export const PREVIOUS_DIR = 'prev';

export function parseArgs(argv) {
    const options = { dir: DEFAULT_DIR, json: false, only: null };
    for (let index = 0; index < argv.length; index += 1) {
        const arg = argv[index];
        if (arg === '--dir' || arg === '-d') {
            index += 1;
            if (index >= argv.length) {
                throw new Error('--dir braucht einen Wert');
            }
            options.dir = argv[index];
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

function main() {
    let options;
    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        process.stderr.write(`FEHLER: ${error instanceof Error ? error.message : error}\n\n`);
        process.stderr.write('Aufruf: node scripts/ui-review-captures.mjs [--dir DIR] [--json] [--only ROUTE]\n');
        return 2;
    }
    if (options.help) {
        process.stdout.write('Aufruf: node scripts/ui-review-captures.mjs [--dir DIR] [--json] [--only ROUTE]\n');
        return 0;
    }
    if (!fs.existsSync(options.dir)) {
        process.stderr.write(
            `FEHLER: ${options.dir} existiert nicht — zuerst "pnpm test:screenshots" laufen lassen.\n`,
        );
        return 2;
    }

    const all = collect(options.dir);
    const entries = options.only === null ? all : all.filter((entry) => entry.meta.route.includes(options.only));
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

    const totalBands = rows.reduce((sum, row) => sum + row.bands, 0);
    const totalPrevious = rows.reduce((sum, row) => sum + (row.previousBands ?? 0), 0);
    const changed = rows.filter((row) => row.delta !== null && row.delta !== 0);

    if (options.json) {
        process.stdout.write(`${JSON.stringify({ root: options.dir, totalBands, rows }, null, 2)}\n`);
        return 0;
    }

    process.stdout.write(`Store        : ${options.dir}\n`);
    process.stdout.write(`Captures     : ${rows.length}\n`);
    process.stdout.write(
        `Bänder total : ${totalBands}` +
            (rows.some((row) => row.previousBands !== null) ? ` (vorher ${totalPrevious})` : ' (kein Vergleich — erster Lauf)') +
            '\n',
    );
    process.stdout.write(
        `Run-Key      : ${[...new Set(rows.map((row) => row.runKey))].join(', ') || '—'}\n\n`,
    );
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

