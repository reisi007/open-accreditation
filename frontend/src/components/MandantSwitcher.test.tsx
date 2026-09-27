/**
 * @vitest-environment jsdom
 * @vitest-environment-options {"url": "https://www.hauptseite.test:8443/admin/mandants?tab=domains"}
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { i18n } from '@lingui/core';
import { MemoryRouter } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MandantListPage } from '../pages/admin/MandantListPage';
import { renderWithProviders } from '../test-setup';
import { MandantSwitcher } from './MandantSwitcher';

const ME_URL = '/api/auth/me';
const MANDANTS_URL = '/api/admin/mandants';

/**
 * The current origin, spelled out so the tests assert the contract, not the
 * fixture. It is `www.hauptseite.test` — the mandant's SECOND domain, on
 * purpose: the identity in the trigger comes from `current_mandant_id`, so a
 * fixture whose origin IS the first domain would pass even if the name were
 * derived from the host.
 */
const ORIGIN_HOSTNAME = 'www.hauptseite.test';
const CURRENT_MANDANT_ID = 40;

const SUPER_ADMIN = {
    id: 1,
    name: 'Root',
    email: 'admin@example.com',
    current_mandant_id: CURRENT_MANDANT_ID,
    roles: [{ slug: 'super_admin', name: 'Super-Admin', mandant_id: null, team_id: null }],
};

const MANDANT_ADMIN = {
    ...SUPER_ADMIN,
    id: 2,
    email: 'verband@example.com',
    roles: [{ slug: 'mandant_admin', name: 'Mandanten-Admin', mandant_id: CURRENT_MANDANT_ID, team_id: null }],
};

/**
 * The list in the order the API returns it (alphabetical by name). THREE rows are
 * not links — an inactive mandant, a domainless one and the current mandant — so
 * "the first focusable row" is never the first `<li>`, which is what makes the
 * keyboard assertions below measure something.
 *
 * `Bundesliga`'s domains are deliberately stored out of order, so a test that
 * expects the lower id to win measures the sort (E3) instead of the array order.
 *
 * `Hauptseite` is the current mandant AND owns the origin host — as its SECOND
 * domain. A host-derived identity would have to pick the mandant by matching the
 * origin against a domain, and matching it against the FIRST domain (what the
 * switcher navigates to) finds nothing here, so the trigger-label assertions
 * below measure the real source: `current_mandant_id` from `/me`.
 */
function mandantPayload() {
    return [
        { id: 10, slug: 'alt', name: 'Alt Verband', is_active: false, is_primary: false, domains: [{ id: 11, hostname: 'alt.test' }] },
        {
            id: 20,
            slug: 'bundesliga',
            name: 'Bundesliga',
            is_active: true,
            is_primary: false,
            // Out of order on purpose: id 4 is the target, id 9 is display-only.
            domains: [
                { id: 9, hostname: 'www.bundesliga.test' },
                { id: 4, hostname: 'bundesliga.test' },
            ],
        },
        { id: 30, slug: 'handball', name: 'Handball', is_active: true, is_primary: false, domains: [{ id: 5, hostname: 'handball.test' }] },
        {
            id: 40,
            slug: 'hauptseite',
            name: 'Hauptseite',
            is_active: true,
            is_primary: true,
            domains: [
                { id: 1, hostname: 'hauptseite.test' },
                { id: 2, hostname: 'www.hauptseite.test' },
            ],
        },
        { id: 50, slug: 'pokal', name: 'Pokal International', is_active: true, is_primary: false, domains: [] },
    ];
}

/**
 * The real `fetch` is stubbed instead of the api client module, so the assertions
 * cover the PATH the request goes to — the two facts the switcher's contract rests
 * on: one shared list request, and none at all for a role that may not read it.
 */
