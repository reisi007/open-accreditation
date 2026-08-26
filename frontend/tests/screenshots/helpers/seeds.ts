import {
    ensurePrimaryMandantAccreditation,
    ensurePrimaryMandantActivePortalEvent,
    ensurePrimaryMandantApprovedApplication,
    loginAdminApi,
    registerAndActivateUser,
    registerAndApplyForAccreditation,
} from '../../e2e/helpers/admin-data';

/**
 * Project-specific seed functions for the filled screenshot states. Each seed
 * makes the primary mandant's data deterministic via the existing E2E data
 * helpers and returns the ids/credentials the spec needs (dynamic route
 * params, UI-scoped clicks, user logins).
 *
 * Login budget: the backend throttles logins per IP (40/min in local), and
 * every seed + browser login counts against it. Seeds that authenticate as
 * admin (or a seeded user) are therefore wrapped in `cachedSeed` — one login
 * per worker process instead of one per test. Data-creation seeds without a
 * login (registration only) stay per-test.
 */

type SeedFn = () => Promise<Record<string, unknown>>;

const seedCache = new Map<string, Promise<Record<string, unknown>>>();

function cachedSeed<T extends SeedFn>(key: string, fn: T): T {
    const memoized = (() => {
        const existing = seedCache.get(key);
        if (existing !== undefined) {
            return existing;
        }
        const promise = fn();
        seedCache.set(key, promise);
        return promise;
    }) as unknown as T;
    return memoized;
}

export type CredentialsSeedResult = {
    email: string;
    password: string;
};

export type ApplySeedResult = CredentialsSeedResult & {
    accreditationId: number;
    categoryName: string;
};

/** One active event-scoped accreditation (cached per worker). */
export const seedAccreditation = cachedSeed('accreditation', () => ensurePrimaryMandantAccreditation());

/** One active portal event + team (cached per worker). */
export const seedPortalEvent = cachedSeed('portal-event', () => ensurePrimaryMandantActivePortalEvent());

/** The primary mandant's id (cached per worker). */
export const seedPrimaryMandant = cachedSeed('primary-mandant', () => seedPrimaryMandantId());

/** One badge template (cached per worker). */
export const seedBadgeTemplate = cachedSeed('badge-template', () => seedBadgeTemplatesFilled());

/**
 * One COMPLETE schema-v2 badge template — every entry type the editor palette
 * offers (cached per worker). Both badge EDITOR routes seed this shape so the
 * "Bearbeiten" click (which opens the NEWEST row, id desc) can never land on
 * the legacy three-field layout of `seedBadgeTemplate`.
 */
export const seedBadgeTemplateSchemaV2 = cachedSeed('badge-template-schema-v2', () =>
    seedBadgeTemplateSchemaV2Filled(),
);

/** One approved application with a real QR token (cached per worker). */
export const seedApprovedApplicationCached = cachedSeed('approved-application', () => seedApprovedApplication());

/** One accreditation + one applicant user (requested application) — apply page. */
export async function seedApplyFilled(): Promise<ApplySeedResult> {
    const { accreditation, categoryName } = await seedAccreditation();
    const user = await registerAndApplyForAccreditation(accreditation.id, 'E2E Bewerber Screenshot');
    return { accreditationId: accreditation.id, categoryName, ...user };
}

/** A user with one requested application — "Meine Akkreditierungen" filled. */
export async function seedMyAccreditationsFilled(): Promise<CredentialsSeedResult> {
    const { accreditation } = await seedAccreditation();
    return registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller Screenshot');
}

/** A mandant-scoped user — the admin users list filled. */
export async function seedUsersFilled(): Promise<CredentialsSeedResult> {
    return registerAndActivateUser();
}

/** One accreditation + one requested application — the approvals view filled. */
export async function seedFreigabenFilled(): Promise<CredentialsSeedResult> {
    const { accreditation } = await seedAccreditation();
    return registerAndApplyForAccreditation(accreditation.id, 'E2E Freigaben Antrag');
}

/** An approved application with a real QR token — the public verify result page. */
export async function seedApprovedApplication(): Promise<{ token: string }> {
    const { application } = await ensurePrimaryMandantApprovedApplication();
    const qrUrl = String(application.qr_url);
    const token = qrUrl.split('/').pop();
    if (token === undefined || token === '') {
        throw new Error('Approved application carries no qr token');
    }
    return { token };
}

/** The primary mandant's id — the admin mandant detail deep link. */
export async function seedPrimaryMandantId(): Promise<{ id: number }> {
    const api = await loginAdminApi();
    try {
        const body = await (await api.get('/api/admin/mandants')).json();
        const mandants = body.data as Array<{ id: number; is_primary: boolean }> | undefined;
        const primary = mandants?.find((entry) => entry.is_primary) ?? mandants?.[0];
        if (primary === undefined) {
            throw new Error('No mandant found for the admin-mandant-detail seed');
        }
        return { id: primary.id };
    } finally {
        await api.dispose();
    }
}

/** One badge template — the badge templates list filled. */
export async function seedBadgeTemplatesFilled(): Promise<Record<string, unknown>> {
    const api = await loginAdminApi();
    try {
        const create = await api.post('/api/admin/badge-templates', {
            data: {
                name: `E2E Ausweis-Template ${Date.now()}`,
                layout: [
                    { field: 'name', x: 5, y: 5, w: 50, h: 10, size: 12, align: 'left' },
                    { field: 'category', x: 5, y: 20, w: 50, h: 10, size: 10, align: 'left' },
                    { field: 'date', x: 5, y: 35, w: 50, h: 10, size: 10, align: 'left' },
                ],
                is_default: true,
            },
        });
        if (create.status() !== 201) {
            throw new Error(`Badge template seed failed with status ${create.status()}`);
        }
        return {};
    } finally {
        await api.dispose();
    }
}

/**
 * One complete schema-v2 badge template (FE3 screenshot seed): photo top-left,
 * qr top-right (~78/8, 20 × 20), the seven data fields with size + align in
 * the free canvas areas. Non-overlapping and inside the A6 bounds
 * (105 × 148 mm), so the editor edit capture shows all nine boxes plus a
 * clean properties panel without overlap warnings.
 */
export async function seedBadgeTemplateSchemaV2Filled(): Promise<Record<string, unknown>> {
    const api = await loginAdminApi();
    try {
        const create = await api.post('/api/admin/badge-templates', {
            data: {
                name: `E2E Schema-v2 Volltemplate ${Date.now()}`,
                layout: [
                    { field: 'photo', x: 8, y: 8, w: 22, h: 28, size: 12, align: 'left' },
                    { field: 'qr', x: 78, y: 8, w: 20, h: 20 },
                    { field: 'name', x: 35, y: 8, w: 40, h: 8, size: 14, align: 'left' },
                    { field: 'category', x: 35, y: 18, w: 40, h: 6, size: 10, align: 'left' },
                    { field: 'event', x: 35, y: 26, w: 40, h: 6, size: 9, align: 'left' },
                    { field: 'date', x: 35, y: 34, w: 40, h: 6, size: 9, align: 'left' },
                    { field: 'status', x: 8, y: 42, w: 30, h: 6, size: 9, align: 'left' },
                    { field: 'team', x: 8, y: 50, w: 60, h: 6, size: 9, align: 'left' },
                    { field: 'vest_number', x: 8, y: 58, w: 30, h: 6, size: 9, align: 'left' },
                ],
            },
        });
        if (create.status() !== 201) {
            throw new Error(`Schema-v2 badge template seed failed with status ${create.status()}`);
        }
        return {};
    } finally {
        await api.dispose();
    }
}
