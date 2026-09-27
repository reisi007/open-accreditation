import { expect, test } from '@playwright/test';
import { loginAdminApi, uniqueSuffix } from './helpers/admin-data';

/**
 * Badge template editor basis UI (FE2, features/badge-template-editor.md):
 * palette adds elements (data fields, qr, image), the A6 canvas selects
 * fields, the properties panel edits mm coordinates / source unions, and a
 * saved schema-v2 layout roundtrips through the server-authoritative API.
 *
 * Editor model „Raster + konfigurierbare Labels" (2026-09-27): the canvas
 * previews a 5 mm grid; the absolute geometry is typed into the properties
 * panel, and the canvas itself carries selection plus the arrow-key nudge
 * (1 mm, Shift = 5 mm — restored after the revert, because the panel's
 * `step="any"` inputs cannot move a field relatively). The FE3/FE4 POINTER
 * specs (mouse drag, corner-resize handles, magnetic guides) stay removed — the
 * tests below assert their absence alongside the panel- and keyboard-driven
 * replacements.
 *
 * The badge-images backend slice (upload/delivery API) is implemented — the
 * upload flow test exercises the real `POST /api/admin/badge-images` endpoint
 * with a tiny PNG fixture.
 *
 * Style note: tests/e2e files are parsed by ESLint with the plain-ES2020
 * parser (no TS syntax) while tsc strict-checks them — so everything stays
 * inline in the test callbacks where `page` is inferred.
 */

const TINY_PNG_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

