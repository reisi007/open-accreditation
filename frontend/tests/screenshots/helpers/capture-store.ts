import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { uiReviewConfig } from '../ui-review.config';
import { PLAYWRIGHT_SCRATCH_DIR } from './store-paths';
import type { UiReviewState, UiReviewViewport } from '../ui-review.config';

/**
 * The capture store — where the review's pixels land, and what happens to the
 * pixels that were there before.
 *
 * ## Why this file exists: a partial re-capture used to destroy the rest
 *
 * AGENTS.md §7 step 4 prescribes the fix loop: "Fixes delegieren → Re-Capture
 * **nur der betroffenen Routen** (`pnpm test:screenshots -g <routenname>`) →
 * `vision`-Subagent vergleicht old vs new". The second half of that sentence was
 * impossible, because the harness deleted its own evidence.
 *
 * MEASURED (Playwright 1.63.0, this repo): the runner deletes its configured
 * `outputDir` **recursively, before the first test runs**, and
 * `playwright.screenshots.config.ts` points that `outputDir` at
 * `test-results/ui-screenshots` — which is exactly where the captures were
 * written. So a partial run kept only what it re-took:
 *
 *   126 PNG  -- `-g "screenshot home"`  -->  11 PNG   (116 files gone)
 *     11 PNG  -- `-g "screenshot login"` -->   4 PNG   (7 files gone)
 *
 * After a re-capture there was no "old" left — not even for the routes that had
 * just been re-taken. The fix loop was blocked at its own step 4.
 *
 * ## The two rules that fix it
 *
 * 1. **The capture root is not inside `test-results/` AT ALL** — it is
 *    `test-artifacts/ui-review/`, declared in `helpers/store-paths.ts` next to
 *    the scratch path so the two cannot drift apart.
 *
 *    The progression is worth keeping, because each step looked sufficient and
 *    was not. Inside `outputDir`: a partial re-capture took the store from
 *    **126 PNG to 11**. Moved next to `outputDir`
 *    (`test-results/ui-review/`, a SIBLING): the DEFAULT could no longer reach
 *    it, but `--output=test-results` is Playwright's *default* `outputDir` and
 *    the store's own parent, and it took the store from **192 PNG to 0** — even
 *    with a guard, because the runner wipes `outputDir` before `globalSetup`
 *    runs. So a sibling inside `test-results/` protected against the standard
 *    and not against the possibility. Outside the tree, the runner has nothing
 *    to delete.
 *
 *    `uiReviewConfig.outputDir` remains the single source of truth for the
 *    capture root; it now *is* `CAPTURE_STORE_DIR`.
 *
 * 2. **A capture overwrites; it never deletes.** Before an existing file is
 *    replaced, it is archived into `<captureDir>/prev/<file>`. A partial run
 *    therefore leaves BOTH halves of the comparison on disk: the untouched
 *    routes byte-for-byte as before, and the re-taken route as
 *    `prev/<name>.png` (old) next to `<name>.png` (new) — the exact pair the
 *    vision subagent needs. `prev/` is one generation deep on purpose: a fix
 *    loop compares against "the previous capture", and an unbounded history
 *    would make the review batch grow with every run.
 *
 * A numbered series (`<base>-1`, `<base>-2`, …) is the one case where files must
 * LEAVE the current generation: a page (or a document) that shrank leaves files
 * the new capture does not have. Those are archived — so the comparison stays
 * complete — and the current generation ends up describing exactly what the last
 * capture produced. Section bands and the printed badge pages both go through
 * `storePngSeries`, so the rule holds for both.
 *
 * ## Naming note (a documented-path change)
 *
 * Series files are `<route>-sec-1.png`, `<route>-sec-2.png`, … — a dash before the
 * index, where the first version of the band captures wrote `<route>-sec1.png`.
 * The uniform series pattern is what lets the surplus sweep above work for bands
 * and pages with one function, and the printed pages (`<route>-pdf-1.png`) follow
 * the same shape. AGENTS.md §7 quotes the old spelling and needs this one-word
 * correction.
 */

/** Sub-directory of a capture directory that holds the PREVIOUS generation. */
export const PREVIOUS_DIR_NAME = 'prev';

