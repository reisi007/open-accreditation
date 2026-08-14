import {
    ensurePrimaryMandantAccreditation,
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
 */

export type CredentialsSeedResult = {
    email: string;
    password: string;
};

export type ApplySeedResult = CredentialsSeedResult & {
    accreditationId: number;
    categoryName: string;
};

/** One active event-scoped accreditation + one applicant user (requested application). */
export async function seedApplyFilled(): Promise<ApplySeedResult> {
    const { accreditation, categoryName } = await ensurePrimaryMandantAccreditation();
    const user = await registerAndApplyForAccreditation(accreditation.id, 'E2E Bewerber Screenshot');
    return { accreditationId: accreditation.id, categoryName, ...user };
}

/** A user with one requested application — "Meine Akkreditierungen" filled. */
export async function seedMyAccreditationsFilled(): Promise<CredentialsSeedResult> {
    const { accreditation } = await ensurePrimaryMandantAccreditation();
    return registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller Screenshot');
}

/** A mandant-scoped user — the admin users list filled. */
export async function seedUsersFilled(): Promise<CredentialsSeedResult> {
    return registerAndActivateUser();
}

/** One accreditation + one requested application — the approvals view filled. */
export async function seedFreigabenFilled(): Promise<CredentialsSeedResult> {
    const { accreditation } = await ensurePrimaryMandantAccreditation();
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
