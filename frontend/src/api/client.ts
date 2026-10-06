export type { AccountDeletionResult, AccountSummary } from './types';

import { activeUiLocale } from '../logic/uiLocale';
import type {
    AccountDeletionResult,
    AccountSummary,
    Accreditation,
    AccreditationScope,
    AdminApplication,
    AdminMedia,
    AdminSubApplication,
    AdminUser,
    AllocationResult,
    Application,
    ApplicationAction,
    BadgeLayoutEntry,
    BadgeTemplate,
    Blacklist,
    Category,
    Event,
    FailedMail,
    Mandant,
    MandantDomain,
    PageMeta,
    Paginated,
    PortalEvent,
    PortalEventDetail,
    PortalOverview,
    SmtpConfig,
    SubAccreditation,
    SubApplication,
    SubType,
    Team,
    User,
    UserRoleAssignment,
    Venue,
    VerifyResult,
} from './types';

export interface ApiErrorInfo {
    message?: string;
    errors?: Record<string, string[]>;
}

export class ApiError extends Error {
    readonly status: number;
    readonly info: ApiErrorInfo;

    constructor(status: number, message: string, info: ApiErrorInfo) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.info = info;
    }
}

type UnauthorizedHandler = () => void;
let unauthorizedHandler: UnauthorizedHandler | null = null;

export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
    unauthorizedHandler = handler;
}

/**
 * The transport every JSON call shares: build the headers, fetch with the
 * session cookie, run the 401 handler, and turn a non-2xx into an `ApiError`
 * carrying the server's own `{message}` / `{errors}`.
 *
 * It is separate from the two parsers below because the two BODY SHAPES are the
 * reason they are separate: the resource endpoints answer `{data: …}` and are
 * unwrapped by `request`, while the "I have done what you asked" endpoints
 * (`…/resend`, `…/failed-mails/{id}/requeue`) answer a BARE `{message}` and are
 * read by `requestMessage`. Before this split both callers re-implemented the
 * transport, which is how a second copy of the 401 handler and the error mapping
 * would have drifted.
 *
 * `Accept-Language` is set HERE because the server answers `{message}` bodies in
 * the negotiated language — the resend/requeue surfaces show them verbatim, and
 * they used to be hardcoded German. One header on one place beats setting it at
 * the three call sites, where the next endpoint would forget it. The BINARY
 * transport sets it too, and `fetchBinary` says why it used not to.
 */
async function send(path: string, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers);
    if (!(init.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }
    headers.set('Accept', 'application/json');
    headers.set('Accept-Language', activeUiLocale());

    let response: Response;
    try {
        response = await fetch(path, { ...init, headers, credentials: 'include' });
    } catch {
        throw new ApiError(0, 'Netzwerkfehler: Keine Verbindung zum Server.', {});
    }

    if (response.status === 401 && !path.startsWith('/api/auth/')) {
        unauthorizedHandler?.();
    }

    if (!response.ok) {
        let info: ApiErrorInfo = {};
        const contentType = response.headers.get('content-type');
        if (contentType?.includes('application/json')) {
            try {
                info = (await response.json()) as ApiErrorInfo;
            } catch {
                // Non-JSON error body — keep the fallback message.
            }
        }
        const message =
            typeof info.message === 'string' && info.message !== '' ? info.message : `HTTP ${response.status}`;
        throw new ApiError(response.status, message, info);
    }

    return response;
}

/**
 * `request()` for a paginated resource: keeps `{data, meta}` INTACT.
 *
 * `request` returns `body.data` and would drop `meta` on the floor, so a
 * paginated endpoint called through it could only ever tell "the list ended" from
 * "the list ended" — which is the one distinction the page counter exists to
 * make. One line of unwrapping is the whole difference, so this is a wrapper
 * rather than a second transport: it calls `send()`, exactly like `request` and
 * `requestMessage` do, and inherits the cookie, the 401 handler and the
 * `ApiError` mapping from it.
 *
 * ## What happens to a response with no `meta`
 *
 * The rows are KEPT and the window is reconstructed from them — one page, sized
 * to what arrived. Discarding real rows because the envelope was malformed was
 * the alternative, and it is the worse failure here: on the dead-letter queue a
 * dropped row is an undelivered mail nobody can see, which is the exact defect
 * this page exists to prevent. Reconstructing instead means a server that
 * stopped paginating shows its letters under an honest "page 1 of 1" instead of
 * either vanishing or pretending to know a total it cannot know.
 */