function stubFetch(me: unknown, mandants: unknown = mandantPayload(), mandantsStatus = 200) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === ME_URL) {
            return new Response(JSON.stringify({ data: me }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        if (url === MANDANTS_URL) {
            return new Response(JSON.stringify({ data: mandants }), {
                status: mandantsStatus,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response(JSON.stringify({ message: 'not found' }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

function renderSwitcher() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MandantSwitcher />
        </SWRConfig>,
    );
}

/** Async on purpose: the trigger only exists once `/me` has answered. */
async function trigger(): Promise<HTMLElement> {
    return screen.findByRole('button', { name: /^Verband: / });
}

async function openPanel(): Promise<HTMLElement> {
    return screen.findByRole('list', { name: 'Verband wechseln' });
}

/** The `<li>` a rendered text belongs to — the panel row. */
function rowOf(element: HTMLElement): HTMLLIElement {
    const row = element.closest('li');
    if (row === null) {
        throw new Error('element is not inside a panel row');
    }
    return row;
}

/** The element daisyUI's menu rules style: the direct child of the `<li>`. */
function rowTarget(row: HTMLLIElement): Element {
    const target = row.firstElementChild;
    if (target === null) {
        throw new Error('panel row has no element child');
    }
    return target;
}

/**
 * daisyUI's OWN selector for "this menu row carries the hover affordance", read
 * from the shipped stylesheet rather than retyped here — a transcription would
 * keep asserting a rule that daisyUI may have changed.
 *
 * jsdom cannot resolve the COMPUTED style of these rules: it fails to parse
 * daisyUI's stylesheet at all (measured, not assumed — `Could not parse CSS
 * stylesheet`, the injected sheet contributes zero rules), so no unit test in
 * this repo can assert `cursor: pointer` here. What jsdom CAN do is answer the
 * question the stylesheet asks: while an element is hovered, does the hover rule
 * match it? nwsapi evaluates that selector faithfully, `:hover` state included.
 * The resulting appearance (cursor, background, box-shadow unchanged between
 * rest and hover) was measured in Chromium against the built CSS; this test
 * pins the precondition of that appearance in both directions, so a future
 * change — ours or daisyUI's — cannot silently take the affordance away from
 * the rows that keep it or give it back to the rows that must not have it.
 */
function daisyUiMenuHoverSelector(): string {
    const require = createRequire(import.meta.url);
    const css = readFileSync(require.resolve('daisyui/components/menu.css'), 'utf8');
    // The unprefixed rule (the responsive variants are `.sm\:menu`, `.md\:menu`, …).
    const selector = [...css.matchAll(/([^{}]*?):hover(?=[^{}]*\{)/g)]
        .map((match) => `${match[1].trim()}:hover`)
        .find((candidate) => candidate.startsWith('.menu ') && !candidate.includes('\\:'));
    if (selector === undefined) {
        throw new Error('daisyUIs menu.css contains no .menu …:hover rule any more');
    }
    return selector;
}

afterEach(() => {
    vi.unstubAllGlobals();
    i18n.activate('de');
});

describe('MandantSwitcher — visibility (E1)', () => {
    it('renders no trigger for a mandant_admin and never asks for the mandant list', async () => {
        const fetchMock = stubFetch(MANDANT_ADMIN);
        renderSwitcher();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(ME_URL, expect.anything()));
        expect(screen.queryByRole('button', { name: /^Verband: / })).not.toBeInTheDocument();
        // `GET /api/admin/mandants` is behind `can:mandants.manage`, so a
        // mandant_admin could only ever get a 403 — the request is not made at all.
        expect(fetchMock.mock.calls.some(([url]) => url === MANDANTS_URL)).toBe(false);
    });

    it('renders the trigger for a super_admin, named with the current mandant and the current host', async () => {
        stubFetch(SUPER_ADMIN);
        renderSwitcher();

        const button = await screen.findByRole('button', { name: /^Verband: / });
        // The host is DISPLAY, the identity comes from `current_mandant_id`. The
        // fixture makes that observable: the origin is the mandant's SECOND
        // domain, so a name derived from the host — or looked up by matching the
        // origin against the FIRST domain, the one the switcher navigates to —
        // would not be "Hauptseite" here. Nor may the name be dropped for a host
        // that is not the first one (the host-only fallback would be the bare
        // `www.hauptseite.test`).
        expect(window.location.hostname).toBe(ORIGIN_HOSTNAME);
        expect(button).toHaveAttribute('aria-label', `Verband: Hauptseite (${ORIGIN_HOSTNAME})`);
        expect(button).toHaveTextContent(`Hauptseite · ${ORIGIN_HOSTNAME}`);
        expect(button).toHaveAttribute('aria-expanded', 'false');
    });
});

describe('MandantSwitcher — the rows (E3, E4, E5, E8)', () => {
    it('offers a domainless and an inactive mandant as rows WITHOUT a link, each with its own marker', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();

        // E4: no domain → there is no URL to navigate to, so the row carries no
        // `href` at all and says why.
        const domainless = rowOf(within(open).getByText('Pokal International'));
        expect(within(domainless).queryByRole('link')).not.toBeInTheDocument();
        expect(within(domainless).getByText('keine Domain')).toBeInTheDocument();
        expect(within(domainless).queryByText('inaktiv')).not.toBeInTheDocument();

        // E5 separately: an inactive mandant is badged and never offered — and it
        // keeps its hostname visible, so the admin can see WHAT is deactivated.
        const inactive = rowOf(within(open).getByText('Alt Verband'));
        expect(within(inactive).queryByRole('link')).not.toBeInTheDocument();
        expect(within(inactive).getByText('inaktiv')).toBeInTheDocument();
        expect(inactive).toHaveTextContent('alt.test');

        // An active mandant with a domain is a real link (E8) and shows EVERY one
        // of its domains, comma separated — the row is per mandant, not per host.
        const reachable = rowOf(within(open).getByText('Bundesliga'));
        expect(within(reachable).getByRole('link')).toBeInTheDocument();
        expect(reachable).toHaveTextContent('bundesliga.test, www.bundesliga.test');
    });

    it('marks ONLY the current mandant as aria-current and gives it no href', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();

        const current = rowOf(within(open).getByText('Hauptseite'));
        expect(within(current).queryByRole('link')).not.toBeInTheDocument();
        expect(within(current).getByText('Hauptseite').closest('[aria-current]')).toHaveAttribute('aria-current', 'true');

        // "Du bist hier" is `aria-current`, exactly once — and never
        // `aria-selected`, because this widget is not a listbox at all.
        expect(open.querySelectorAll('[aria-current="true"]')).toHaveLength(1);
        expect(within(open).queryByRole('listbox')).not.toBeInTheDocument();
        expect(within(open).queryByRole('option')).not.toBeInTheDocument();

        // Every OTHER non-navigable row says so explicitly — `aria-current="false"`
        // is the honest "this is not where you are" and keeps daisyUI's ACTIVE
        // styling off the row. It is NOT what keeps the hover rule off (that is
        // measured in the next test). A reachable row carries no `aria-current` at
        // all: a link to another origin is not a "current item" of anything.
        expect(rowOf(within(open).getByText('Alt Verband')).querySelector('[aria-current="false"]')).not.toBeNull();
        expect(within(rowOf(within(open).getByText('Bundesliga'))).getByRole('link')).not.toHaveAttribute('aria-current');
    });

    it('carries the hover affordance ONLY on the rows that can actually be clicked', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();
        const hoverTarget = daisyUiMenuHoverSelector();

        // Direction one: the reachable row IS a target of daisyUI's hover rule, so
        // the affordance (pointer cursor, tinted background) survives where it
        // belongs. This is also what keeps the test from passing vacuously: a
        // broken extraction would make every assertion below trivially true.
        const reachable = within(rowOf(within(open).getByText('Bundesliga'))).getByRole('link');
        await user.hover(reachable);
        expect(reachable.matches(hoverTarget)).toBe(true);

        // Direction two: no row that cannot be navigated to may be one. All three
        // kinds, because each is dead for a different reason — no domain (E4),
        // inactive (E5) and the current mandant — and each is a direct `li` child,
        // which is what the rule targets. `aria-current="false"` does not exclude
        // a row from it (measured in Chromium: `cursor: auto → pointer`), which is
        // why the exclusion is asserted on the element, not on an attribute.
        for (const name of ['Pokal International', 'Alt Verband', 'Hauptseite']) {
            const target = rowTarget(rowOf(within(open).getByText(name)));
            await user.hover(target);
            expect(target.matches(hoverTarget), `${name} must not look clickable`).toBe(false);
        }
    });

    it('closes the panel when the current row is clicked, without navigating', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();
        const current = rowOf(within(open).getByText('Hauptseite'));

        await user.click(within(current).getByText('Hauptseite'));

        expect(screen.queryByRole('list', { name: 'Verband wechseln' })).not.toBeInTheDocument();
        // No navigation happened: the host cannot change under a span.
        expect(window.location.hostname).toBe(ORIGIN_HOSTNAME);
    });
});

describe('MandantSwitcher — the target URL (E7, E9)', () => {
    it('builds protocol//hostname + the current path and search, and never a port', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();

        // Exactly two reachable rows: the inactive, the domainless and the current
        // one are spans. The count also proves nothing was over-offered.
        expect(within(open).getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
            // The scheme comes from the RUNNING origin (E9), the path and query
            // are carried over unchanged (E7), and the target is the FIRST domain
            // by id — `bundesliga.test` (id 4), not the `www.` one stored first.
            `${window.location.protocol}//bundesliga.test${window.location.pathname}${window.location.search}`,
            `${window.location.protocol}//handball.test${window.location.pathname}${window.location.search}`,
        ]);

        // The fixture origin runs on 8443: an href carrying that port would be
        // visible here, and it must not be there.
        expect(window.location.port).toBe('8443');
        for (const link of within(open).getAllByRole('link')) {
            expect(link.getAttribute('href')).not.toContain('8443');
        }
    });
});

