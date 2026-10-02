import { execFile } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { promisify } from 'node:util';
import type { Page } from '@playwright/test';
import type { UiReviewDataset } from './dataset';
import { storeArtifact, storePngSeries, storeMeta } from './capture-store';
import type { CaptureMeta } from './capture-store';

const execFileAsync = promisify(execFile);

/**
 * The PRINT side of the badge review: the same template the editor routes show,
 * rendered by the renderer that actually produces the badges.
 *
 * ## Why an editor screenshot cannot stand in for this
 *
 * `BadgeExportService::export()` renders **one A6 card per approved application**
 * with dompdf and has no postcondition in the backend. The screenshot suite
 * looked at the *editor* — HTML, CSS, a browser. Two defect classes got through
 * exactly that way: the `object-fit` stretching and the QR distortion, because
 * **dompdf implements no `object-fit` at all** (a browser honours the property,
 * dompdf stretches). An editor screenshot cannot show a dompdf failure, ever: it
 * shows the browser's rendering of a different program.
 *
 * ## The contract that makes this a real check
 *
 * The PDF is fetched over the **production export route**, as the logged-in
 * admin, from inside the browser context:
 *
 *   POST /api/admin/accreditations/{id}/badges/export  {format: 'pdf', template_id}
 *
 * `page.request` carries the httpOnly session cookie the UI login set, so this
 * is the same request `ApprovalsPage`'s export button makes — request path,
 * controller, service, dompdf, the lot. Calling `BadgeRenderService::renderPdf()`
 * directly from a test would be a DIFFERENT path than production and would
 * exclude precisely the difference under test. The `template_id` is the one the
 * UI's template select sends; it pins the print output to the SAME template the
 * editor captures show (which is additionally the mandant's default, so the
 * id-less path renders the same layout).
 *
 * Rasterising goes through `scripts/pdf-to-png-vision.sh`, whose postcondition
 * checks the alpha channel and the background opacity — a PDF page that paints no
 * background rasterises with an alpha channel, and how that then looks depends on
 * the viewer (the black badge text disappears). Our own badges paint `#ffffff` on
 * the page container as of D22, so the script is CHECKING rather than repairing
 * here; it stays strict because foreign PDFs still need it. This harness adds the
 * page-count postcondition the backend lacks: one A6 page per approved
 * application.
 *
 * ## The limit, stated so nobody over-reads the result
 *
 * **This loop checks ONE template against a handful of applications. A real
 * export is hundreds.** It finds DEFECT CLASSES — "photo and QR are stretched",
 * "a field overflows its box", "the page break lands in the wrong place" — not
 * the individual failures of a production run. A clean print check says "this
 * layout prints correctly for this data", never "the export is correct".
 */

export const PRINT_ROUTE = 'admin-badge-print';

/** Route whose captures show the same template in the EDITOR. */
export const EDITOR_ROUTE = 'admin-badge-editor-edit';

/** Explicit rasterisation density — pinned so captures stay comparable over time. */
const DENSITY = 200;

export interface PrintedBadgeCapture {
    /** The exported PDF, exactly as the route returned it. */
    pdf: Buffer;
    /** Rasterised pages, in page order, already stored in the capture directory. */
    pages: Array<{ fileName: string; path: string }>;
    pageCount: number;
    density: number;
    templateId: number;
    accreditationId: number;
    /** The rasteriser's own report (method taken, pages, postcondition result). */
    rasterSummary: string;
}

/**
 * Locates `scripts/pdf-to-png-vision.sh` by walking up from the working
 * directory, so the harness works from `frontend/` (the documented way) and from
 * a repo-root invocation. Throws by name: a missing rasteriser must fail the
 * capture, never silently skip the print check.
 */
export function rasterizerScriptPath(): string {
    let dir = process.cwd();
    for (let depth = 0; depth < 4; depth += 1) {
        const candidate = path.join(dir, 'scripts', 'pdf-to-png-vision.sh');
        if (fs.existsSync(candidate)) {
            return candidate;
        }
        const parent = path.dirname(dir);
        if (parent === dir) {
            break;
        }
        dir = parent;
    }
    throw new Error(`pdf-to-png-vision.sh not found above ${process.cwd()} (walked 4 levels)`);
}