async function requestPage<T>(path: string, init: RequestInit = {}): Promise<Paginated<T>> {
    const response = await send(path, init);

    const fromRows = (data: T[]): Paginated<T> => ({
        data,
        meta: { page: 1, per_page: data.length, total: data.length, last_page: 1 },
    });

    if (response.status === 204) {
        return fromRows([]);
    }

    const contentType = response.headers.get('content-type');
    if (contentType?.includes('application/json')) {
        const body = (await response.json()) as { data?: T[]; meta?: PageMeta };
        const data = body.data ?? [];

        return body.meta === undefined ? fromRows(data) : { data, meta: body.meta };
    }

    return fromRows([]);
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const response = await send(path, init);

    if (response.status === 204) {
        return undefined as T;
    }

    const contentType = response.headers.get('content-type');
    if (contentType?.includes('application/json')) {
        const body = (await response.json()) as { data?: T };
        return body.data as T;
    }

    return undefined as T;
}

/**
 * The `message` of a bare-`{message}` action endpoint, or `''` when the body
 * carries none.
 *
 * `request` cannot serve these endpoints: it unwraps `{data: …}`, and a body
 * without a `data` key would arrive as `undefined` — which is exactly the bug
 * this exists to end. Of the endpoints that reach THIS function, three answer
 * `{message: …}` at the TOP level — a claim about this function's callers, not
 * about the backend, where a top-level `'message' =>` is the norm rather than
 * the exception (36 in `backend/app/Http/Controllers`, and no endpoint other
 * than these three is routed through here):
 * `POST …/applications/{id}/resend` (`AdminApplicationController::resend`,
 * `:181`/`:196`), `POST …/sub-applications/{id}/resend`
 * (`AdminSubApplicationController::resend`, `:192`/`:207`) and
 * `POST …/failed-mails/{id}/requeue` (`FailedMailController::requeue`, `:99`).
 * Bare-ness is pinned at the ROOT by `assertJsonPath('message', …)` in
 * `MailTest.php:406` and `AdminSubApplicationResendTest.php:139`; the requeue
 * shape rests on its source line — `MailDeadLetterTest.php:221` pins the status,
 * not the body.
 *
 * That list is COMPLETE, and cannot quietly rot: this function is NOT exported,
 * so its only callers are the three wrappers below — `resendApplicationMail`,
 * `resendSubApplicationMail` and `requeueFailedMail` (grepped 2026-10-04; named
 * rather than line-numbered, because an in-file `:NN` goes stale on every edit
 * above it — which is how this list lost the sub-application resend once). A
 * fourth endpoint has to be added here in the same breath.
 *
 * The message is the SERVER'S account of what it did ("in die Warteschlange
 * gestellt"), which the UI has to show instead of a string of its own.
 */
async function requestMessage(path: string, init: RequestInit = {}): Promise<string> {
    const response = await send(path, init);

    if (response.status === 204) {
        return '';
    }

    const contentType = response.headers.get('content-type');
    if (!contentType?.includes('application/json')) {
        return '';
    }

    let body: unknown;
    try {
        body = await response.json();
    } catch {
        return '';
    }
    if (body === null || typeof body !== 'object') {
        return '';
    }

    const message = (body as { message?: unknown }).message;
    return typeof message === 'string' ? message : '';
}

export async function uploadFile(path: string, file: File, fieldName = 'file'): Promise<void> {
    const formData = new FormData();
    formData.append(fieldName, file);
    await request<void>(path, { method: 'POST', body: formData });
}

export const login = (email: string, password: string): Promise<void> =>
    request<void>('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
    });

export const logout = (): Promise<void> => request<void>('/api/auth/logout', { method: 'POST' });

export const getMe = (): Promise<User> => request<User>('/api/auth/me');

/**
 * Self-service account surface (`GET /api/user/account`): own identity plus
 * the counts the deletion confirmation has to name. No gate by design — the
 * target is `$request->user()`.
 */
export const getAccount = (): Promise<AccountSummary> => request<AccountSummary>('/api/user/account');

/**
 * Hard-delete the OWN account. The backend clears the JWT cookie on the way
 * out; revoking the access is Weg A (the account row is gone, so the next
 * request with that token 401s whatever the cookie does).
 */
export const deleteOwnAccount = (): Promise<AccountDeletionResult> =>
    request<AccountDeletionResult>('/api/user/account', { method: 'DELETE' });

export interface MandantPayload {
    name: string;
    slug: string;
    teams_enabled: boolean;
    is_active: boolean;
    impressum_text: string;
    privacy_text: string;
    smtp_config?: SmtpConfig | null;
}

export const listMandants = (): Promise<Mandant[]> => request<Mandant[]>('/api/admin/mandants');

