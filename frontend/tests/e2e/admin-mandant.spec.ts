import { expect, test } from '@playwright/test';
import { loginAdminApi } from './helpers/admin-data';

// 1×1 transparent PNG — the same fixture bytes used for the portrait upload
// in helpers/admin-data.ts.
const PNG_1PX_BASE64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

test.describe('Admin: Mandanten (P2a)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create mandant with domain and team', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        const suffix = Date.now();
        const uniqueName = `E2E Mandant ${suffix}`;
        const uniqueSlug = `e2e-mandant-${suffix}`;
        const domainHostname = `${uniqueSlug}.test`;
        const teamName = `E2E Team ${suffix}`;
        const teamSlug = `e2e-team-${suffix}`;

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Mandanten' })).toBeVisible();

        // Create a new mandant.
        await adminMain.getByRole('link', { name: 'Neu' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants\/new$/);

        const createMain = page.getByRole('main');
        await createMain.getByLabel('Name', { exact: true }).fill(uniqueName);
        await createMain.getByLabel('Slug', { exact: true }).fill(uniqueSlug);
        // Teams are an opt-in feature — enable them so the team step below works.
        await createMain.getByLabel('Teams aktivieren', { exact: true }).check();
        await createMain.getByRole('button', { name: 'Mandant erstellen' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants\/\d+$/);
        await expect(page.getByRole('main').getByRole('heading', { level: 1, name: uniqueName })).toBeVisible();

        // Add a domain.
        const detailMain = page.getByRole('main');
        await detailMain.getByLabel('Domain', { exact: true }).fill(domainHostname);
        await detailMain.getByRole('button', { name: 'Domain hinzufügen' }).click();
        await expect(detailMain.getByText(domainHostname)).toBeVisible();

        // Add a team.
        await detailMain.getByRole('button', { name: 'Team hinzufügen' }).click();
        await detailMain.getByLabel('Team-Name', { exact: true }).fill(teamName);
        await detailMain.getByLabel('Team-Slug', { exact: true }).fill(teamSlug);
        await detailMain.getByRole('button', { name: 'Team speichern' }).click();
        await expect(detailMain.getByText(teamName)).toBeVisible();

        // The new mandant shows up in the list.
        await page.getByRole('complementary').getByRole('link', { name: 'Mandanten' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);
        await expect(page.getByRole('main').getByRole('row', { name: new RegExp(uniqueName) })).toBeVisible();
    });

    test('self-service logo upload', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // Navigate via the sidebar to the self-service media page.
        await page.getByRole('complementary').getByRole('link', { name: 'Logo & Header' }).click();
        await expect(page).toHaveURL(/\/admin\/media$/);

        const mediaMain = page.getByRole('main');
        await expect(mediaMain.getByRole('heading', { level: 1, name: 'Logo & Header' })).toBeVisible();

        // The seeded primary mandant has no logo → the Logo field shows the empty state.
        const logoField = mediaMain.getByLabel('Logo', { exact: true }).locator('..');
        await expect(logoField.getByText('Kein Bild hinterlegt.')).toBeVisible();

        // Upload the 1×1 PNG fixture.
        await logoField.getByLabel('Logo', { exact: true }).setInputFiles({
            name: 'logo.png',
            mimeType: 'image/png',
            buffer: Buffer.from(PNG_1PX_BASE64, 'base64'),
        });
        await logoField.getByRole('button', { name: 'Hochladen' }).click();

        // After the overview re-fetch the logo preview image becomes visible.
        await expect(logoField.getByRole('img', { name: 'Logo' })).toBeVisible();

        // Remove it again and the preview returns to the empty state.
        await logoField.getByRole('button', { name: 'Entfernen' }).click();
        await expect(logoField.getByText('Kein Bild hinterlegt.')).toBeVisible();
    });

    test('mandant list shows logo column and portal link', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        const suffix = Date.now();
        const uniqueSlug = `e2e-liste-${suffix}`;
        const domainHostname = `${uniqueSlug}.test`;

        // Deterministic fixture: a fresh mandant with a domain via the API, so
        // the portal-link assertion does not depend on the parallel
        // create-mandant test in this file.
        const api = await loginAdminApi();
        try {
            const create = await api.post('/api/admin/mandants', {
                data: {
                    name: `E2E Liste ${suffix}`,
                    slug: uniqueSlug,
                    teams_enabled: false,
                    is_active: true,
                    impressum_text: '',
                    privacy_text: '',
                },
            });
            expect(create.status()).toBe(201);
            const mandant = (await create.json()).data;
            const domain = await api.post(`/api/admin/mandants/${mandant.id}/domains`, {
                data: { hostname: domainHostname },
            });
            expect(domain.status()).toBe(201);
        } finally {
            await api.dispose();
        }

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('columnheader', { name: 'Logo' })).toBeVisible();

        // The domain hostname is rendered as an external portal link.
        const portalLink = adminMain.getByRole('link', { name: new RegExp(domainHostname) });
        await expect(portalLink).toBeVisible();
        await expect(portalLink).toHaveAttribute('href', `http://${domainHostname}`);
        await expect(portalLink).toHaveAttribute('target', '_blank');
        await expect(portalLink).toHaveAttribute('rel', 'noreferrer');
    });
});