/**
 * The export itself, with the postconditions a test can actually assert:
 * 200, an `application/pdf` content type and the `%PDF-` magic. A streamed
 * error page (Laravel's HTML 500, a JSON 422 about a missing template) must not
 * be mistaken for a badge.
 */
async function exportBadgesPdf(
    page: Page,
    origin: string,
    accreditationId: number,
    templateId: number,
): Promise<Buffer> {
    const response = await page.request.post(
        `${origin}/api/admin/accreditations/${accreditationId}/badges/export`,
        { data: { format: 'pdf', template_id: templateId } },
    );
    if (response.status() !== 200) {
        const body = (await response.text()).slice(0, 300);
        throw new Error(`Badge export failed with status ${response.status()}: ${body}`);
    }
    const contentType = response.headers()['content-type'] ?? '';
    if (!contentType.includes('application/pdf')) {
        throw new Error(`Badge export answered content-type "${contentType}" instead of application/pdf`);
    }
    const buffer = await response.body();
    if (buffer.subarray(0, 5).toString('latin1') !== '%PDF-') {
        throw new Error('Badge export body is not a PDF (missing %PDF- magic)');
    }
    return buffer;
}

/**
 * Proves the export is gated by the session cookie the UI login set — i.e. that
 * the authenticated call above is authenticated BY that cookie and not by
 * something ambient. Without a login the same request is refused; that check is
 * the evidence the printed PDF came through the real, auth-gated route.
 */
export async function assertExportRefusedWithoutSession(
    page: Page,
    origin: string,
    accreditationId: number,
    templateId: number,
): Promise<number> {
    const response = await page.request.post(
        `${origin}/api/admin/accreditations/${accreditationId}/badges/export`,
        { data: { format: 'pdf', template_id: templateId } },
    );
    if (response.status() !== 401) {
        throw new Error(
            `Unauthenticated badge export answered ${response.status()} instead of 401 — the auth gate ` +
                'is not what makes the authenticated export succeed, so the print check proves nothing.',
        );
    }
    return response.status();
}

interface RasterResult {
    /** PNG paths, in page order, as reported by the script. */
    files: string[];
    /** The script's own summary lines (method, pages, postcondition) for the log. */
    summary: string;
}

/** Runs the rasteriser and returns the PNG paths it reported. */
async function rasterize(pdfPath: string, outDir: string): Promise<RasterResult> {
    const script = rasterizerScriptPath();
    let stdout: string;
    try {
        const result = await execFileAsync(
            'bash',
            [script, pdfPath, '-o', outDir, '-d', String(DENSITY)],
            { maxBuffer: 8 * 1024 * 1024 },
        );
        stdout = result.stdout;
    } catch (error) {
        const detail = error instanceof Error ? error.message : String(error);
        throw new Error(`pdf-to-png-vision.sh failed for ${pdfPath}: ${detail}`);
    }
    // The script's machine-readable contract: the PNG list after this marker.
    const marker = stdout.indexOf('PNG(s) für die Vision-Analyse:');
    if (marker < 0) {
        throw new Error(`pdf-to-png-vision.sh did not report its PNGs:\n${stdout.slice(-800)}`);
    }
    const files = stdout
        .slice(marker)
        .split('\n')
        .slice(1)
        .map((line) => line.trim())
        .filter((line) => line.endsWith('.png'))
        .map((line) => path.resolve(line));
    if (files.length === 0) {
        throw new Error(`pdf-to-png-vision.sh reported no PNG:\n${stdout.slice(-800)}`);
    }
    for (const file of files) {
        if (!fs.existsSync(file)) {
            throw new Error(`pdf-to-png-vision.sh reported a missing PNG: ${file}`);
        }
    }
    return { files, summary: stdout.slice(0, marker).trim() };
}

/**
 * Exports the badges over the production route, rasterises them and stores the
 * PDF + one PNG per page NEXT TO the editor captures of the same template, so
 * both views of that template land in ONE vision batch.
 */
