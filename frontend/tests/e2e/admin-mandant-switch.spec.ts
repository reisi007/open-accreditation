import { expect, test } from '@playwright/test';
import { loginAdminApi, uniqueSuffix } from './helpers/admin-data';

/**
 * Domain switcher for the super_admin (features/01-multi-tenancy.md, D21).
 *
 * The widget is a plain disclosure over a list of `<a href>` rows: every target
 * is a FOREIGN origin, so a click is a top-level navigation that the server
 * resolves through the Host header again. Nothing about the switch is persisted
 * — the host is the only truth.
 *
 * Plain ES2020 on purpose: `tests/e2e` is linted with the non-TS parser but built
 * by the strict `tsc -b`, so a type annotation is an ESLint parse error and an
 * un-annotated parameter an implicit `any` (see `helpers/admin-data.ts`). The
 * helpers below therefore take no parameters, and collections are `Map`/`Set`
 * rather than bare arrays for the same reason.
 */

/** Filled by `createSwitcherFixtures`, read by the panel assertions. */
let fixture = { inactiveName: '', inactiveHost: '', domainlessName: '' };

/**
 * The two states the switcher has to render WITHOUT a link, each isolated so
 * that an assertion measures one decision:
 *
 *  - INACTIVE **with** a domain, otherwise "not clickable" could be the missing
 *    domain instead of the deactivation.
 *  - ACTIVE **without** a domain — a mandant is created without one by the
 *    ordinary form, so this is a reachable state, not an exotic one.
 *
 * Both names start with `E2E `, the marker `purgeAllE2EArtifacts` reclaims after
 * the run, and each run creates its own pair, so no cross-run lookup (and no
 * leftover a crashed run would keep alive) is needed.
 */
async function createSwitcherFixtures() {
    const suffix = uniqueSuffix();
    const inactiveName = `E2E Switcher Inaktiv ${suffix}`;
    const inactiveSlug = `e2e-switcher-inaktiv-${suffix}`;
    const domainlessName = `E2E Switcher Ohne Domain ${suffix}`;
    const domainlessSlug = `e2e-switcher-ohne-domain-${suffix}`;

    const api = await loginAdminApi();
    try {
        const inactive = await api.post('/api/admin/mandants', {
            data: { name: inactiveName, slug: inactiveSlug, is_active: false },
        });
        if (inactive.status() !== 201) {
            throw new Error(`Creating the inactive switcher mandant failed with status ${inactive.status()}`);
        }
        const inactiveId = (await inactive.json()).data.id;
        const domain = await api.post(`/api/admin/mandants/${inactiveId}/domains`, {
            data: { hostname: `${inactiveSlug}.test` },
        });
        if (domain.status() !== 201) {
            throw new Error(`Adding the domain failed with status ${domain.status()}`);
        }

        const domainless = await api.post('/api/admin/mandants', { data: { name: domainlessName, slug: domainlessSlug } });
        if (domainless.status() !== 201) {
            throw new Error(`Creating the domainless switcher mandant failed with status ${domainless.status()}`);
        }

        fixture = { inactiveName, inactiveHost: `${inactiveSlug}.test`, domainlessName };

        // The mandant the CURRENT host resolves to, and the full list, read over
        // the same session. `current_mandant_id` is host-derived, so this session
        // and the browser session necessarily agree on it.
        const me = (await (await api.get('/api/auth/me')).json()).data;
        const mandants = (await (await api.get('/api/admin/mandants')).json()).data;
        for (const mandant of mandants) {
            if (mandant.id === me.current_mandant_id) {
                return { currentMandant: mandant, mandants };
            }
        }

        throw new Error('The current host resolved to no mandant');
    } finally {
        await api.dispose();
    }
}