export const createMandant = (payload: MandantPayload): Promise<Mandant> =>
    request<Mandant>('/api/admin/mandants', { method: 'POST', body: JSON.stringify(payload) });

export const getMandant = (id: number): Promise<Mandant> => request<Mandant>(`/api/admin/mandants/${id}`);

export const updateMandant = (id: number, payload: MandantPayload): Promise<Mandant> =>
    request<Mandant>(`/api/admin/mandants/${id}`, { method: 'PUT', body: JSON.stringify(payload) });

export const deleteMandant = (id: number): Promise<void> =>
    request<void>(`/api/admin/mandants/${id}`, { method: 'DELETE' });

export const uploadLogo = (id: number, file: File): Promise<void> =>
    uploadFile(`/api/admin/mandants/${id}/logo`, file);

export const uploadHeader = (id: number, file: File): Promise<void> =>
    uploadFile(`/api/admin/mandants/${id}/header`, file);

export const deleteLogo = (id: number): Promise<void> =>
    request<void>(`/api/admin/mandants/${id}/logo`, { method: 'DELETE' });

export const deleteHeader = (id: number): Promise<void> =>
    request<void>(`/api/admin/mandants/${id}/header`, { method: 'DELETE' });

export const uploadMyLogo = (file: File): Promise<void> => uploadFile('/api/mandant/logo', file);

export const deleteMyLogo = (): Promise<void> => request<void>('/api/mandant/logo', { method: 'DELETE' });

export const uploadMyHeader = (file: File): Promise<void> => uploadFile('/api/mandant/header', file);

export const deleteMyHeader = (): Promise<void> => request<void>('/api/mandant/header', { method: 'DELETE' });

export const listDomains = (mandantId: number): Promise<MandantDomain[]> =>
    request<MandantDomain[]>(`/api/admin/mandants/${mandantId}/domains`);

export const addDomain = (mandantId: number, hostname: string): Promise<MandantDomain> =>
    request<MandantDomain>(`/api/admin/mandants/${mandantId}/domains`, {
        method: 'POST',
        body: JSON.stringify({ hostname }),
    });

export const removeDomain = (mandantId: number, domainId: number): Promise<void> =>
    request<void>(`/api/admin/mandants/${mandantId}/domains/${domainId}`, { method: 'DELETE' });

export interface TeamPayload {
    name: string;
    slug: string;
    /**
     * Reference into the mandant's `venues` list (W12). Replaces the former
     * free-text `home_venue`; `null` clears the home venue. Omitting the key
     * entirely leaves a stored reference untouched (partial update).
     */
    venue_id?: number | null;
}

export const listTeams = (mandantId: number): Promise<Team[]> =>
    request<Team[]>(`/api/admin/mandants/${mandantId}/teams`);

export const createTeam = (mandantId: number, payload: TeamPayload): Promise<Team> =>
    request<Team>(`/api/admin/mandants/${mandantId}/teams`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });

export const updateTeam = (mandantId: number, teamId: number, payload: TeamPayload): Promise<Team> =>
    request<Team>(`/api/admin/mandants/${mandantId}/teams/${teamId}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });

export const deleteTeam = (mandantId: number, teamId: number): Promise<void> =>
    request<void>(`/api/admin/mandants/${mandantId}/teams/${teamId}`, { method: 'DELETE' });

/**
 * Mandant-scoped venue master data (W12). The list is NOT paginated by the
 * frontend: the combobox needs the whole mandant list to filter locally, and
 * the admin page paginates the same array client-side (the Categories/Events
 * page idiom).
 *
 * Two surfaces, one optional `mandantId`:
 * - omitted → the HOST-scoped endpoints, whose mandant the backend resolves
 *   from the request host (`MandantContext`). Correct for every page that lives
 *   on a mandant's domain: categories, events, accreditations, `VenuesPage`.
 * - given → the mandant-ADDRESSED endpoints. Required on `/admin/mandants/{id}`,
 *   the one page that addresses a mandant by URL: the host-scoped create wrote
 *   its inline venue into the host mandant while the page was about another one.
 */
export interface VenuePayload {
    name: string;
}

export interface VenueUpdatePayload {
    name?: string;
    is_active?: boolean;
}

/**
 * The venue endpoint family: host-scoped without a `mandantId`, mandant-
 * ADDRESSED with one. One place, so the three calls below cannot drift into
 * addressing a different mandant than they read.
 */
const venuePath = (mandantId: number | null | undefined, suffix = ''): string =>
    mandantId === null || mandantId === undefined
        ? `/api/admin/venues${suffix}`
        : `/api/admin/mandants/${mandantId}/venues${suffix}`;

export const listVenues = (mandantId?: number | null): Promise<Venue[]> => request<Venue[]>(venuePath(mandantId));

export const createVenue = (payload: VenuePayload, mandantId?: number | null): Promise<Venue> =>
    request<Venue>(venuePath(mandantId), { method: 'POST', body: JSON.stringify(payload) });

export const updateVenue = (id: number, payload: VenueUpdatePayload, mandantId?: number | null): Promise<Venue> =>
    request<Venue>(venuePath(mandantId, `/${id}`), { method: 'PUT', body: JSON.stringify(payload) });

/** 204 when unreferenced, 409 (with counts) while teams or events point at it. */
export const deleteVenue = (id: number): Promise<void> =>
    request<void>(`/api/admin/venues/${id}`, { method: 'DELETE' });

export interface CategoryPayload {
    name: string;
    slug: string;
    description?: string | null;
    team_id?: number | null;
}

export interface EventPayload {
    title: string;
    team_id?: number | null;
    date?: string | null;
    /**
     * Reference into the mandant's `venues` list (W12). Replaces the former
     * free-text `venue`; `null` clears the venue.
     */
    venue_id?: number | null;
    competition?: string | null;
    deadline_start?: string | null;
    deadline_end?: string | null;
    active?: boolean;
}

export interface QueryParams {
    team_id?: number;
    active?: boolean;
    search?: string;
    role?: string;
}

function buildQuery<T extends object>(params?: T): string {
    const searchParams = new URLSearchParams();
    for (const [key, value] of Object.entries(params ?? {})) {
        if (value !== undefined && value !== null) {
            searchParams.set(key, String(value));
        }
    }
    const query = searchParams.toString();

    return query === '' ? '' : `?${query}`;
}

export const listCategories = (params?: QueryParams): Promise<Category[]> =>
    request<Category[]>(`/api/admin/categories${buildQuery(params)}`);

export const createCategory = (payload: CategoryPayload): Promise<Category> =>
    request<Category>('/api/admin/categories', { method: 'POST', body: JSON.stringify(payload) });

export const updateCategory = (id: number, payload: CategoryPayload): Promise<Category> =>
    request<Category>(`/api/admin/categories/${id}`, { method: 'PUT', body: JSON.stringify(payload) });

export const deleteCategory = (id: number): Promise<void> =>
    request<void>(`/api/admin/categories/${id}`, { method: 'DELETE' });

export const listEvents = (params?: QueryParams): Promise<Event[]> =>
    request<Event[]>(`/api/admin/events${buildQuery(params)}`);

export const createEvent = (payload: EventPayload): Promise<Event> =>
    request<Event>('/api/admin/events', { method: 'POST', body: JSON.stringify(payload) });

export const updateEvent = (id: number, payload: EventPayload): Promise<Event> =>
    request<Event>(`/api/admin/events/${id}`, { method: 'PUT', body: JSON.stringify(payload) });

export const deleteEvent = (id: number): Promise<void> =>
    request<void>(`/api/admin/events/${id}`, { method: 'DELETE' });

export type UserRoleSlug = 'mandant_admin' | 'team_admin' | 'user' | 'verifier';

export interface UserRoleInput {
    role: UserRoleSlug;
    team_id?: number | null;
}

export const listUsers = (params?: QueryParams): Promise<AdminUser[]> =>
    request<AdminUser[]>(`/api/admin/users${buildQuery(params)}`);

export const updateUserRoles = (userId: number, roles: UserRoleInput[]): Promise<UserRoleAssignment[]> =>
    request<UserRoleAssignment[]>(`/api/admin/users/${userId}/roles`, {
        method: 'PUT',
        body: JSON.stringify({ roles }),
    });

/**
 * Hard-delete an account (DSGVO). Behind the backend's own `users.delete`
 * gate and its mandant-scoped `{user}` binding, so a foreign target is a 404 —
 * the UI gate on top of this is a convenience, never the authorisation.
 */
export const deleteUserAccount = (userId: number): Promise<AccountDeletionResult> =>
    request<AccountDeletionResult>(`/api/admin/users/${userId}`, { method: 'DELETE' });

export interface PortalEventsParams {
    team_id?: number | null;
    competition?: string;
}

export const getPortalOverview = (): Promise<PortalOverview> => request<PortalOverview>('/api/portal/overview');

export const getPortalEvents = (params?: PortalEventsParams): Promise<PortalEvent[]> =>
    request<PortalEvent[]>(`/api/portal/events${buildQuery(params)}`);

export const getPortalEvent = (id: number): Promise<PortalEventDetail> => request<PortalEventDetail>(`/api/portal/events/${id}`);

export interface AccreditationParams {
    event_id?: number;
}

export interface AdminAccreditationParams {
    team_id?: number;
    active?: boolean;
}

export interface AccreditationPayload {
    category_id: number;
    scope: AccreditationScope;
    event_id?: number | null;
    team_id?: number | null;
    quota: number;
    deadline_start?: string | null;
    deadline_end?: string | null;
    auto_approve?: boolean;
    active?: boolean;
}

export const listAccreditations = (params?: AccreditationParams): Promise<Accreditation[]> =>
    request<Accreditation[]>(`/api/accreditations${buildQuery(params)}`);

export const getAccreditation = (id: number): Promise<Accreditation> => request<Accreditation>(`/api/accreditations/${id}`);

export const applyAccreditation = (id: number): Promise<Application> =>
    request<Application>(`/api/accreditations/${id}/apply`, { method: 'POST' });

export const listApplications = (): Promise<Application[]> => request<Application[]>('/api/applications');

export const withdrawApplication = (id: number): Promise<void> =>
    request<void>(`/api/applications/${id}`, { method: 'DELETE' });

export const listAdminAccreditations = (params?: AdminAccreditationParams): Promise<Accreditation[]> =>
    request<Accreditation[]>(`/api/admin/accreditations${buildQuery(params)}`);

export const createAccreditation = (payload: AccreditationPayload): Promise<Accreditation> =>
    request<Accreditation>('/api/admin/accreditations', { method: 'POST', body: JSON.stringify(payload) });

export const updateAccreditation = (id: number, payload: AccreditationPayload): Promise<Accreditation> =>
    request<Accreditation>(`/api/admin/accreditations/${id}`, { method: 'PUT', body: JSON.stringify(payload) });

export const deleteAccreditation = (id: number): Promise<void> =>
    request<void>(`/api/admin/accreditations/${id}`, { method: 'DELETE' });

export interface SubAccreditationPayload {
    type: SubType;
    quota: number;
    deadline_start?: string | null;
    deadline_end?: string | null;
    auto_approve?: boolean;
    active?: boolean;
}

export const listSubAccreditations = (accreditationId: number): Promise<SubAccreditation[]> =>
    request<SubAccreditation[]>(`/api/accreditations/${accreditationId}/sub-accreditations`);

export const listAdminSubAccreditations = (accreditationId: number): Promise<SubAccreditation[]> =>
    request<SubAccreditation[]>(`/api/admin/accreditations/${accreditationId}/sub-accreditations`);

export interface AdminSubAccreditationsParams {
    accreditation_id?: number;
    category_id?: number;
    event_id?: number;
    team_id?: number;
    type?: SubType;
    active?: boolean;
    search?: string;
}

/**
 * P3e-B4: mandant-wide filtered sub-accreditation list — one request with
 * server-side filters instead of N parallel per-accreditation requests.
 */
export const listAllAdminSubAccreditations = (params?: AdminSubAccreditationsParams): Promise<SubAccreditation[]> =>
    request<SubAccreditation[]>(`/api/admin/sub-accreditations${buildQuery(params)}`);

export const createSubAccreditation = (accreditationId: number, payload: SubAccreditationPayload): Promise<SubAccreditation> =>
    request<SubAccreditation>(`/api/admin/accreditations/${accreditationId}/sub-accreditations`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });

export const updateSubAccreditation = (id: number, payload: SubAccreditationPayload): Promise<SubAccreditation> =>
    request<SubAccreditation>(`/api/admin/sub-accreditations/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });

export const deleteSubAccreditation = (id: number): Promise<void> =>
    request<void>(`/api/admin/sub-accreditations/${id}`, { method: 'DELETE' });

export const applySubAccreditation = (id: number): Promise<SubApplication> =>
    request<SubApplication>(`/api/sub-accreditations/${id}/apply`, { method: 'POST' });

export const listSubApplications = (): Promise<SubApplication[]> => request<SubApplication[]>('/api/sub-applications');

export const withdrawSubApplication = (id: number): Promise<void> =>
    request<void>(`/api/sub-applications/${id}`, { method: 'DELETE' });

export interface AdminApplicationsParams {
    accreditation_id?: number;
    status?: string;
    search?: string;
}

export const listAdminApplications = (params?: AdminApplicationsParams): Promise<AdminApplication[]> =>
    request<AdminApplication[]>(`/api/admin/applications${buildQuery(params)}`);

export const updateAdminApplication = (id: number, action: ApplicationAction): Promise<AdminApplication> =>
    request<AdminApplication>(`/api/admin/applications/${id}`, {
        method: 'PUT',
        body: JSON.stringify(action),
    });

export const listAdminApplicationMedia = (id: number): Promise<AdminMedia[]> =>
    request<AdminMedia[]>(`/api/admin/applications/${id}/media`);