export async function capturePrintedBadges(params: {
    page: Page;
    origin: string;
    dir: string;
    dataset: UiReviewDataset;
    state: CaptureMeta['state'];
    viewport: CaptureMeta['viewport'];
}): Promise<PrintedBadgeCapture> {
    const { page, origin, dir, dataset, state, viewport } = params;
    const templateId = dataset.badgeTemplates.editor.id;
    const accreditationId = dataset.print.accreditationId;
    const expectedPages = dataset.print.applications.length;

    const pdf = await exportBadgesPdf(page, origin, accreditationId, templateId);
    storeArtifact(dir, `${PRINT_ROUTE}.pdf`, pdf);

    // Rasterise in a scratch directory and READ the pages out of it before the
    // cleanup — the PNGs are the review artifact, the scratch dir is not.
    const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ui-review-print-'));
    let raster: RasterResult;
    let buffers: Buffer[];
    try {
        const pdfPath = path.join(workDir, `${PRINT_ROUTE}.pdf`);
        fs.writeFileSync(pdfPath, pdf);
        raster = await rasterize(pdfPath, workDir);
        buffers = raster.files.map((file) => fs.readFileSync(file));
    } finally {
        fs.rmSync(workDir, { recursive: true, force: true });
    }

    // The page-count postcondition the backend has none of: one A6 card per
    // approved application. A renderer that dropped a card, or a fixture whose
    // approval silently did not happen, fails here instead of producing a
    // plausible-looking one-page PDF.
    if (buffers.length !== expectedPages) {
        throw new Error(
            `Printed ${buffers.length} badge page(s) for ${expectedPages} approved application(s) — ` +
                'the export and the fixture disagree.',
        );
    }

    storePngSeries(dir, `${PRINT_ROUTE}-pdf`, buffers);

    const stored = buffers.map((_buffer, index) => ({
        fileName: `${PRINT_ROUTE}-pdf-${index + 1}.png`,
        path: path.join(dir, `${PRINT_ROUTE}-pdf-${index + 1}.png`),
    }));
    storeMeta(dir, {
        route: PRINT_ROUTE,
        state,
        viewport,
        file: `${PRINT_ROUTE}.pdf`,
        // For a print capture "bands" counts PAGES, not scroll bands: the
        // measurement that matters here is how many cards the renderer produced.
        bands: buffers.length,
        scrollHeightPx: 0,
        viewportHeightPx: 0,
        sectionScrollStep: 0,
        runKey: dataset.runKey,
        capturedAt: new Date().toISOString(),
        dataset: dataset.fingerprint,
        // The print pair is pinned to the same row the editor captures show, so
        // the sidecar names it: template id + accreditation id (see
        // `CaptureMeta.entityIds`).
        entityIds: { templateId, accreditationId },
        // The rendered print PAGES, which is the same role `contentCount` plays
        // for a DOM capture (see `CaptureMeta.contentCount`): how much of the
        // artifact the reviewer is actually looking at. For a print capture that
        // happens to be the `bands` value above.
        contentCount: buffers.length,
        pathname: `/api/admin/accreditations/${accreditationId}/badges/export`,
        compareWith: EDITOR_ROUTE,
        visionNote: printVisionNote(expectedPages),
    });

    return {
        pdf,
        pages: stored,
        pageCount: buffers.length,
        density: DENSITY,
        templateId,
        accreditationId,
        rasterSummary: raster.summary,
    };
}

/**
 * The instruction handed to the reviewer with these files (the model reads the
 * PNGs itself, D28). It says the one
 * thing that makes the pair readable: these are TWO VIEWS OF THE SAME TEMPLATE —
 * browser editor against printed paper — and a defect that shows up in only one
 * of them is the finding.
 */
export function printVisionNote(expectedPages: number): string {
    return (
        `TWO VIEWS OF THE SAME BADGE TEMPLATE: \`${EDITOR_ROUTE}.png\` is the browser editor, ` +
        `\`${PRINT_ROUTE}-pdf-1.png\`…\`-${expectedPages}.png\` is what the printer gets (dompdf, one A6 ` +
        'card per approved application). Compare them field by field: is every box where the editor ' +
        'puts it, is the photo fitted (not stretched), is the QR square and scannable, is the text ' +
        'inside its box, does the card fill the page without a stray second page. A defect visible in ' +
        'exactly ONE of the two views is the finding — the browser understands `object-fit` and dompdf ' +
        'does not, so the editor can look right while the print is wrong.'
    );
}