/**
 * The invariant of rule 1 above, as CODE.
 *
 * ## What the guard is now, honestly: a tripwire, not the protection
 *
 * The PATH is the protection (see rule 1). What is left to check is somebody
 * aiming `--output` AT the store — `--output=test-artifacts`, `--output=.` —
 * which now takes intent rather than a default. `assertCaptureRootOutsidePlaywrightOutput()`
 * refuses that, and it has to run at CONFIG-LOAD time to be worth anything:
 * MEASURED, the runner wipes `outputDir` BEFORE `globalSetup`, so the copy in
 * `playwright.screenshots.config.ts` (evaluated while Playwright is still
 * resolving the config, i.e. before it knows what to delete) is the one that
 * keeps the evidence. The `globalSetup` copy is kept because a project may pin
 * its own `outputDir`, which the config file cannot see — and it is documented
 * as the weaker of the two rather than as the safeguard.
 *
 * `store-guard.spec.ts` exercises this function directly, and states in its own
 * first assertion that the committed paths are separated by the PATH, not by
 * this check.
 */
export function assertCaptureRootOutsidePlaywrightOutput(
    captureRoot: string,
    playwrightOutputDir: string,
): void {
    const store = path.resolve(captureRoot);
    const scratch = path.resolve(playwrightOutputDir);
    const isInside = store === scratch || store.startsWith(`${scratch}${path.sep}`);
    if (!isInside) {
        return;
    }
    throw new Error(
        `The ui-review capture store would be deleted by its own run: "${store}" is inside the ` +
            `Playwright outputDir "${scratch}", which the runner wipes recursively before the first test ` +
            '(MEASURED: 126 → 11 PNG with the store inside outputDir, and 192 → 0 with it as a sibling ' +
            'of the scratch dir plus a guard that only ran in globalSetup — the runner wipes before that ' +
            "hook). The store now lives OUTSIDE test-results/ so this cannot happen by accident; " +
            'pointing `--output` at it means the flag was aimed at the store deliberately. ' +
            `Drop the flag to use the committed ${PLAYWRIGHT_SCRATCH_DIR}, or pass ` +
            '`--output=<somewhere else>`.',
    );
}

/** Suffix of the per-capture measurement sidecar. */
const META_SUFFIX = '.meta.json';

export interface CaptureMeta {
    /** Route name, i.e. the file base of the full-page PNG. */
    route: string;
    state: UiReviewState;
    viewport: UiReviewViewport;
    /** Full-page PNG, relative to the capture directory. */
    file: string;
    /**
     * How many `<route>-sec-N.png` section bands this capture produced. THE number
     * a review batch has to be able to reproduce: it is a function of the page
     * height, which grew with every run while the seeds leaked (measured 35 → 45
     * → 66 bands over three runs of unchanged code). Logged next to the images
     * so a batch stays auditable even when the underlying data changes.
     */
    bands: number;
    /** Page height in CSS px at capture time — the input the band count derives from. */
    scrollHeightPx: number;
    /** Viewport height in CSS px at capture time (950 desktop / 1040 mobile). */
    viewportHeightPx: number;
    /** Fraction of the viewport one band advances (`uiReviewConfig.sectionScrollStep`). */
    sectionScrollStep: number;
    /** Identity of the capture run (see `dataset.ts`: the runner process id). */
    runKey: string;
    /** ISO timestamp of the capture. */
    capturedAt: string;
    /** Entity counts the captures were rendered against (see `dataset.ts`). */
    dataset: Record<string, number>;
    /**
     * WHICH row the capture shows: the integer ids the seed resolved, e.g.
     * `{ accreditationId: 200 }`.
     *
     * Added because the id was invisible until now: the `apply` captures of one
     * run disagreed about their accreditation (desktop 201, mobile 200) and
     * nothing in the sidecar said which was which — the only way to notice was to
     * read the text out of the pixels. A batch can now be checked by reading the
     * sidecars, which is what the §7 acceptance check does.
     */
    entityIds: Record<string, number>;
    /** The pathname the capture was taken at — the manifest's route, postconditioned. */
    pathname: string;
    /**
     * Set when this capture belongs to a PAIR: the editor capture of the same
     * artifact. A finding that appears in only one of the two views is the
     * interesting one — the browser and the print renderer do not implement the
     * same CSS.
     */
    compareWith?: string;
    /** What the reviewer must do with this artifact (fed to the vision brief). */
    visionNote?: string;
}

export interface RouteCaptureInput {
    state: UiReviewState;
    viewport: UiReviewViewport;
    route: string;
    /** The full-page PNG. */
    fullPage: Buffer;
    /** Section bands in order (`-sec-1`, `-sec-2`, …). May be empty (short page). */
    bands: Buffer[];
    measurements: {
        scrollHeightPx: number;
        viewportHeightPx: number;
    };
    runKey: string;
    dataset: Record<string, number>;
    entityIds: Record<string, number>;
    pathname: string;
    compareWith?: string;
    visionNote?: string;
}

