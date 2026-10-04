import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ApiError,
    addDomain,
    deleteMandant,
    deleteMyHeader,
    deleteMyLogo,
    getMe,
    listFailedMails,
    listMandants,
    requeueFailedMail,
    resendApplicationMail,
    resendSubApplicationMail,
    setUnauthorizedHandler,
    uploadLogo,
    uploadMyHeader,
    uploadMyLogo,
} from './client';
import type { FailedMail, Mandant } from './types';

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

    it('uploads the self-service mandant logo via FormData', async () => {
        const fetchMock = stubFetch({ data: {} }, 200);
        const file = new File(['x'], 'logo.png', { type: 'image/png' });

        await uploadMyLogo(file);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/mandant/logo');
        expect(init?.method).toBe('POST');
        expect(init?.credentials).toBe('include');
        expect(init?.body).toBeInstanceOf(FormData);
        const formData = init?.body as FormData;
        expect(formData.get('file')).toBe(file);
    });

    it('deletes the self-service mandant logo', async () => {
        const fetchMock = stubFetch(undefined, 204);

        await deleteMyLogo();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/mandant/logo');
        expect(init?.method).toBe('DELETE');
        expect(init?.credentials).toBe('include');
    });

    it('uploads and deletes the self-service mandant header', async () => {
        const uploadMock = stubFetch({ data: {} }, 200);
        const file = new File(['h'], 'header.png', { type: 'image/png' });

        await uploadMyHeader(file);

        const [uploadUrl, uploadInit] = uploadMock.mock.calls[0];
        expect(uploadUrl).toBe('/api/mandant/header');
        expect(uploadInit?.method).toBe('POST');
        const formData = uploadInit?.body as FormData;
        expect(formData.get('file')).toBe(file);

        const deleteMock = stubFetch(undefined, 204);
        await deleteMyHeader();

        const [deleteUrl, deleteInit] = deleteMock.mock.calls[0];
        expect(deleteUrl).toBe('/api/mandant/header');
        expect(deleteInit?.method).toBe('DELETE');
        expect(deleteInit?.credentials).toBe('include');
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

/**
 * The dead-letter surface (`FailedMailController`) and the two "I ordered a
 * delivery" endpoints, which answer a BARE `{message}`.
 */
describe('api client — dead letters', () => {
    it('unwraps the DLQ list from its {data} envelope', async () => {
        const rows: FailedMail[] = [
            {
                id: 11,
                mandant_id: 1,
                mailable: 'App\\Mail\\PassMail',
                recipient: 'anna@example.test',
                queue: 'default',
                exception: 'Connection could not be established',
                failed_at: '2026-10-02T09:30:00+00:00',
            },
        ];
        const fetchMock = stubFetch({ data: rows });

        await expect(listFailedMails()).resolves.toEqual(rows);
        // No query string: the endpoint takes no parameters, so a filter here
        // would be silently ignored by the server.
        expect(fetchMock.mock.calls[0][0]).toBe('/api/admin/failed-mails');
    });

    it('accepts an empty DLQ list as an empty array, not as undefined', async () => {
        // `{data: []}` must survive the unwrap. A `?? []` at the call site would
        // hide the difference between "no dead letters" and "the field was
        // missing", and the page renders those as two different states.
        stubFetch({ data: [] });

        await expect(listFailedMails()).resolves.toEqual([]);
    });

    it('returns the SERVER message of a requeue, not void', async () => {
        // MEASURED 2026-10-02: `FailedMailController::requeue` answers
        // `{"message": "E-Mail wurde erneut in die Warteschlange gestellt."}`.
        const fetchMock = stubFetch({ message: 'E-Mail wurde erneut in die Warteschlange gestellt.' });

        await expect(requeueFailedMail(11)).resolves.toBe('E-Mail wurde erneut in die Warteschlange gestellt.');
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/admin/failed-mails/11/requeue');
        expect(init?.method).toBe('POST');
    });

    it('raises the requeue 404 (foreign or gone letter) as an ApiError', async () => {
        // The controller answers 404 for a FOREIGN letter, not 403 — the same
        // shape as the tenant CRUD. Swallowing it would render "you may not"
        // where the truth is "not yours to see", which is the leak this stream
        // must not create in the first place.
        stubFetch({ message: 'No query results for model [App\\Models\\FailedJob] 999.' }, 404);

        const error = await requeueFailedMail(999).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(404);
    });

    it('raises the requeue 403 (role without mails.dlq.manage) as an ApiError', async () => {
        stubFetch({ message: 'This action is unauthorized.' }, 403);

        const error = await requeueFailedMail(11).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(403);
    });

    it('yields an empty string for a requeue body without a message', async () => {
        // The fallback path of `serverActionMessage`: it must be reachable, and
        // it must not be a crash.
        stubFetch({ data: null }, 200);

        await expect(requeueFailedMail(11)).resolves.toBe('');
    });

    it('yields an empty string for a non-JSON requeue answer', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('OK', { status: 200, headers: { 'Content-Type': 'text/plain' } })),
        );

        await expect(requeueFailedMail(11)).resolves.toBe('');
    });

    it('returns the SERVER message of the application resend', async () => {
        // Same contract, same reason: `AdminApplicationController::resend`
        // answers a bare `{message}` and only ORDERS the delivery.
        const fetchMock = stubFetch({ message: 'E-Mail wurde erneut in die Warteschlange gestellt.' });

        await expect(resendApplicationMail(42)).resolves.toBe(
            'E-Mail wurde erneut in die Warteschlange gestellt.',
        );
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/admin/applications/42/resend');
        expect(init?.method).toBe('POST');
    });

    it('still raises the resend 422 (no mailable status) as an ApiError', async () => {
        stubFetch({ message: 'Application has no mailable status.' }, 422);

        const error = await resendApplicationMail(42).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(422);
    });

    it('returns the SERVER message of the sub-application resend', async () => {
        // MEASURED on the running backend:
        // `AdminSubApplicationController::resend` answers the same bare
        // `{message}` as its main-application counterpart, and the UI must show
        // THAT string — a translated string of our own would be a claim about a
        // relay this process never talked to (see `logic/serverActionMessage.ts`).
        const fetchMock = stubFetch({ message: 'E-Mail wurde erneut in die Warteschlange gestellt.' });

        await expect(resendSubApplicationMail(7)).resolves.toBe('E-Mail wurde erneut in die Warteschlange gestellt.');
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/admin/sub-applications/7/resend');
        expect(init?.method).toBe('POST');
    });

    it('raises the sub-application resend 422 (requested / no mailable reason) as an ApiError', async () => {
        stubFetch({ message: 'Sub-application has no mailable status.' }, 422);

        const error = await resendSubApplicationMail(7).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(422);
        expect(error.message).toBe('Sub-application has no mailable status.');
    });

    it('raises the sub-application resend 403 (team_admin on a foreign team) as an ApiError', async () => {
        stubFetch({ message: 'This action is unauthorized.' }, 403);

        const error = await resendSubApplicationMail(7).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(403);
    });

    it('raises the sub-application resend 404 (foreign mandant) as an ApiError', async () => {
        // A foreign mandant is a 404 and NOT a 403 — the controller scopes the
        // route binding to the current mandant before anything else. Swallowing
        // it would render "you may not" where the truth is "not yours to see".
        stubFetch({ message: 'No query results for model [App\\Models\\SubApplication] 999.' }, 404);

        const error = await resendSubApplicationMail(999).catch((err: unknown) => err);
        expect(error).toBeInstanceOf(ApiError);
        if (!(error instanceof ApiError)) return;
        expect(error.status).toBe(404);
        expect(error.message).toBe('No query results for model [App\\Models\\SubApplication] 999.');
    });

    it('yields an empty string for a sub-application resend body without a message', async () => {
        // The fallback path of `serverActionMessage`: a 2xx whose body carries no
        // `message` must be reachable, and it must not be a crash.
        stubFetch({ data: null }, 200);

        await expect(resendSubApplicationMail(7)).resolves.toBe('');
    });
});
