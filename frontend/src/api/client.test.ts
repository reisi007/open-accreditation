import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ApiError,
    addDomain,
    deleteMandant,
    getMe,
    listMandants,
    setUnauthorizedHandler,
    uploadLogo,
} from './client';
import type { Mandant } from './types';

function stubFetch(responseBody: unknown, status = 200, headers?: Record<string, string>) {
    const fetchMock = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => {
        const body = responseBody === undefined ? null : JSON.stringify(responseBody);
        return new Response(body, {
            status,
            headers: { 'Content-Type': 'application/json', ...headers },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('api client', () => {
    it('unwraps the {data} envelope on success', async () => {
        const mandants = [{ id: 1, slug: 'main', name: 'Hauptseite' } as Mandant];
        stubFetch({ data: mandants });

        await expect(listMandants()).resolves.toEqual(mandants);
    });

    it('throws ApiError with message and field errors from {message, errors}', async () => {
        const body = {
            message: 'Der Slug ist bereits vergeben.',
            errors: { slug: ['Der Slug ist bereits vergeben.'] },
        };
        stubFetch(body, 422);

        const error = await listMandants().catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(422);
        expect(error.message).toBe('Der Slug ist bereits vergeben.');
        expect(error.info).toEqual(body);
    });

    it('falls back to a status message for non-JSON error bodies', async () => {
        const fetchMock = vi.fn(async () => new Response('<html>oops</html>', { status: 500 }));
        vi.stubGlobal('fetch', fetchMock);

        const error = await listMandants().catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(500);
        expect(error.message).toBe('HTTP 500');
    });

    it('resolves to undefined on 204 (DELETE)', async () => {
        stubFetch(undefined, 204);

        await expect(deleteMandant(7)).resolves.toBeUndefined();
    });

    it('sends JSON for POST bodies', async () => {
        const fetchMock = stubFetch({ data: { id: 9, hostname: 'example.test' } }, 201);

        await addDomain(7, 'example.test');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [, init] = fetchMock.mock.calls[0];
        expect(init?.method).toBe('POST');
        expect(new Headers(init?.headers).get('Content-Type')).toBe('application/json');
        expect(JSON.parse(String(init?.body))).toEqual({ hostname: 'example.test' });
    });

    it('uploads files via FormData with credentials', async () => {
        const fetchMock = stubFetch({ data: {} }, 200);
        const file = new File(['x'], 'logo.png', { type: 'image/png' });

        await uploadLogo(3, file);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/admin/mandants/3/logo');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('include');
        expect(init?.body).toBeInstanceOf(FormData);
        const formData = init?.body as FormData;
        expect(formData.get('file')).toBe(file);
    });

    it('triggers the unauthorized handler on 401 for admin endpoints', async () => {
        stubFetch({ message: 'Nicht angemeldet.' }, 401);
        const handler = vi.fn();
        setUnauthorizedHandler(handler);

        await listMandants().catch(() => undefined);

        expect(handler).toHaveBeenCalledTimes(1);
        setUnauthorizedHandler(null);
    });

    it('does NOT trigger the unauthorized handler on 401 for /api/auth/*', async () => {
        stubFetch({ message: 'Ungültige Zugangsdaten.' }, 401);
        const handler = vi.fn();
        setUnauthorizedHandler(handler);

        await getMe().catch(() => undefined);

        expect(handler).not.toHaveBeenCalled();
        setUnauthorizedHandler(null);
    });

    it('maps network failures to ApiError with status 0', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new Error('Failed to fetch');
        }));

        const error = await listMandants().catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(0);
        expect(error.message).toBe('Netzwerkfehler: Keine Verbindung zum Server.');
    });
});