/** Absolute path of the capture directory for one state × viewport. */
export function captureDir(state: UiReviewState, viewport: UiReviewViewport): string {
    return path.resolve(process.cwd(), uiReviewConfig.outputDir, state, viewport);
}

function metaFileName(route: string): string {
    return `${route}${META_SUFFIX}`;
}

function isErrnoException(error: unknown, code: string): boolean {
    return error instanceof Error && 'code' in error && error.code === code;
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Moves `<dir>/<fileName>` to `<dir>/prev/<fileName>` if it exists, so the
 * capture that is about to overwrite it survives as the "old" half of the
 * comparison. Returns whether something was archived.
 */
function archiveExisting(dir: string, fileName: string): boolean {
    const current = path.join(dir, fileName);
    if (!fs.existsSync(current)) {
        return false;
    }
    const previousDir = path.join(dir, PREVIOUS_DIR_NAME);
    fs.mkdirSync(previousDir, { recursive: true });
    const archived = path.join(previousDir, fileName);
    if (fs.existsSync(archived)) {
        fs.unlinkSync(archived);
    }
    fs.renameSync(current, archived);
    return true;
}

/**
 * Stores one artifact, archiving the previous generation of that exact file
 * first. The single write primitive every capture goes through — it is not
 * PNG-specific, because the print check also stores the exported PDF itself.
 * Returns whether a previous generation was archived.
 */
export function storeArtifact(dir: string, fileName: string, bytes: Buffer): boolean {
    fs.mkdirSync(dir, { recursive: true });
    const archived = archiveExisting(dir, fileName);
    fs.writeFileSync(path.join(dir, fileName), bytes);
    return archived;
}

/**
 * Stores a numbered series — `<baseName>-1`, `-2`, … — archiving each file it
 * replaces and archiving (i.e. retiring from the current generation) the surplus
 * when the new series is shorter. Both captures of a page (section bands) and
 * pages of a document go through here, so "the files in this directory" always
 * means exactly "what the last capture produced".
 */
export function storePngSeries(dir: string, baseName: string, buffers: Buffer[]): void {
    buffers.forEach((buffer, index) => {
        storeArtifact(dir, `${baseName}-${index + 1}.png`, buffer);
    });
    const seriesPattern = new RegExp(`^${escapeRegExp(baseName)}-(\\d+)\\.png$`);
    for (const entry of fs.readdirSync(dir)) {
        const match = seriesPattern.exec(entry);
        if (match !== null && Number(match[1]) > buffers.length) {
            archiveExisting(dir, entry);
        }
    }
}

/** Reads a capture's measurement sidecar, or `null` when there is none. */
export function readMeta(dir: string, route: string): CaptureMeta | null {
    try {
        const raw = fs.readFileSync(path.join(dir, metaFileName(route)), 'utf8');
        return JSON.parse(raw) as CaptureMeta;
    } catch (error) {
        if (isErrnoException(error, 'ENOENT')) {
            return null;
        }
        throw error;
    }
}

/** Stores the measurement sidecar, archiving the previous one with the PNG. */
export function storeMeta(dir: string, meta: CaptureMeta): void {
    fs.mkdirSync(dir, { recursive: true });
    const fileName = metaFileName(meta.route);
    archiveExisting(dir, fileName);
    fs.writeFileSync(path.join(dir, fileName), `${JSON.stringify(meta, null, 2)}\n`);
}

/**
 * Archives + writes a full route capture: the full-page PNG, every section band,
 * and the measurement sidecar. Returns the sidecar that was written (its `bands`
 * is the run's record of how far the page reached).
 */
export function storeRouteCapture(input: RouteCaptureInput): CaptureMeta {
    const dir = captureDir(input.state, input.viewport);
    const { route, bands } = input;

    storeArtifact(dir, `${route}.png`, input.fullPage);
    storePngSeries(dir, `${route}-sec`, bands);

    const meta: CaptureMeta = {
        route,
        state: input.state,
        viewport: input.viewport,
        file: `${route}.png`,
        bands: bands.length,
        scrollHeightPx: input.measurements.scrollHeightPx,
        viewportHeightPx: input.measurements.viewportHeightPx,
        sectionScrollStep: uiReviewConfig.sectionScrollStep,
        runKey: input.runKey,
        capturedAt: new Date().toISOString(),
        dataset: input.dataset,
        entityIds: input.entityIds,
        pathname: input.pathname,
        ...(input.compareWith === undefined ? {} : { compareWith: input.compareWith }),
        ...(input.visionNote === undefined ? {} : { visionNote: input.visionNote }),
    };
    storeMeta(dir, meta);
    return meta;
}
