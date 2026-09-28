import { expect, test } from '@playwright/test';
import { acquirePrimaryMandantLogoLock, loginAdminApi, uniqueSuffix } from './helpers/admin-data';
import { pngFixture } from '../screenshots/helpers/png-fixtures';
import { reclaimOwnedRows, rememberOwnedRow, resetOwnedRows } from './helpers/ownership';
// Per-test ownership (tests/e2e/helpers/ownership.ts): the ledger is emptied BEFORE
// the first create and drained AFTER every test, so a spec that dies half-way
// still gives back what it managed to build — three fixtures created, the fourth
// throws, the three go back. The serial globalTeardown stays as the net for a run
// that was KILLED before this hook could run: a different failure, needing a
// different net.
//
// At FILE scope, not inside a describe, on purpose: admin-mobile-layout.spec.ts
// has two describes, and a describe-scoped hook would have covered only the
// first — the exact "the teardown exists somewhere in this file" illusion the
// gate in namespace-isolation.spec.ts is meant to end. The teardown exits before
// its admin login when the ledger is empty, so a test that creates nothing pays
// nothing.
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});


// 1×1 transparent PNG, taken from the shared, CRC-VERIFIED fixture registry
// (`tests/screenshots/helpers/png-fixtures.ts`). It used to be a literal in this
// spec; `tests/e2e/png-fixtures.spec.ts` now fails on any base64 PNG literal in
// the tree that is not registered there — which is how the corrupt portrait that
// survived in two specs for so long becomes impossible to add a third time to.
const PNG_1PX_BASE64 = pngFixture('tiny-logo-image').toString('base64');

test.describe('Admin: Mandanten (P2a)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create mandant with domain and team', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const uniqueName = `E2E Mandant ${suffix}`;
        const uniqueSlug = `e2e-mandant-${suffix}`;
        const domainHostname = `${uniqueSlug}.test`;
        const teamName = `E2E Team ${suffix}`;
        const teamSlug = `e2e-team-${suffix}`;

        /**
         * The id of one UI-created row, found by its EXACT, worker-unique field
         * value.
         *
         * ## Why a lookup at all
         *
         * The mandant, its domain and its team are created THROUGH THE UI, and a
         * create form answers nothing the test can keep: the mandant's id shows
         * up in the URL, the domain's and the team's nowhere. So the rows were
         * never registered, and MEASURED with `E2E_PURGE=off`, twice in a row:
         * `mandants 0 → 1 → 2` — cumulative and unbounded, from a spec whose every
         * assertion is green. The serial name sweep does reclaim them eventually,
         * by prefix, which is exactly why the leak stays invisible for a whole
         * run.
         *
         * The lookup is by exact value and not by prefix, because `uniqueSuffix()`
         * stamps every one of these names per worker — a prefix search would
         * return a sibling's row and register the WRONG id, which is a worse
         * failure than no registration at all.
         *
         * ## Why it returns the id and does NOT register it
         *
         * The `rememberOwnedRow('<kind>', …)` call is written at each create site
         * instead of inside here, for two reasons: the kind is a LITERAL at the
         * call site, so the gate in `namespace-isolation.spec.ts` can read which
         * kinds a test registers (a generic `rememberOwnedRow(kind, …)` reads as a
         * registration of nothing); and the ordering the ledger depends on —
         * create, then immediately register — is visible in the test body.
         *
         * ## Why a short-lived session
         *
         * One call per create, right after its submit, so the third create
         * throwing cannot lose the first two rows: that is the half-failure
         * guarantee, and a session held across all three would put a live
         * resource between a create and its registration. `login` is throttled
         * per IP; three sessions it is.
         */
        async function findCreatedRowId(listUrl = '', key = '', value = '', kind = '') {
            const api = await loginAdminApi();
            try {
                const rows = (await (await api.get(listUrl)).json()).data ?? [];
                for (const row of rows) {
                    if (row[key] === value) {
                        return row.id;
                    }
                }
            } finally {
                await api.dispose();
            }
            // Throwing rather than warning is the point: a lookup that finds
            // nothing means the form and the API disagree, and the row is
            // already in the database. Carrying on would leave it behind with a
            // green test on top of it.
            throw new Error(
                `the form for a ${kind} reported success, but no row in ${listUrl} carries ${key} ` +
                    `"${value}". Its id is therefore unknown, so it cannot be given back, and this test ` +
                    'refuses to continue on top of a row it would leak.',
            );
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
        // Register BEFORE the domain step, so a failure in either of the two
        // steps below cannot lose the mandant.
        const mandantId = await findCreatedRowId('/api/admin/mandants', 'name', uniqueName, 'mandants');
        rememberOwnedRow('mandants', mandantId);

        // Add a domain.
        const detailMain = page.getByRole('main');
        await detailMain.getByLabel('Domain', { exact: true }).fill(domainHostname);
        await detailMain.getByRole('button', { name: 'Domain hinzufügen' }).click();
        await expect(detailMain.getByText(domainHostname)).toBeVisible();
        rememberOwnedRow(
            'mandantDomains',
            await findCreatedRowId(
                `/api/admin/mandants/${mandantId}/domains`,
                'hostname',
                domainHostname,
                'mandantDomains',
            ),
            mandantId,
        );

        // Add a team.
        await detailMain.getByRole('button', { name: 'Team hinzufügen' }).click();
        await detailMain.getByLabel('Team-Name', { exact: true }).fill(teamName);
        await detailMain.getByLabel('Team-Slug', { exact: true }).fill(teamSlug);
        await detailMain.getByRole('button', { name: 'Team speichern' }).click();
        await expect(detailMain.getByText(teamName)).toBeVisible();
        rememberOwnedRow(
            'teams',
            await findCreatedRowId(`/api/admin/mandants/${mandantId}/teams`, 'name', teamName, 'teams'),
            mandantId,
        );

        // The new mandant shows up in the list.
        await page.getByRole('complementary').getByRole('link', { name: 'Mandanten' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);
        await expect(page.getByRole('main').getByRole('row', { name: new RegExp(uniqueName) })).toBeVisible();
    });

    test('self-service logo upload', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        // The portal spec asserts that the PRIMARY mandant has no logo (it must
        // fall back to the static asset), so the upload window below is global
        // state on a row another spec reads. Take turns instead of racing
        // (WP-9-D4).
        const releaseLogoLock = await acquirePrimaryMandantLogoLock();
        try {
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
        } finally {
            releaseLogoLock();
        }
    });

    test('mandant list shows logo column and portal link', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
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
            // Registered before the domain POST — see the note in
            // `admin-mandant-switch.spec.ts` for why the order is the guarantee.
            rememberOwnedRow('mandants', mandant.id);
            const domain = await api.post(`/api/admin/mandants/${mandant.id}/domains`, {
                data: { hostname: domainHostname },
            });
            expect(domain.status()).toBe(201);
            rememberOwnedRow('mandantDomains', (await domain.json()).data.id, mandant.id);
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

        // The domain hostname is rendered as an external portal link. Mandant
        // sites are TLS-only (Caddy terminates 443 per mandant), so the link
        // must NOT downgrade to plain HTTP.
        const portalLink = adminMain.getByRole('link', { name: new RegExp(domainHostname) });
        await expect(portalLink).toBeVisible();
        await expect(portalLink).toHaveAttribute('href', `https://${domainHostname}`);
        await expect(portalLink).toHaveAttribute('target', '_blank');
        await expect(portalLink).toHaveAttribute('rel', 'noreferrer');
    });
});
