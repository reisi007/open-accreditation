import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { captureDir } from './helpers/capture-store';
import { assertExportRefusedWithoutSession, capturePrintedBadges, EDITOR_ROUTE, PRINT_ROUTE } from './helpers/badge-print';
import { uiReviewDataset } from './helpers/dataset';
import { loginViaUi } from './helpers/session';

/**
 * The PRINT capture: the same badge template the editor routes show, as the
 * printer receives it.
 *
 * The screenshot suite looked at the editor — HTML, CSS, a browser — and two
 * defect classes got through precisely because of that: the `object-fit`
 * stretching and the QR distortion. dompdf implements no `object-fit`, so a
 * browser screenshot cannot show a dompdf failure; it shows a *different
 * program's* rendering of the same template. `BadgeExportService` renders one A6
 * card per approved application and has no postcondition in the backend, so
 * nothing else in the suite ever looked at the output either.
 *
 * The contract (all of it load-bearing, see `helpers/badge-print.ts`):
 * - the PDF comes from the PRODUCTION export route
 *   (`POST /api/admin/accreditations/{id}/badges/export`, the request the export
 *   button in the approvals page makes), authenticated by the httpOnly session
 *   cookie of a real UI login — never from a directly called `renderPdf()`,
 *   which would be a different code path and would exclude the difference under
 *   test;
 * - `template_id` pins the export to the same template the editor captures show;
 * - rasterising goes through `scripts/pdf-to-png-vision.sh` (its own
 *   postcondition checks the alpha channel / opacity);
 * - the PNGs are stored NEXT TO the editor capture, so both views of that
 *   template reach the vision subagent in ONE batch.
 *
 * ## What this does and does not promise
 *
 * It checks ONE template against a handful of applications, and it is a
 * **class** check: "photo and QR are fitted, fields stay in their boxes, the
 * page break is right". A clean run says "this layout prints correctly for this
 * data" — never "the export is correct". Hundreds of real badges, real names,
 * real photo crops are outside what this loop sees.
 */

const PRIMARY_ORIGIN = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'admin';

test('printed badge export (filled, desktop)', { tag: ['@screenshot'] }, async ({ page }, testInfo) => {
    // Desktop only: the editor captures this template lives in the desktop
    // directory, and the point of the artifact is that both views of the SAME
    // template sit in one batch.
    test.skip(testInfo.project.name !== 'Desktop Chrome', 'the print pair is stored with the desktop editor captures');

    const dataset = await uiReviewDataset();
    const accreditationId = dataset.print.accreditationId;
    const templateId = dataset.badgeTemplates.editor.id;

    // Proof that the export is gated by the session cookie and not by something
    // ambient: before the login the very same request must be refused.
    const refused = await assertExportRefusedWithoutSession(page, PRIMARY_ORIGIN, accreditationId, templateId);
    expect(refused).toBe(401);

    await loginViaUi(page, PRIMARY_ORIGIN, ADMIN_EMAIL, ADMIN_PASSWORD, /\/admin/);

    const dir = captureDir('filled', 'desktop');
    const capture = await capturePrintedBadges({
        page,
        origin: PRIMARY_ORIGIN,
        dir,
        dataset,
        state: 'filled',
        viewport: 'desktop',
    });

    // The editor half of the pair may not have been captured in this run (a `-g
    // print` run skips it). That is worth saying out loud, not worth failing:
    // the pairing is a review instruction, and the harness cannot invent the
    // other half.
    const editorCapture = path.join(dir, `${EDITOR_ROUTE}.png`);
    if (!fs.existsSync(editorCapture)) {
        console.warn(
            `[ui-review] ${PRINT_ROUTE}: editor capture ${editorCapture} is missing — run the full suite ` +
                'before reviewing the print, otherwise the pair the reviewer needs does not exist.',
        );
    }

    console.log(
        `[ui-review] ${PRINT_ROUTE}: ${capture.pageCount} page(s) @ ${capture.density} dpi from ` +
            `accreditation ${capture.accreditationId} / template ${capture.templateId} ` +
            `(${capture.pdf.length} byte PDF) → ${capture.pages.map((printed) => printed.fileName).join(', ')}\n` +
            capture.rasterSummary,
    );
});
