import { expect, test } from '@playwright/test';
import { loginAdminApi } from './helpers/admin-data';

/**
 * Badge template editor basis UI (FE2, features/badge-template-editor.md):
 * palette adds elements (data fields, qr, image), the A6 canvas selects
 * fields, the properties panel edits mm coordinates / source unions, and a
 * saved schema-v2 layout roundtrips through the server-authoritative API.
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
});