test.describe('Badge-Template-Editor (FE2)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to spare the shared per-IP
    // login throttle budget (same reasoning as badge.spec.ts).
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    // Self-cleaning: purge the UI-created editor templates via the admin API,
    // even on failure (same pattern as badge.spec.ts).
    test.afterAll(async () => {
        const api = await loginAdminApi();
        try {
            const body = await (await api.get('/api/admin/badge-templates')).json();
            const templates = body.data ?? [];
            for (const template of templates) {
                if ((template.name ?? '').startsWith('E2E Editor')) {
                    await api.delete(`/api/admin/badge-templates/${template.id}`);
                }
            }
        } finally {
            await api.dispose();
        }
    });

    test('adds elements, edits coordinates and persists them across reopen', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        // Direct admin-URL load is the allowed route-guard exception: as a
        // guest, RequireAdmin redirects to /login and the SPA returns after
        // the login.
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Neues Template' })).toBeVisible();
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        const templateName = `E2E Editor Roundtrip ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // The default name row: select it on the canvas, edit it in the panel.
        await canvas.getByRole('button', { name: 'Feld Name' }).click();
        await dialog.getByLabel('X (mm)').fill('10');
        await dialog.getByLabel('Y (mm)').fill('20');
        await dialog.getByLabel('Breite (mm)').fill('60');
        await dialog.getByLabel('Höhe (mm)').fill('12');
        await dialog.getByLabel('Schriftgröße (pt)').fill('16');

        // qr: only one may exist — the second add attempt is disabled.
        await dialog.getByRole('button', { name: 'QR-Code', exact: true }).click();
        await expect(dialog.getByRole('button', { name: 'QR-Code', exact: true })).toBeDisabled();
        await expect(canvas.getByRole('button', { name: 'Feld QR-Code' })).toBeVisible();

        // image: brand source + fit selection in the properties panel.
        await dialog.getByRole('button', { name: 'Bild', exact: true }).click();
        await dialog.getByLabel('Quelle').selectOption('brand');
        await dialog.getByLabel('Mandanten-Bild', { exact: true }).selectOption({ label: 'Kopfbild' });
        await dialog.getByLabel('Skalierung').selectOption({ label: 'Füllen' });

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: new RegExp(templateName) });
        await expect(templateRow).toBeVisible();
        await expect(templateRow.getByText('3 Felder')).toBeVisible();

        // Persistence roundtrip: reopen and find every edited value restored.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        const editCanvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        await editCanvas.getByRole('button', { name: 'Feld Name' }).click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('10');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('20');
        await expect(dialog.getByLabel('Breite (mm)')).toHaveValue('60');
        await expect(dialog.getByLabel('Höhe (mm)')).toHaveValue('12');
        await expect(dialog.getByLabel('Schriftgröße (pt)')).toHaveValue('16');

        await editCanvas.getByRole('button', { name: 'Feld Bild' }).click();
        await expect(dialog.getByLabel('Quelle')).toHaveValue('brand');
        await expect(dialog.getByLabel('Mandanten-Bild', { exact: true })).toHaveValue('header');
        await expect(dialog.getByLabel('Skalierung')).toHaveValue('cover');
    });

    test('blocks saving a field outside the A6 bounds with a validation error', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        const templateName = `E2E Editor Bounds ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // x + w = 110 > 105: the client-side mirror of the server rule must
        // block the submit BEFORE any network call.
        await canvas.getByRole('button', { name: 'Feld Name' }).click();
        await dialog.getByLabel('X (mm)').fill('100');
        await dialog.getByLabel('Breite (mm)').fill('10');

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        await expect(dialog.getByText('Das Feld ragt über den rechten Rand hinaus.')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Template erstellen' })).toBeVisible();

        // Fixing the geometry lets the save go through.
        await dialog.getByLabel('X (mm)').fill('40');
        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        await expect(main.getByRole('row', { name: new RegExp(templateName) })).toBeVisible();
    });

    test('places a field on the 5 mm grid from the panel and persists the position', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        const templateName = `E2E Editor Grid ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // The visible raster is the editor's orientation aid: its cell must
        // measure the agreed 5 mm against the real A6 sheet (105 × 148 mm).
        // Scoped through the `group` landmark — the card itself is a plain div.
        // Chromium re-serialises inline styles to 6 significant digits, so the
        // comparison runs at 3 decimals — far tighter than the next plausible
        // grid step (10 mm would read 9.5238 % / 6.7568 %).
        const raster = canvas.locator('.badge-canvas-container > div[aria-hidden="true"]');
        const rasterStyle = await raster.getAttribute('style');
        const cell = /([\d.]+)% ([\d.]+)%/.exec(rasterStyle ?? '');
        if (!cell) throw new Error('raster background-size not found');
        expect(parseFloat(cell[1])).toBeCloseTo((5 / 105) * 100, 3);
        expect(parseFloat(cell[2])).toBeCloseTo((5 / 148) * 100, 3);

        // Position comes from the properties panel (the default name row starts
        // at the origin), NOT from a pointer gesture.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await dialog.getByLabel('X (mm)').fill('25');
        await dialog.getByLabel('Y (mm)').fill('40');

        // WYSIWYG in mm: the box really sits 25/105 of the card width from the
        // left and 40/148 of its height from the top.
        const card = await canvas.locator('.badge-canvas-container').boundingBox();
        const box = await nameBox.boundingBox();
        if (!card || !box) throw new Error('canvas or name box not rendered');
        expect(((box.x - card.x) / card.width) * 105).toBeCloseTo(25, 0);
        expect(((box.y - card.y) / card.height) * 148).toBeCloseTo(40, 0);

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: new RegExp(templateName) });
        await expect(templateRow).toBeVisible();

        // Roundtrip: the panel-authored position survives save + reopen.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        const editCanvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });
        await editCanvas.getByRole('button', { name: 'Feld Name' }).click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('25');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('40');
    });

    test('warns about overlapping fields without blocking the save', {
        tag: ['@feature:badge-editor'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');

        const templateName = `E2E Editor Overlap ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // A new image element is placed at a FREE position first (no warning).
        await dialog.getByRole('button', { name: 'Bild', exact: true }).click();
        await expect(dialog.getByText('Felder überschneiden sich.')).toHaveCount(0);

        // Moving it onto the default name row (0,0, 40×8) triggers the soft
        // overlap warning…
        await dialog.getByLabel('X (mm)').fill('0');
        await dialog.getByLabel('Y (mm)').fill('0');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });
        await expect(dialog.getByText('Felder überschneiden sich.')).toBeVisible();
        await expect(canvas.getByRole('button', { name: 'Feld Bild' })).toHaveAttribute(
            'title',
            'Felder überschneiden sich.',
        );

        // …which must NOT block saving (server-authoritative validation only
        // rejects hard rules like bounds/min sizes).
        await dialog.getByLabel('Quelle').selectOption('brand');
        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        await expect(main.getByRole('row', { name: new RegExp(templateName) })).toBeVisible();
    });

    test('uploads a badge image and persists it as a layout source across reopen', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');

        const templateName = `E2E Editor Upload ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        await dialog.getByRole('button', { name: 'Bild', exact: true }).click();
        await dialog.getByLabel('Quelle').selectOption('upload');

        // Upload a real tiny PNG through the file input + upload button —
        // the backend persists it on the private disk and returns its id.
        const uploadResponse = page.waitForResponse(
            (resp) => resp.url().includes('/api/admin/badge-images')
                && resp.request().method() === 'POST'
                && resp.status() === 201,
        );
        await dialog
            .getByLabel('Neues Bild hochladen')
            .setInputFiles({ name: 'e2e-upload.png', mimeType: 'image/png', buffer: Buffer.from(TINY_PNG_BASE64, 'base64') });
        await dialog.getByRole('button', { name: 'Bild hochladen', exact: true }).click();
        const uploaded = await uploadResponse;
        const uploadedBody = await uploaded.json();
        const uploadedId = Number(uploadedBody?.data?.id);

        // The freshly uploaded image is now selectable in the existing-images
        // dropdown and carries the uploaded filename.
        const imageSelect = dialog.getByLabel('Vorhandenes Bild');
        await expect(imageSelect).toHaveValue(String(uploadedId));
        await expect(imageSelect.locator('option', { hasText: 'e2e-upload.png' })).toBeAttached();

        await dialog.getByLabel('Skalierung').selectOption({ label: 'Einpassen' });

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: new RegExp(templateName) });
        await expect(templateRow).toBeVisible();

        // The uploaded image id survives storage + serialization.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        const editCanvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });
        await editCanvas.getByRole('button', { name: 'Feld Bild' }).click();
        await expect(dialog.getByLabel('Quelle')).toHaveValue('upload');
        await expect(dialog.getByLabel('Vorhandenes Bild')).toHaveValue(String(uploadedId));
        await expect(dialog.getByLabel('Skalierung')).toHaveValue('contain');
    });

    test('resizes a field from the panel and persists w/h across reopen', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        const templateName = `E2E Editor Resize ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // Default name row: x=0, y=0, 40×8 mm. Selecting it must NOT reveal
        // corner handles any more — the removed resize affordance is gone for
        // good, not merely restyled.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await expect(nameBox).toHaveAttribute('aria-pressed', 'true');
        await expect(nameBox.locator('[data-resize-handle]')).toHaveCount(0);

        // Size is authored numerically; x/y stay untouched by a resize.
        await dialog.getByLabel('Breite (mm)').fill('70');
        await dialog.getByLabel('Höhe (mm)').fill('20');
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('0');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('0');

        // WYSIWYG in mm: 70 of 105 mm card width, 20 of 148 mm card height.
        const card = await canvas.locator('.badge-canvas-container').boundingBox();
        const box = await nameBox.boundingBox();
        if (!card || !box) throw new Error('canvas or name box not rendered');
        expect((box.width / card.width) * 105).toBeCloseTo(70, 0);
        expect((box.height / card.height) * 148).toBeCloseTo(20, 0);

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: new RegExp(templateName) });
        await expect(templateRow).toBeVisible();

        // Roundtrip: the panel-authored geometry survives save + reopen.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        await dialog
            .getByRole('group', { name: 'Ausweis-Vorschau' })
            .getByRole('button', { name: 'Feld Name' })
            .click();
        await expect(dialog.getByLabel('Breite (mm)')).toHaveValue('70');
        await expect(dialog.getByLabel('Höhe (mm)')).toHaveValue('20');
    });

    test('never moves a field by pointer drag — the panel and the arrow keys are the only writers', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        await dialog.getByLabel('Name', { exact: true }).fill(`E2E Editor NoDrag ${uniqueSuffix()}`);

        // Deterministic base position via the panel, then the box itself.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await dialog.getByLabel('X (mm)').fill('30');
        await dialog.getByLabel('Y (mm)').fill('50');
        const before = await nameBox.boundingBox();
        const card = await canvas.locator('.badge-canvas-container').boundingBox();
        if (!before || !card) throw new Error('canvas or name box not rendered on the canvas');

        // A full press → sweep → release across the box, then a sweep over
        // the whole card to the far corner. The removed
        // handleDragStart/Move/End trio consumed exactly this gesture.
        // (The release lands on the card background, which legitimately
        // clears the selection — so re-select before reading the panel.)
        const startX = before.x + before.width / 2;
        const startY = before.y + before.height / 2;
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX + 30, startY + 12, { steps: 6 });
        await page.mouse.move(card.x + card.width - 8, card.y + card.height - 8, { steps: 10 });
        await page.mouse.move(card.x + 8, card.y + 8, { steps: 10 });
        await page.mouse.up();

        // The box did not move by a single pixel…
        const afterDrag = await nameBox.boundingBox();
        if (!afterDrag) throw new Error('name box disappeared from the canvas');
        expect(afterDrag.x).toBeCloseTo(before.x, 1);
        expect(afterDrag.y).toBeCloseTo(before.y, 1);
        // … and the panel still holds exactly the authored values.
        await nameBox.click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('30');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('50');
    });

    test('nudges the focused field with arrow keys (1 mm, Shift = 5 mm) and persists it', {
        tag: ['@feature:badge-editor', '@regression'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        const templateName = `E2E Editor Nudge ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // The box is a native button: clicking it leaves it FOCUSED, so the
        // arrow keys reach the nudge without any tabIndex plumbing.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await expect(nameBox).toBeFocused();
        await dialog.getByLabel('X (mm)').fill('10');
        await dialog.getByLabel('Y (mm)').fill('10');
        // …re-focus: filling the panel moved the focus into the input.
        await nameBox.click();

        await page.keyboard.press('ArrowRight');
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('11');
        await page.keyboard.press('Shift+ArrowDown');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('15');
        await page.keyboard.press('ArrowUp');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('14');
        await page.keyboard.press('ArrowLeft');
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('10');

        // WYSIWYG in mm: the nudged box really sits at 10/105 and 14/148.
        const card = await canvas.locator('.badge-canvas-container').boundingBox();
        const box = await nameBox.boundingBox();
        if (!card || !box) throw new Error('canvas or name box not rendered');
        expect(((box.x - card.x) / card.width) * 105).toBeCloseTo(10, 0);
        expect(((box.y - card.y) / card.height) * 148).toBeCloseTo(14, 0);

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: new RegExp(templateName) });
        await expect(templateRow).toBeVisible();

        // Roundtrip: the nudged position survives save + reopen.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        await dialog
            .getByRole('group', { name: 'Ausweis-Vorschau' })
            .getByRole('button', { name: 'Feld Name' })
            .click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('10');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('14');
    });

    test('advertises the raster + properties panel instead of drag instructions', {
        tag: ['@feature:badge-editor'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        await dialog.getByLabel('Name', { exact: true }).fill(`E2E Editor NoGuide ${uniqueSuffix()}`);

        // The hint must point at the panel, and the superseded drag wording
        // must be gone from the document.
        await expect(
            dialog.getByText('Das Raster hat 5 mm. Position und Größe des gewählten Feldes stellst du im Eigenschaften-Panel ein.'),
        ).toBeVisible();
        await expect(dialog.getByText(/Ziehen verschiebt das Feld/)).toHaveCount(0);

        // No magnetic alignment guides, at rest or while the pointer sweeps.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await expect(canvas.locator('[data-badge-guide]')).toHaveCount(0);

        const box = await nameBox.boundingBox();
        if (!box) throw new Error('name box not rendered on the canvas');
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x - 60, box.y + 40, { steps: 8 });
        await expect(canvas.locator('[data-badge-guide]')).toHaveCount(0);
        await page.mouse.up();
        await expect(canvas.locator('[data-badge-guide]')).toHaveCount(0);
    });

    test('auto-fits the sample text into small boxes (FE3-F1 regression)', {
        tag: ['@feature:badge-editor'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        const canvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });

        await dialog.getByLabel('Name', { exact: true }).fill(`E2E Editor Autofit ${uniqueSuffix()}`);

        // The FE3-F1 bug: 16 pt sample text in a 4 mm tall box was clipped.
        // Regression setup: an authored size of 10 px in a WIDE (60 mm) box.
        // On every editor canvas down to a ~240 px tall card both auto-fit
        // caps sit ABOVE 10 px (desktop dialog canvas ≈ 312 × 443 px card:
        // width cap ≈ 21 px, height cap ≈ 21 px), so squeezing the box to a
        // 4 mm height must bind the height cap alone and shrink the font.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await dialog.getByLabel('Schriftgröße (pt)').fill('10');
        await dialog.getByLabel('Breite (mm)').fill('60');
        await dialog.getByLabel('Höhe (mm)').fill('4');

        const shrunk = await nameBox.evaluate((el) => {
            const inner = el.querySelector('span');
            if (!inner) throw new Error('content wrapper missing');
            return {
                fontSize: parseFloat(getComputedStyle(inner).fontSize),
                innerHeight: inner.getBoundingClientRect().height,
                boxHeight: el.getBoundingClientRect().height,
            };
        });
        expect(shrunk.fontSize).toBeLessThan(10);
        expect(shrunk.innerHeight).toBeLessThanOrEqual(shrunk.boxHeight);

        // A generous box keeps the authored size — no unnecessary shrink.
        // At 60 × 20 mm neither cap can drop below 10 px on any supported
        // viewport (break-even would be a card shorter than ~240 px), so
        // `min()` must resolve to the authored value itself.
        await dialog.getByLabel('Höhe (mm)').fill('20');
        const keptFontSize = await nameBox.evaluate((el) => {
            const inner = el.querySelector('span');
            if (!inner) throw new Error('content wrapper missing');
            return parseFloat(getComputedStyle(inner).fontSize);
        });
        expect(keptFontSize).toBe(10);
    });

    test('warns about duplicate data fields without blocking the save', {
        tag: ['@feature:badge-editor'],
    }, async ({ page }) => {
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');

        const templateName = `E2E Editor Duplicate ${uniqueSuffix()}`;
        await dialog.getByLabel('Name', { exact: true }).fill(templateName);

        // Two Foto fields raise the soft duplicate warning (FE2-F1)…
        await dialog.getByRole('button', { name: 'Foto', exact: true }).click();
        await dialog.getByRole('button', { name: 'Foto', exact: true }).click();
        await expect(dialog.getByText('Datenfeld ist mehrfach vorhanden.')).toBeVisible();

        // …which must NOT block saving (soft warning, server stays authoritative).
        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        await expect(main.getByRole('row', { name: new RegExp(templateName) })).toBeVisible();
    });
});
