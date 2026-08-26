import type {
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
    Mandant,
    MandantDomain,
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

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    if (!(init.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }
    headers.set('Accept', 'application/json');

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
    home_venue?: string | null;
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
    venue?: string | null;
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

export const resendApplicationMail = (applicationId: number): Promise<void> =>
    request<void>(`/api/admin/applications/${applicationId}/resend`, { method: 'POST' });

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

export const listBadgeImages = (): Promise<BadgeImage[]> => request<BadgeImage[]>('/api/admin/badge-images');

export const uploadBadgeImage = (file: File): Promise<BadgeImage> => {
    const body = new FormData();
    body.append('file', file);

    return request<BadgeImage>('/api/admin/badge-images', { method: 'POST', body });
};

export const deleteBadgeImage = (id: number): Promise<void> =>
    request<void>(`/api/admin/badge-images/${id}`, { method: 'DELETE' });

/**
 * Streams the badge export (PDF/CSV). The `request` helper only unwraps JSON
 * envelopes — this endpoint answers binary, so the fetch is done here directly.
 * JSON `{message}` error bodies (e.g. the 422 "no default template") are still
 * surfaced as ApiError for the caller.
 */
export async function exportBadges(accreditationId: number, payload: BadgeExportPayload): Promise<Blob> {
    let response: Response;
    try {
        response = await fetch(`/api/admin/accreditations/${accreditationId}/badges/export`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/pdf, text/csv' },
            body: JSON.stringify(payload),
            credentials: 'include',
        });
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

    return response.blob();
}

export const verifyToken = (token: string): Promise<VerifyResult> =>
    request<VerifyResult>(`/api/verify/${encodeURIComponent(token)}`);
