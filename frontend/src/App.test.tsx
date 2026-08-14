import { afterEach, describe, expect, it, vi } from 'vitest';
import userEvent from '@testing-library/user-event';
import { screen } from '@testing-library/react';
import App from './App';
import { renderWithProviders } from './test-setup';

const overviewPayload = {
    mandant: {
        id: 1,
        slug: 'main',
        name: 'Hauptseite',
        logo_url: null,
        header_url: null,
        impressum_text: null,
        privacy_text: null,
        teams_enabled: true,
    },
    teams: [{ id: 10, name: 'Heimverein', home_venue: 'Heimstadion' }],
};

function stubFetch() {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === '/api/portal/overview') {
            return new Response(JSON.stringify({ data: overviewPayload }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        if (url.startsWith('/api/portal/events')) {
            return new Response(JSON.stringify({ data: [] }), {
                status: 200,
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

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('App scaffold', () => {
    it('renders the header and the portal mandant heading', async () => {
        stubFetch();
        renderWithProviders(<App />);

        expect(screen.getByRole('link', { name: 'Akkreditierung' })).toBeInTheDocument();
        expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('Hauptseite');
    });

    it('switches the UI language to English', async () => {
        stubFetch();
        const user = userEvent.setup();
        renderWithProviders(<App />);

        await screen.findByRole('heading', { level: 1 });
        await user.selectOptions(screen.getByRole('combobox', { name: 'Sprache' }), 'en');

        expect(await screen.findByRole('heading', { level: 2, name: 'Event calendar' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'All teams' })).toBeInTheDocument();
    });
});