test.describe('Admin: Domainwechsel-Dropdown (D21)', () => {
    // UI-heavy spec: run once (Desktop Chrome) — the shared per-IP login
    // throttle (15/min) budget must stay available for the parallel feature
    // specs, exactly as in admin-mandant.spec.ts / a11y.spec.ts.
    test.beforeEach(async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);
    });

    test('panel lists every mandant with its domain and offers only the reachable ones as links', { tag: ['@regression', '@feature:admin:mandant'] }, async ({ page }) => {
        const { currentMandant, mandants } = await createSwitcherFixtures();

        // The switcher reads the mandant list through the SHARED SWR entry that
        // the login render already fetched, which is exactly why opening it costs
        // no second request — and why rows created afterwards are invisible to
        // it. A reload (not a `goto`; the session cookie carries over) is what
        // puts the fixtures into that first fetch.
        await page.reload();
        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // The switcher lives in the header, so it is scoped to the `banner`
        // landmark — never to a CSS class.
        const banner = page.getByRole('banner');
        const trigger = banner.getByRole('button', { name: /^Verband: / });
        await expect(trigger).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await trigger.click();

        const panel = banner.getByRole('list', { name: 'Verband wechseln' });
        await expect(panel).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');

        // Every mandant the API knows is a row, and one with domains shows them.
        // ONE row per MANDANT, not per hostname: the seeded primary carries three
        // domains and must not show up three times. Counted per NAME, because the
        // dev database really does hold two same-named mandants — one row for
        // EACH of them is the contract, and a substring filter could not tell
        // them apart.
        const rowsPerName = new Map();
        for (const mandant of mandants) {
            rowsPerName.set(mandant.name, (rowsPerName.get(mandant.name) ?? 0) + 1);
        }
        for (const [name, count] of rowsPerName) {
            const row = panel.getByRole('listitem').filter({ has: page.getByText(name, { exact: true }) });
            await expect(row).toHaveCount(count);
        }

        // Every hostname of every mandant stays visible: the row shows ALL of
        // them, it just navigates to the first.
        const panelText = await panel.innerText();
        for (const mandant of mandants) {
            for (const domain of mandant.domains) {
                expect(panelText).toContain(domain.hostname);
            }
        }

        // The mandant this host resolved to is marked, and carries no link:
        // there is nothing to switch to. Located by the MARKER, not by name.
        const currentRow = panel.locator('[aria-current="true"]');
        await expect(currentRow).toHaveCount(1);
        await expect(currentRow).toContainText(currentMandant.name);
        await expect(currentRow.getByRole('link')).toHaveCount(0);

        // E5: inactive → badged, never offered. It keeps its hostname visible.
        const inactiveRow = panel.getByRole('listitem').filter({ has: page.getByText(fixture.inactiveName, { exact: true }) });
        await expect(inactiveRow.getByText('inaktiv', { exact: true })).toBeVisible();
        await expect(inactiveRow).toContainText(fixture.inactiveHost);
        await expect(inactiveRow.getByRole('link')).toHaveCount(0);

        // E4: no domain → no URL to navigate to, so no link and a plain hint.
        const domainlessRow = panel.getByRole('listitem').filter({ has: page.getByText(fixture.domainlessName, { exact: true }) });
        await expect(domainlessRow.getByText('keine Domain', { exact: true })).toBeVisible();
        await expect(domainlessRow.getByRole('link')).toHaveCount(0);
    });

    test('every target URL takes the running scheme, the current path and no port', { tag: ['@regression', '@feature:admin:mandant'] }, async ({ page }) => {
        // Navigate to a DIFFERENT admin route through the UI (no page.goto), so
        // the hrefs have a path that could be dropped or hard-coded.
        await page.getByRole('complementary').getByRole('link', { name: 'Kategorien' }).click();
        await expect(page).toHaveURL(/\/admin\/categories$/);

        await page.getByRole('banner').getByRole('button', { name: /^Verband: / }).click();
        const panel = page.getByRole('banner').getByRole('list', { name: 'Verband wechseln' });

        const hrefs = await panel.getByRole('link').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href')));
        expect(hrefs.length).toBeGreaterThan(0);

        const origin = new URL(page.url());
        for (const href of hrefs) {
            if (href === null) {
                throw new Error('a switcher row carried no href');
            }
            const target = new URL(href);
            // E9: the scheme of the RUNNING origin — no scheme is stored per mandant.
            expect(target.protocol).toBe(origin.protocol);
            // E7: the path is carried over unchanged, also on a route that is not
            // the mandant CRUD.
            expect(`${target.pathname}${target.search}`).toBe('/admin/categories');
            // E7: never a port. The dev origin runs on one, a mandant domain has none.
            expect(target.port).toBe('');
            // And never the host we are already on: the current row has no href.
            expect(target.hostname).not.toBe(origin.hostname);
        }
    });

    test('a click leaves for the target origin, carrying the current admin path', { tag: ['@regression', '@feature:admin:mandant'] }, async ({ page }) => {
        await page.getByRole('banner').getByRole('button', { name: /^Verband: / }).click();
        const panel = page.getByRole('banner').getByRole('list', { name: 'Verband wechseln' });

        // Whatever the seeded data offers, the first link is a real destination.
        const row = panel.getByRole('link').first();
        const href = await row.getAttribute('href');
        if (href === null) {
            throw new Error('the first switcher row carried no href');
        }
        const targetHost = new URL(href).hostname;
        const path = new URL(href).pathname;

        // The target host (`bundesliga.test`) has no DNS entry in the E2E
        // container, so the navigation is intercepted BEFORE it is attempted and
        // answered with minimal HTML. Nothing leaves the machine, and the CLICK
        // is still measured — not just the href.
        const captured = new Map();
        await page.route(
            (url) => url.hostname === targetHost,
            async (route) => {
                captured.set(route.request().url(), true);
                await route.fulfill({
                    status: 200,
                    contentType: 'text/html',
                    body: '<!doctype html><html lang="de"><head><title>Zieldomain</title></head><body><h1>Zieldomain</h1></body></html>',
                });
            },
        );

        await row.click();

        // The navigation really happened — the app was left, not just requested.
        await expect(page.getByRole('heading', { name: 'Zieldomain' })).toBeVisible();
        expect(captured.size).toBe(1);
        const navigated = [...captured.keys()][0];
        expect(navigated).toBe(href);
        // And it carried the admin path we were on, not the root.
        expect(path).toBe('/admin/mandants');
        expect(new URL(navigated).pathname).toBe(path);
    });
});