/**
 * Order the status mail for one application again.
 *
 * Returns the SERVER's message. Since Position 45 `MandantMailerService::send()`
 * only dispatches `SendMandantMail`, so this endpoint cannot know whether the
 * relay answered — it reports what it really did ("in die Warteschlange
 * gestellt"), and the UI used to contradict it with a string of its own
 * ("erneut gesendet"). `''` only when the body carries no message at all.
 */
export const resendApplicationMail = (applicationId: number): Promise<string> =>
    requestMessage(`/api/admin/applications/${applicationId}/resend`, { method: 'POST' });

export interface AdminSubApplicationsParams {
    sub_accreditation_id?: number;
    status?: string;
}

export const listAdminSubApplications = (params?: AdminSubApplicationsParams): Promise<AdminSubApplication[]> =>
    request<AdminSubApplication[]>(`/api/admin/sub-applications${buildQuery(params)}`);

export const updateAdminSubApplication = (id: number, action: ApplicationAction): Promise<AdminSubApplication> =>
    request<AdminSubApplication>(`/api/admin/sub-applications/${id}`, {
        method: 'PUT',
        body: JSON.stringify(action),
    });

/**
 * Order the status mail for ONE sub-application again — the Park-/Sitzkarte
 * counterpart of `resendApplicationMail`, and the same contract:
 *
 *  - `AdminSubApplicationController::resend` answers a BARE `{message}`
 *    ("E-Mail wurde erneut in die Warteschlange gestellt."), so it is read by
 *    `requestMessage`, never by `request` (that one unwraps `{data: …}` and
 *    would hand `undefined` to the UI).
 *  - The message is the SERVER's account of what it did; since Position 45
 *    `MandantMailerService::send()` only dispatches a job, so neither this
 *    endpoint nor the UI may claim the mail was sent. `''` only when the body
 *    carries no message at all.
 *  - A foreign mandant answers **404**, a `team_admin` on a foreign team
 *    **403**, and a `requested` row (or a denied one without a reason) **422**
 *    — all `ApiError`s the caller must surface, never swallow.
 */
export const resendSubApplicationMail = (subApplicationId: number): Promise<string> =>
    requestMessage(`/api/admin/sub-applications/${subApplicationId}/resend`, { method: 'POST' });

export interface BlacklistPayload {
    email?: string;
    domain?: string;
    note?: string;
}

export const listBlacklists = (params?: { search?: string }): Promise<Blacklist[]> =>
    request<Blacklist[]>(`/api/admin/blacklists${buildQuery(params)}`);

export const createBlacklist = (payload: BlacklistPayload): Promise<Blacklist> =>
    request<Blacklist>('/api/admin/blacklists', { method: 'POST', body: JSON.stringify(payload) });

export const deleteBlacklist = (id: number): Promise<void> =>
    request<void>(`/api/admin/blacklists/${id}`, { method: 'DELETE' });

/**
 * The dead-letter queue of undelivered mandant mails (Position 45).
 *
 * PAGINATED since 2026-10-06, with the window in `meta` rather than implied by
 * "the list ends". The endpoint still takes no FILTER — the search box and the
 * mandant selector stay client-side and only see the rows of the CURRENT page,
 * which is the one thing this surface must not pretend otherwise about: the page
 * says how many letters the server reported in total, so a filtered view can be
 * read as "3 of 213" instead of as the whole queue.
 *
 * `per_page` is validated server-side (`FailedMailController::PER_PAGE_MIN` /
 * `PER_PAGE_MAX`, 1…200) and an out-of-range value is a 422 rather than a
 * silent clamp, so the default lives here — in ONE place, named once.
 *
 * Scope is the backend's, not the UI's: `super_admin` sees every mandant,
 * `mandant_admin` only his own (a foreign letter is a 404, never a 403).
 */
export const listFailedMails = (params?: { page?: number; perPage?: number }): Promise<Paginated<FailedMail>> =>
    requestPage<FailedMail>(
        `/api/admin/failed-mails${buildQuery({
            page: params?.page,
            per_page: params?.perPage,
        })}`,
    );

/**
 * Move one dead letter back onto the queue — a human, logged decision, and the
 * ONLY way out of the terminal `dead` state.
 *
 * Returns the server's message (bare `{message}`, `FailedMailController.php:109`).
 * A foreign letter answers **404** and a role without `mails.dlq.manage` answers
 * **403**; both are `ApiError`s the caller must surface, never swallow.
 */
