import fs from 'node:fs';
import path from 'node:path';

/**
 * The capture store that used to live at `test-results/ui-review/`, and what to
 * do about a checkout that still carries one there.
 *
 * ## The failure this exists for (Position 12)
 *
 * The store was moved BY HAND, once, out of `test-results/` into
 * `test-artifacts/` (see `tests/screenshots/helpers/store-paths.ts` for the
 * measurements that made the move necessary). Nothing versioned that move,
 * because the directory is gitignored — and so:
 *
 *   * every checkout that still carries a store from before the move keeps a
 *     **stale copy** at the old path, and
 *   * nothing warns, nothing migrates, nothing cleans it.
 *
 * A reviewer who opens the old path sees a COMPLETE, plausible-looking, OUTDATED
 * review batch — full-page PNGs, section bands, `.meta.json` sidecars with band
 * counts and timestamps. Nothing about it looks broken. That is the most
 * expensive shape of legacy: not an error, but a **correctly-looking wrong
 * finding**, and it is reviewable by accident because the review loop's first
 * step is "open the batch".
 *
 * ## Why a HINT and not a cleanup
 *
 * Because the position is right about this, and the reason is structural: a
 * gitignored directory is forgotten the moment nobody looks at it. Deleting it
 * silently at run time would (a) destroy the only copy of a batch somebody may
 * still be comparing against, (b) make the disappearance invisible, and (c) fix
 * the machine the harness happens to run on and not the checkout. A hint is the
 * shape that survives: it names what it found, names where the current store is,
 * and gives the exit route, and it is silent when there is nothing to say — which
 * is the normal case and must stay so.
 *
 * ## Why this is a separate `.mjs`
 *
 * Two consumers need this: `scripts/ui-review-captures.mjs` (the report a
 * reviewer runs, which has to stay runnable as plain `node scripts/*.mjs`) and
 * the Playwright reporter in `tests/screenshots/helpers/`. Plain ESM with JSDoc
 * is the only form both can use — `tsconfig.tests.json` sets `allowJs`, so the
 * TypeScript consumers get it type-checked, and `node` can run the `.mjs`
 * without a build step. Same arrangement as `scripts/check-i18n.mjs` /
 * `scripts/po-catalog.mjs`.
 *
 * The LEGACY path itself is restated here and in `store-paths.ts` (which owns
 * `CAPTURE_STORE_DIR` and `PLAYWRIGHT_SCRATCH_DIR`). `tsconfig.node.json` — the
 * project that type-checks `playwright.screenshots.config.ts` — has no
 * `allowJs`, so the config's import chain cannot reach into a `.mjs`; the two
 * literals are therefore tied together by a COMPARISON in
 * `tests/screenshots/helpers/stale-store.test.ts`, exactly the way
 * `DEFAULT_DIR` is tied to `CAPTURE_STORE_DIR` in
 * `scripts/ui-review-captures.test.ts`. A comment cannot tie two literals; a
 * failing assertion can.
 */
export const LEGACY_CAPTURE_STORE_DIR = 'test-results/ui-review';

/**
 * What a stale store turned out to contain, as data.
 *
 * The counts are the point: a reviewer deciding whether to look at the old path
 * needs to be told it is a full batch and how old, not merely that "something" is
 * there. `captures` / `totalBands` are what `scripts/ui-review-captures.mjs`
 * would have reported for it, and `newestCapturedAt` is the honest answer to "is
 * this current?".
 *
 * @typedef {object} StaleStoreFinding
 * @property {string} legacyRoot    absolute path of the stale store
 * @property {string} legacyDir     the same path as the reviewer types it
 * @property {string} currentRoot   absolute path of the store the harness writes
 * @property {string} currentDir    the same path as the reviewer types it
 * @property {number} files         regular files below the stale root, `prev/` included
 * @property {number} captures      `<route>.meta.json` sidecars OUTSIDE `prev/` — the current generation
 * @property {number} totalBands    the sum of their `bands`
 * @property {string|null} newestCapturedAt  the newest `capturedAt` among them
 */

/**
 * Every regular file below `root`, with the ones inside a `prev/` directory
 * counted apart from the rest.
 *
 * `prev/` holds the PREVIOUS generation (see `helpers/capture-store.ts`), so
 * counting it as captures would double the batch and mis-date it. Symlinks are
 * not followed: a review store is a flat directory tree of images and sidecars,
 * and following a link out of it would make the number depend on something the
 * harness did not write.
 *
 * @param {string} root
 * @returns {{ files: number, captures: number, totalBands: number, newestCapturedAt: string|null }}
 */
export function walkStore(root) {
    let files = 0;
    let captures = 0;
    let totalBands = 0;
    let newestCapturedAt = null;

    const visit = (dir, insidePrev) => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                visit(full, insidePrev || entry.name === 'prev');
                continue;
            }
            if (!entry.isFile()) {
                continue;
            }
            files += 1;
            if (insidePrev || !entry.name.endsWith('.meta.json')) {
                continue;
            }
            captures += 1;
            const meta = readSidecar(full);
            if (meta === null) {
                continue;
            }
            totalBands += Number.isFinite(meta.bands) ? meta.bands : 0;
            if (typeof meta.capturedAt === 'string' && (newestCapturedAt === null || meta.capturedAt > newestCapturedAt)) {
                newestCapturedAt = meta.capturedAt;
            }
        }
    };

    visit(root, false);
    return { files, captures, totalBands, newestCapturedAt };
}