describe('MandantSwitcher — the disclosure and the keyboard', () => {
    it('opens on Enter and lands on the first LINK, skipping the rows without one', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        const button = await screen.findByRole('button', { name: /^Verband: / });
        // Collapsed: no panel in the DOM, so no dangling `aria-controls`.
        expect(button).not.toHaveAttribute('aria-controls');
        expect(screen.queryByRole('list', { name: 'Verband wechseln' })).not.toBeInTheDocument();

        button.focus();
        await user.keyboard('{Enter}');

        expect(button).toHaveAttribute('aria-expanded', 'true');
        expect(button).toHaveAttribute('aria-controls', 'mandant-switcher-panel');
        await waitFor(() => expect(document.activeElement).toHaveTextContent('Bundesliga'));
    });

    it('steps the focus with the arrows and the ends, without wrapping', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        await user.click(await trigger());
        const open = await openPanel();
        const [first, second] = within(open).getAllByRole('link');
        // Opening already put the focus on the first LINK, not on the first row.
        await waitFor(() => expect(document.activeElement).toBe(first));

        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(second);
        // The last row is the end: no wrap-around back to the first.
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(second);
        await user.keyboard('{ArrowUp}');
        expect(document.activeElement).toBe(first);
        await user.keyboard('{ArrowUp}');
        expect(document.activeElement).toBe(first);
        await user.keyboard('{End}');
        expect(document.activeElement).toBe(second);
        await user.keyboard('{Home}');
        expect(document.activeElement).toBe(first);
    });

    it('closes on Escape and hands the focus back to the trigger', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        const button = await screen.findByRole('button', { name: /^Verband: / });
        await user.click(button);
        await openPanel();

        await user.keyboard('{Escape}');

        expect(screen.queryByRole('list', { name: 'Verband wechseln' })).not.toBeInTheDocument();
        expect(button).toHaveAttribute('aria-expanded', 'false');
        expect(document.activeElement).toBe(button);
    });

    it('closes on a click outside the panel', async () => {
        stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderSwitcher();

        const button = await screen.findByRole('button', { name: /^Verband: / });
        await user.click(button);
        await openPanel();

        // A click on non-focusable page background moves no focus at all, so a
        // blur-based close would leave the panel open over the content.
        await user.click(document.body);

        expect(screen.queryByRole('list', { name: 'Verband wechseln' })).not.toBeInTheDocument();
    });
});

