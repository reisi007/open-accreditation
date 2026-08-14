import type { APIRequestContext } from '@playwright/test';
import { loginAdminApi } from '../../e2e/helpers/admin-data';

/**
 * Fixture tenant for the "empty" screenshot states: a secondary mandant
 * without any data, reachable under its own `.localhost` domain.
 *
 * How it works: `MandantContextMiddleware` resolves the current mandant from
 * the Referer host in local dev. `localhost` falls back to the PRIMARY
 * mandant, but `http://empty.localhost:5173` (RFC 6761 loopback, Vite listens
 * on 0.0.0.0) resolves to THIS mandant — so its public pages render empty and
 * its admin pages render empty tables. The global `super_admin` login
 * (admin@example.com / admin) is mandant-independent, so admin-page access on
 * the fixture tenant needs no per-tenant user setup.
 */

export const EMPTY_MANDANT_SLUG = 'empty';
export const EMPTY_MANDANT_NAME = 'Leerer Mandant';
export const EMPTY_MANDANT_DOMAIN = 'empty.localhost';
export const EMPTY_MANDANT_ORIGIN = 'http://empty.localhost:5173';

interface MandantRow {
    id: number;
    slug: string;
    is_primary: boolean;
    is_active: boolean;
}

interface MandantDomainRow {
    hostname: string;
}

async function findMandantBySlug(api: APIRequestContext, slug: string): Promise<MandantRow | null> {
    const body = await (await api.get('/api/admin/mandants')).json();
    const mandants = body.data as MandantRow[] | undefined;
    return mandants?.find((entry) => entry.slug === slug) ?? null;
}

async function findDomain(api: APIRequestContext, mandantId: number, hostname: string): Promise<boolean> {
    const body = await (await api.get(`/api/admin/mandants/${mandantId}/domains`)).json();
    const domains = body.data as MandantDomainRow[] | undefined;
    return (domains ?? []).some((entry) => entry.hostname === hostname);
}

/**
 * Idempotent find-or-create of the fixture tenant: creates the `empty`
 * mandant (is_active, not primary) with the `empty.localhost` domain when
 * missing. Re-runs and parallel workers are safe (a create race falls back to
 * re-reading the list). Never touches the primary mandant.
 */
export async function ensureEmptyMandant(): Promise<void> {
    const api = await loginAdminApi();
    try {
        let mandant = await findMandantBySlug(api, EMPTY_MANDANT_SLUG);
        if (mandant === null) {
            const create = await api.post('/api/admin/mandants', {
                data: {
                    name: EMPTY_MANDANT_NAME,
                    slug: EMPTY_MANDANT_SLUG,
                    is_active: true,
                    teams_enabled: false,
                },
            });
            if (create.status() === 201) {
                mandant = (await create.json()).data as MandantRow;
            } else {
                // Parallel-worker race on the slug unique constraint → re-read.
                mandant = await findMandantBySlug(api, EMPTY_MANDANT_SLUG);
            }
        }
        if (mandant === null) {
            throw new Error(`Fixture mandant "${EMPTY_MANDANT_SLUG}" could not be created or found`);
        }

        // A stale fixture that lost its active flag would 404 in
        // MandantContextMiddleware — keep it reachable.
        if (!mandant.is_active) {
            await api.put(`/api/admin/mandants/${mandant.id}`, { data: { is_active: true } });
        }

        if (!(await findDomain(api, mandant.id, EMPTY_MANDANT_DOMAIN))) {
            const add = await api.post(`/api/admin/mandants/${mandant.id}/domains`, {
                data: { hostname: EMPTY_MANDANT_DOMAIN },
            });
            if (add.status() !== 201) {
                // Parallel-worker race → another run already added the domain.
            }
        }
    } finally {
        await api.dispose();
    }
}
