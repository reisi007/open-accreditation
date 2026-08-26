import { expect, test } from '@playwright/test';
import { loginAdminApi } from './helpers/admin-data';

/**
 * Badge template editor basis UI (FE2, features/badge-template-editor.md):
 * palette adds elements (data fields, qr, image), the A6 canvas selects
 * fields, the properties panel edits mm coordinates / source unions, and a
 * saved schema-v2 layout roundtrips through the server-authoritative API.
 *
 * FE4 polish coverage: corner-resize handles (w/h roundtrip), keyboard
 * nudging (1 mm / Shift = 5 mm), alignment guide lines while dragging and the
 * auto-fit sample text (FE3-F1 — text never overflows its box vertically).
 *
 * The badge-images backend slice (upload/delivery API) is pending per spec —
 * its endpoints are STUBBED via page.route so the FE2 upload flow is
 * verifiable end-to-end at the UI level.
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Roundtrip');

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
        const templateRow = main.getByRole('row', { name: /E2E Editor Roundtrip/ });
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Bounds');

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
        await expect(main.getByRole('row', { name: /E2E Editor Bounds/ })).toBeVisible();
    });

    test('drags a field onto the grid and persists the snapped position', {
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Drag');

        // The default name row starts at the origin (x=0, y=0, w=40 mm).
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        const box = await nameBox.boundingBox();
        if (!box) throw new Error('name box not rendered on the canvas');

        // Pointer drag (mouse): px delta scaled by the rendered box width
        // (40 mm) → mm, so the expectation is resolution-independent.
        const pxPerMm = box.width / 40;
        const deltaX = 150;
        const deltaY = 90;
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width / 2 + deltaX, box.y + box.height / 2 + deltaY, { steps: 8 });
        await page.mouse.up();

        // The panel mirrors the dragged position, snapped onto the 5 mm grid:
        // raw x ≈ 150/pxPerMm → nearest multiple of 5, clamped to ≤ 65.
        const expectedX = Math.min(Math.round(deltaX / pxPerMm / 5) * 5, 105 - 40);
        const expectedY = Math.min(Math.round(deltaY / pxPerMm / 5) * 5, 148 - 8);
        expect(expectedX).toBeGreaterThan(0);
        expect(expectedY).toBeGreaterThan(0);
        await expect(dialog.getByLabel('X (mm)')).toHaveValue(String(expectedX));
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue(String(expectedY));

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: /E2E Editor Drag/ });
        await expect(templateRow).toBeVisible();

        // Roundtrip: the dragged position survives save + reopen.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        const editCanvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });
        await editCanvas.getByRole('button', { name: 'Feld Name' }).click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue(String(expectedX));
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue(String(expectedY));
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Overlap');

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
        await expect(main.getByRole('row', { name: /E2E Editor Overlap/ })).toBeVisible();
    });

    test('saves an image element from an uploaded source and restores it', {
        tag: ['@feature:badge-editor'],
    }, async ({ page }) => {
        // The badge-images API is stubbed until the backend slice lands.
        await page.route('**/api/admin/badge-images**', (route) => {
            if (route.request().method() === 'POST') {
                return route.fulfill({
                    status: 201,
                    contentType: 'application/json',
                    body: JSON.stringify({ data: { id: 42, original_name: 'e2e-upload.png', mime: 'image/png' } }),
                });
            }
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: [
                        { id: 17, original_name: 'wappen.png', mime: 'image/png' },
                        { id: 42, original_name: 'e2e-upload.png', mime: 'image/png' },
                    ],
                }),
            });
        });

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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Upload');

        await dialog.getByRole('button', { name: 'Bild', exact: true }).click();
        await dialog.getByLabel('Quelle').selectOption('upload');

        // Existing uploads are listed; picking one fills the required id.
        const imageSelect = dialog.getByLabel('Vorhandenes Bild');
        await expect(imageSelect).toBeEnabled();
        await expect(imageSelect.locator('option', { hasText: 'wappen.png' })).toBeAttached();

        // Upload a new file through the file input + upload button.
        await dialog
            .getByLabel('Neues Bild hochladen')
            .setInputFiles({ name: 'e2e-upload.png', mimeType: 'image/png', buffer: Buffer.from(TINY_PNG_BASE64, 'base64') });
        await dialog.getByRole('button', { name: 'Bild hochladen', exact: true }).click();
        await expect(imageSelect).toHaveValue('42');

        await dialog.getByLabel('Skalierung').selectOption({ label: 'Einpassen' });

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: /E2E Editor Upload/ });
        await expect(templateRow).toBeVisible();

        // The uploaded image id survives storage + serialization.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        const editCanvas = dialog.getByRole('group', { name: 'Ausweis-Vorschau' });
        await editCanvas.getByRole('button', { name: 'Feld Bild' }).click();
        await expect(dialog.getByLabel('Quelle')).toHaveValue('upload');
        await expect(dialog.getByLabel('Vorhandenes Bild')).toHaveValue('42');
        await expect(dialog.getByLabel('Skalierung')).toHaveValue('contain');
    });

    test('resizes a field with the corner handle and persists w/h across reopen', {
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Resize');

        // Default name row: x=0, y=0, 40×8 mm. Selecting it shows the four
        // corner resize handles.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        const seHandle = nameBox.locator('[data-resize-handle="se"]');
        await expect(seHandle).toBeVisible();

        const box = await nameBox.boundingBox();
        const handle = await seHandle.boundingBox();
        if (!box || !handle) throw new Error('name box or se handle not rendered on the canvas');
        const pxPerMm = box.width / 40; // rendered box width corresponds to w = 40 mm

        // Corner drag: grows the box; the SE corner keeps x/y untouched.
        const deltaX = 45;
        const deltaY = 25;
        await page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
        await page.mouse.down();
        await page.mouse.move(handle.x + handle.width / 2 + deltaX, handle.y + handle.height / 2 + deltaY, { steps: 8 });
        await page.mouse.up();

        const expectedW = Math.min(Math.round((40 + deltaX / pxPerMm) / 5) * 5, 105);
        const expectedH = Math.min(Math.round((8 + deltaY / pxPerMm) / 5) * 5, 148);
        expect(expectedW).toBeGreaterThan(40);
        expect(expectedH).toBeGreaterThan(8);
        await expect(dialog.getByLabel('Breite (mm)')).toHaveValue(String(expectedW));
        await expect(dialog.getByLabel('Höhe (mm)')).toHaveValue(String(expectedH));
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('0');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('0');

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: /E2E Editor Resize/ });
        await expect(templateRow).toBeVisible();

        // Roundtrip: the resized geometry survives save + reopen.
        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        await dialog
            .getByRole('group', { name: 'Ausweis-Vorschau' })
            .getByRole('button', { name: 'Feld Name' })
            .click();
        await expect(dialog.getByLabel('Breite (mm)')).toHaveValue(String(expectedW));
        await expect(dialog.getByLabel('Höhe (mm)')).toHaveValue(String(expectedH));
    });

    test('nudges the selected field with arrow keys (1 mm, Shift = 5 mm)', {
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Nudge');

        // Deterministic base position via the panel, then focus the box again.
        const nameBox = canvas.getByRole('button', { name: 'Feld Name' });
        await nameBox.click();
        await dialog.getByLabel('X (mm)').fill('10');
        await dialog.getByLabel('Y (mm)').fill('10');
        await nameBox.click();

        await page.keyboard.press('ArrowRight');
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('11');
        await page.keyboard.press('Shift+ArrowDown');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('15');
        await page.keyboard.press('ArrowUp');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('14');
        await page.keyboard.press('ArrowLeft');
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('10');

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: /E2E Editor Nudge/ });
        await expect(templateRow).toBeVisible();

        await templateRow.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog.getByRole('heading', { name: 'Template bearbeiten' })).toBeVisible();
        await dialog
            .getByRole('group', { name: 'Ausweis-Vorschau' })
            .getByRole('button', { name: 'Feld Name' })
            .click();
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('10');
        await expect(dialog.getByLabel('Y (mm)')).toHaveValue('14');
    });

    test('shows alignment guides while a dragged edge is flush with another field', {
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Guides');

        await dialog.getByRole('button', { name: 'Bild', exact: true }).click();
        await dialog.getByLabel('Quelle').selectOption('brand');
        // Park the image far from every alignment target first.
        await dialog.getByLabel('X (mm)').fill('60');
        await dialog.getByLabel('Y (mm)').fill('60');

        const card = await canvas.boundingBox();
        const imageBox = await canvas.getByRole('button', { name: 'Feld Bild' }).boundingBox();
        if (!card || !imageBox) throw new Error('canvas or image box not rendered');
        const pxPerMmX = card.width / 105;

        // Drag left so the image's LEFT edge lands flush with the name row's
        // RIGHT edge (x = 40 mm): grid snap brings it onto 40, the guide line
        // appears while the pointer is still held down …
        const startX = imageBox.x + imageBox.width / 2;
        const startY = imageBox.y + imageBox.height / 2;
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX - 19.6 * pxPerMmX, startY, { steps: 8 });
        await expect(canvas.locator('[data-badge-guide="vertical"]').first()).toBeVisible();

        // … and disappears on release.
        await page.mouse.up();
        await expect(canvas.locator('[data-badge-guide]')).toHaveCount(0);
        await expect(dialog.getByLabel('X (mm)')).toHaveValue('40');
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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Autofit');

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

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Editor Duplicate');

        // Two Foto fields raise the soft duplicate warning (FE2-F1)…
        await dialog.getByRole('button', { name: 'Foto', exact: true }).click();
        await dialog.getByRole('button', { name: 'Foto', exact: true }).click();
        await expect(dialog.getByText('Datenfeld ist mehrfach vorhanden.')).toBeVisible();

        // …which must NOT block saving (soft warning, server stays authoritative).
        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        await expect(main.getByRole('row', { name: /E2E Editor Duplicate/ })).toBeVisible();
    });
});