describe('MandantSwitcher — a failed list', () => {
    it('says so in the panel, in the ACTIVE locale, instead of showing an empty list', async () => {
        i18n.activate('en');
        stubFetch(SUPER_ADMIN, [], 500);
        const user = userEvent.setup();
        renderSwitcher();

        // The trigger is found by ROLE, not by name: with the list unavailable
        // there is no mandant to name, and the trigger falls back to the bare
        // hostname — the one part of its label that must never be lost.
        await user.click(await screen.findByRole('button'));
        expect(screen.getByRole('button')).toHaveAttribute('aria-label', window.location.hostname);

        const open = await screen.findByRole('list', { name: 'Switch association' });
        // A switcher that renders "no associations" when the list failed is
        // lying about the one thing it exists to answer.
        expect(within(open).getByText('The mandants could not be loaded.')).toBeInTheDocument();
        expect(within(open).queryAllByRole('link')).toHaveLength(0);
    });
});

/**
 * The invariant `useMandants`' own test cannot see: that test proves two
 * consumers of ONE key share a request, which says nothing about whether the two
 * SURFACES use that key at all. Re-introducing a literal in `MandantListPage`
 * would leave the hook's test green and split the cache in two — the page would
 * show one set of associations and the header's switcher another, and a switcher
 * navigating from a stale list sends the admin to a domain that is not the
 * mandant he picked. So the two components are mounted together and the request
 * count is the assertion.
 */
describe('MandantListPage + MandantSwitcher — one mandant cache entry', () => {
    it('answers both surfaces from a single GET /api/admin/mandants', async () => {
        const fetchMock = stubFetch(SUPER_ADMIN);
        const user = userEvent.setup();
        renderWithProviders(
            <MemoryRouter>
                <SWRConfig value={{ provider: () => new Map() }}>
                    <MandantListPage />
                    <MandantSwitcher />
                </SWRConfig>
            </MemoryRouter>,
        );

        // The page has the list …
        await screen.findByRole('link', { name: 'Bundesliga' });

        // … so opening the switcher on this very page costs no second request, and
        // the switcher renders the same mandants the page already shows.
        await user.click(await trigger());
        const open = await openPanel();
        expect(within(open).getByText('Bundesliga')).toBeInTheDocument();
        expect(fetchMock.mock.calls.filter(([url]) => url === MANDANTS_URL)).toHaveLength(1);
    });
});