export const requeueFailedMail = (failedMailId: number): Promise<string> =>
    requestMessage(`/api/admin/failed-mails/${failedMailId}/requeue`, { method: 'POST' });

export interface AllocationPayload {
    mode: 'all' | 'first';
    limit?: number;
}

export const allocateAccreditation = (id: number, payload: AllocationPayload): Promise<AllocationResult> =>
    request<AllocationResult>(`/api/admin/accreditations/${id}/allocate`, {
        method: 'POST',
        body: JSON.stringify(payload),
    });

export interface BadgeTemplatePayload {
    name: string;
    layout: BadgeLayoutEntry[];
    is_default?: boolean;
}

export interface BadgeExportPayload {
    format: 'pdf' | 'csv';
    template_id?: number;
}

export const listBadgeTemplates = (): Promise<BadgeTemplate[]> => request<BadgeTemplate[]>('/api/admin/badge-templates');

export const createBadgeTemplate = (payload: BadgeTemplatePayload): Promise<BadgeTemplate> =>
    request<BadgeTemplate>('/api/admin/badge-templates', { method: 'POST', body: JSON.stringify(payload) });

export const updateBadgeTemplate = (id: number, payload: BadgeTemplatePayload): Promise<BadgeTemplate> =>
    request<BadgeTemplate>(`/api/admin/badge-templates/${id}`, { method: 'PUT', body: JSON.stringify(payload) });

export const deleteBadgeTemplate = (id: number): Promise<void> =>
    request<void>(`/api/admin/badge-templates/${id}`, { method: 'DELETE' });

/**
 * Mandant-owned badge images for freely placed `image` layout entries
 * (features/badge-template-editor.md, "Upload-Infrastruktur"). Backed by the
 * `badge_images` table + auth-gated admin API (`GET/POST/DELETE /api/admin/
 * badge-images`, `GET /api/admin/badge-images/{id}/file`); brand sources
 * (`logo`/`header`) resolve through the mandant media service.
 */
export interface BadgeImage {
    id: number;
    original_name: string;
    mime: string;
}

/** Auth-gated file URL of one badge image (editor thumbnails/previews). */
export const badgeImageFileUrl = (id: number): string => `/api/admin/badge-images/${id}/file`;

/**
 * Auth-gated URL of the bundled person silhouette that stands in for a missing
 * portrait in a `photo` entry (features/badge-template-editor.md, "Platzhalter
 * für ein fehlendes Porträt").
 *
 * The editor must show the *same* icon the PDF prints, and that icon may exist
 * in this repository exactly once: the bytes live in
 * `backend/resources/img/badge/photo-placeholder.png` and the backend serves
 * them from there. A second copy in the SPA (an `import`ed asset, an iconify
 * class, a file in `public/`) would drift from what the badge prints — and
 * `public/` is served without authentication, which AGENTS.md §11 does not allow
 * for the editor's byte path.
 */
export const badgePhotoPlaceholderUrl = '/api/admin/badge-assets/photo-placeholder';

export const listBadgeImages = (): Promise<BadgeImage[]> => request<BadgeImage[]>('/api/admin/badge-images');

export const uploadBadgeImage = (file: File): Promise<BadgeImage> => {
    const body = new FormData();
    body.append('file', file);

    return request<BadgeImage>('/api/admin/badge-images', { method: 'POST', body });
};

export const deleteBadgeImage = (id: number): Promise<void> =>
    request<void>(`/api/admin/badge-images/${id}`, { method: 'DELETE' });

/**
 * Shared binary-response fetch. The `request` helper only unwraps JSON
 * envelopes — badge exports and wallet passes answer binary, so they are
 * fetched here. JSON `{message}` error bodies (e.g. the badge export's 422
 * `messages.badges.no_template`) are still surfaced as ApiError for the caller,
 * which is what lets the UI show a real message instead of silently downloading
 * the error body.
 *
 * ## `Accept-Language` IS here now, because binary endpoints answer catalog
 * strings
 *
 * It used to be absent, and the reason was measured rather than assumed: no
 * binary endpoint answered a catalog string then, so the header would have
 * localized nothing. That stopped being true on 2026-10-05, when the last three
 * hardcoded English literals in `app/Http/Controllers/Api/` became
 * `__('messages.…')` — `BadgeExportController`'s 422 and `WalletController`'s
 * 410 and both 422s, all reachable from this transport, all rendered verbatim by
 * the caller (`ApprovalsPage`'s export alert, `MyAccreditationsPage`'s wallet
 * error line).
 *
 * So the header is set here for the same reason it is set in `send`, and the
 * header note in `send` no longer says the binary transport is the exception.
 * Two refusals reachable through this transport stay hardcoded literals —
 * `'Mandant not found'` (`WalletController::currentMandant`) and
 * `'No mandant context for this request.'` (`BadgeExportController`) — because
 * both are internal tenancy invariants rather than copy, which is also why this
 * paragraph names the catalog keys instead of claiming every binary body is
 * localized.
 */