/**
 * A sidecar, or `null` when it is unreadable or unparsable.
 *
 * A corrupt sidecar must not stop the hint: the point is to tell a reviewer that
 * the old path holds something, and a batch nobody can parse is if anything a
 * stronger reason to look at the CURRENT store instead.
 *
 * @param {string} file
 * @returns {{ bands: unknown, capturedAt: unknown }|null}
 */
function readSidecar(file) {
    try {
        const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
        return typeof parsed === 'object' && parsed !== null ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * The stale store at `legacyRoot`, or `null` when there is nothing to report.
 *
 * `null` is the NORMAL case and has to be indistinguishable from silence: a
 * fresh checkout has no old store, and a run must not print anything about it.
 *
 * "Non-empty" is the condition, not "exists": Playwright's own wipe leaves the
 * directory behind with nothing in it, and a harness that shouted about an empty
 * directory would be shouting on every machine that ever ran the old config.
 *
 * @param {{ legacyRoot: string, legacyDir: string, currentRoot: string, currentDir: string }} options
 * @returns {StaleStoreFinding|null}
 */
export function inspectStaleCaptureStore(options) {
    const { legacyRoot, legacyDir, currentRoot, currentDir } = options;
    let stats;
    try {
        stats = fs.statSync(legacyRoot);
    } catch {
        return null;
    }
    if (!stats.isDirectory()) {
        return null;
    }
    const walked = walkStore(legacyRoot);
    if (walked.files === 0) {
        return null;
    }
    return { legacyRoot, legacyDir, currentRoot, currentDir, ...walked };
}

/**
 * The warning, as the block a human reads.
 *
 * Loud on purpose. §7's loop starts with a human opening a batch, and the whole
 * risk here is that they open the wrong one without a single signal. So: a banner
 * rule above and below, the word "nicht reviewen" in it, BOTH paths in both forms
 * (typed and absolute), what was found, why it is stale, and the exit route.
 *
 * @param {StaleStoreFinding} finding
 * @returns {string}
 */
export function formatStaleStoreHint(finding) {
    const rule = '!'.repeat(112);
    const inner = '!';
    const found = [];
    if (finding.captures > 0) {
        found.push(`${finding.captures} Captures`);
    }
    if (finding.totalBands > 0) {
        found.push(`${finding.totalBands} Bänder`);
    }
    found.push(`${finding.files} Dateien`);
    if (finding.newestCapturedAt !== null) {
        found.push(`letzte Aufnahme ${finding.newestCapturedAt}`);
    }
    const age = finding.newestCapturedAt === null ? '' : ' (diese Bilder werden nicht mehr geschrieben)';

    // The typed path, plus the absolute one when the two actually differ — a
    // duplicate line for an absolute `--legacy` argument would only cost width.
    const pair = (label, typed, absolute) =>
        absolute === typed
            ? [`${inner}  ${label} : ${typed}`]
            : [`${inner}  ${label} : ${typed}`, `${inner}            ${absolute}`];

    return [
        rule,
        `${inner}  WARNUNG: VERALTETER ui-review-STORE AN DER ALTEN PFADANGABE — NICHT REVIEWEN${' '.repeat(24)}${inner}`,
        inner,
        ...pair('ALT     ', finding.legacyDir, finding.legacyRoot),
        ...pair('AKTUELL ', finding.currentDir, finding.currentRoot),
        inner,
        `${inner}  gefunden : ${found.join(', ')}${age}`,
        inner,
        `${inner}  Der Pfad wurde verschoben, nicht der Inhalt aktualisiert. Diese Bilder stammen aus einem`,
        `${inner}  ÄLTEREN Commit; ein Review darauf beschreibt einen Stand, den es nicht (mehr) gibt.`,
        `${inner}  Lautlos wäre das ein Fehler, der wie ein Befund aussieht — deshalb dieser Hinweis.`,
        inner,
        `${inner}  Ausstieg: rm -rf ${finding.legacyDir}`,
        `${inner}  (gitignoriert, also versioniert der Pfad nichts; danach beschreibt nur noch AKTUELL einen Batch.)`,
        rule,
        '',
    ].join('\n');
}

/**
 * Writes the hint through `write` when there is a stale store. Returns whether it
 * wrote anything, so a caller can tell "checked, nothing to say" from "checked
 * and shouted" without parsing the output.
 *
 * @param {{ legacyRoot: string, legacyDir: string, currentRoot: string, currentDir: string }} options
 * @param {(text: string) => void} write
 * @returns {boolean}
 */
export function reportStaleCaptureStore(options, write) {
    const finding = inspectStaleCaptureStore(options);
    if (finding === null) {
        return false;
    }
    write(`${formatStaleStoreHint(finding)}\n`);
    return true;
}