async function fetchBinary(path: string, init: RequestInit = {}): Promise<Response> {
    const headers = new Headers(init.headers);
    // The refusals reachable through this transport are catalog strings
    // (`backend/lang/{de,en}/messages.php`) rendered verbatim by the caller, so
    // the app's locale has to reach the backend here exactly as it does in
    // `send`. Read at CALL time — see `logic/uiLocale.ts`.
    headers.set('Accept-Language', activeUiLocale());

    let response: Response;
    try {
        response = await fetch(path, { ...init, headers, credentials: 'include' });
    } catch {
        throw new ApiError(0, 'Netzwerkfehler: Keine Verbindung zum Server.', {});
    }

    if (response.status === 401) {
        unauthorizedHandler?.();
    }

    if (!response.ok) {
        let info: ApiErrorInfo = {};
        const contentType = response.headers.get('content-type');
        if (contentType?.includes('application/json')) {
            try {
                info = (await response.json()) as ApiErrorInfo;
            } catch {
                // Non-JSON error body — keep the fallback message.
            }
        }
        const message =
            typeof info.message === 'string' && info.message !== '' ? info.message : `HTTP ${response.status}`;
        throw new ApiError(response.status, message, info);
    }

    return response;
}

/**
 * Streams the badge export (PDF/CSV) as a blob.
 */
export async function exportBadges(accreditationId: number, payload: BadgeExportPayload): Promise<Blob> {
    const response = await fetchBinary(`/api/admin/accreditations/${accreditationId}/badges/export`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/pdf, text/csv' },
        body: JSON.stringify(payload),
    });

    return response.blob();
}

export interface BinaryDownload {
    blob: Blob;
    /**
     * Filename taken from the response's `Content-Disposition` when present.
     * `WalletController::google()` sends no header at all, so the caller's
     * fallback is used for the Google payload.
     */
    filename: string;
}

function filenameFromContentDisposition(header: string | null, fallback: string): string {
    if (header === null || header === '') {
        return fallback;
    }

    const encoded = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (encoded?.[1]) {
        try {
            return decodeURIComponent(encoded[1].trim());
        } catch {
            // Malformed percent-encoding — fall through to the plain form.
        }
    }

    const plain = /filename="([^"]*)"|filename=([^;]+)/i.exec(header);
    const raw = (plain?.[1] ?? plain?.[2])?.trim();

    return raw !== undefined && raw !== '' ? raw : fallback;
}

async function toBinaryDownload(response: Response, fallbackFilename: string): Promise<BinaryDownload> {
    return {
        blob: await response.blob(),
        filename: filenameFromContentDisposition(response.headers.get('content-disposition'), fallbackFilename),
    };
}

const APPLE_WALLET_MIME = 'application/vnd.apple.pkpass';

export type WalletProvider = 'apple' | 'google';

/**
 * Wallet pass of an approved application. `WalletController::google()` answers
 * a plain JSON payload without `Content-Disposition`, hence the per-provider
 * fallback filename.
 */
export async function downloadApplicationWallet(
    applicationId: number,
    provider: WalletProvider,
): Promise<BinaryDownload> {
    const response = await fetchBinary(
        provider === 'apple'
            ? `/api/applications/${applicationId}/wallet`
            : `/api/applications/${applicationId}/wallet/google`,
        { headers: { Accept: provider === 'apple' ? APPLE_WALLET_MIME : 'application/json' } },
    );

    return toBinaryDownload(response, provider === 'apple' ? 'wallet.pkpass' : 'wallet.json');
}

/** Wallet pass of an approved sub-application (Apple only). */
export async function downloadSubApplicationWallet(subApplicationId: number): Promise<BinaryDownload> {
    const response = await fetchBinary(`/api/sub-applications/${subApplicationId}/wallet`, {
        headers: { Accept: APPLE_WALLET_MIME },
    });

    return toBinaryDownload(response, 'wallet.pkpass');
}

export const verifyToken = (token: string): Promise<VerifyResult> =>
    request<VerifyResult>(`/api/verify/${encodeURIComponent(token)}`);
