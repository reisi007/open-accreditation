import {
    ensurePrimaryMandantAccreditation,
    ensurePrimaryMandantActivePortalEvent,
} from '../e2e/helpers/admin-data';
import {
    seedApplyFilled,
    seedApprovedApplication,
    seedBadgeTemplatesFilled,
    seedFreigabenFilled,
    seedMyAccreditationsFilled,
    seedPrimaryMandantId,
    seedUsersFilled,
} from './helpers/seeds';

/**
 * UI-review route manifest — the single source of truth for which pages get
 * screenshotted and in which states. Edit this file to add/remove routes; the
 * generic spec (`ui-screenshots.spec.ts`) picks the changes up automatically.
 *
 * State semantics:
 * - `filled` — the primary mandant with deterministic seeded data (via the
 *   route's `seed`, reusing the E2E admin-data helpers).
 * - `empty`  — the fixture tenant (`empty.localhost`, see
 *   `helpers/empty-mandant.ts`) that resolves to a data-less mandant. A global
 *   super_admin login grants admin-page access there, so no per-tenant user is
 *   needed. Routes whose empty state is not meaningful (forms, global
 *   super-admin surfaces) document that in `note`.
 */

export type UiReviewState = 'filled' | 'empty';
export type UiReviewViewport = 'desktop' | 'mobile';
export type UiReviewAuth = 'guest' | 'admin' | 'user' | 'none';

export interface UiReviewClickStep {
    kind: 'click';
    /** Semantic landmark to scope the click to (header, admin sidebar, main content). */
    scope: 'banner' | 'complementary' | 'main';
    role: 'link' | 'button';
    /** Exact accessible name; omit to click the first matching element (e.g. the first list card). */
    name?: string;
    /** Restrict to an <article> whose text contains the seed value for this key first. */
    within?: string;
}

export interface UiReviewGotoStep {
    kind: 'goto';
    /** May contain `:param` placeholders resolved from the seed result. */
    path: string;
    /** Why a direct URL load is justified (deep link / dynamic detail page / no UI entry point). */
    reason: string;
}

export type UiReviewNavStep = UiReviewClickStep | UiReviewGotoStep;

export type UiReviewSeed = () => Promise<Record<string, unknown>>;

export interface UiReviewRoute {
    name: string;
    /** Route pattern; `:param` tokens are resolved from the seed result where used. */
    path: string;
    states: UiReviewState[];
    auth?: UiReviewAuth;
    viewports?: UiReviewViewport[];
    note?: string;
    seed?: UiReviewSeed;
    nav?: UiReviewNavStep[];
}

export interface UiReviewConfig {
    /** Must mirror `outputDir` in playwright.screenshots.config.ts — the spec builds absolute screenshot paths from it. */
    outputDir: string;
    routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
    outputDir: 'test-results/ui-screenshots',
    routes: [
        // ── Public / guest ──────────────────────────────────────────────────
        {
            name: 'home',
            path: '/',
            states: ['filled', 'empty'],
            auth: 'guest',
            seed: () => ensurePrimaryMandantActivePortalEvent(),
        },
        {
            name: 'akkreditierungen',
            path: '/akkreditierungen',
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Akkreditierungen' }],
            seed: () => ensurePrimaryMandantAccreditation(),
        },
        {
            name: 'login',
            path: '/login',
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Anmelden' }],
            note: 'Pure form, no data dependency — filled and empty render identically; captured on both mandants for completeness.',
        },
        {
            name: 'verify',
            path: '/verify',
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Verifizieren' }],
        },
        {
            name: 'verify-token',
            path: '/verify/:token',
            states: ['filled'],
            auth: 'guest',
            viewports: ['desktop'],
            nav: [
                {
                    kind: 'goto',
                    path: '/verify/:token',
                    reason: 'Approved-application QR links have no UI entry point — the token comes from the seeded approved application (P4 badge flow), a justified direct-URL load.',
                },
            ],
            seed: () => seedApprovedApplication(),
        },
        {
            name: 'events-detail',
            path: '/events/:id',
            states: ['filled'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'main', role: 'link' }],
            seed: () => ensurePrimaryMandantActivePortalEvent(),
        },

        // ── Authenticated user ──────────────────────────────────────────────
        {
            name: 'meine-akkreditierungen',
            path: '/meine-akkreditierungen',
            states: ['filled', 'empty'],
            auth: 'user',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Meine Akkreditierungen' }],
            note: 'Empty = a freshly activated user without applications (mandant-independent — the list shows only the user’s own applications).',
            seed: () => seedMyAccreditationsFilled(),
        },
        {
            name: 'apply',
            path: '/apply/:accreditationId',
            states: ['filled'],
            auth: 'user',
            nav: [
                { kind: 'click', scope: 'banner', role: 'link', name: 'Akkreditierungen' },
                { kind: 'click', scope: 'main', role: 'link', name: 'Beantragen', within: 'categoryName' },
            ],
            seed: () => seedApplyFilled(),
        },

        // ── Admin ───────────────────────────────────────────────────────────
        {
            name: 'admin-mandants',
            path: '/admin/mandants',
            states: ['filled'],
            auth: 'admin',
            note: 'The mandant list is a global super-admin surface (not mandant-scoped) — a data-less tenant cannot render it empty; filled only.',
        },
        {
            name: 'admin-mandants-new',
            path: '/admin/mandants/new',
            states: ['filled'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'main', role: 'link', name: 'Neu' }],
            note: 'Pure form, no data dependency — the empty state would be identical to the filled one.',
        },
        {
            name: 'admin-mandant-detail',
            path: '/admin/mandants/:id',
            states: ['filled'],
            auth: 'admin',
            nav: [
                {
                    kind: 'goto',
                    path: '/admin/mandants/:id',
                    reason: 'The detail page id is dynamic and seeded at runtime — deep-link semantics (a user arrives here from the list row link).',
                },
            ],
            seed: () => seedPrimaryMandantId(),
        },
        {
            name: 'admin-categories',
            path: '/admin/categories',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Kategorien' }],
            seed: () => ensurePrimaryMandantAccreditation(),
        },
        {
            name: 'admin-events',
            path: '/admin/events',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Events' }],
            seed: () => ensurePrimaryMandantActivePortalEvent(),
        },
        {
            name: 'admin-accreditations',
            path: '/admin/accreditations',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Akkreditierungen' }],
            seed: () => ensurePrimaryMandantAccreditation(),
        },
        {
            name: 'admin-freigaben',
            path: '/admin/freigaben',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Freigaben' }],
            seed: () => seedFreigabenFilled(),
        },
        {
            name: 'admin-users',
            path: '/admin/users',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Benutzer' }],
            seed: () => seedUsersFilled(),
        },
        {
            name: 'admin-badge-templates',
            path: '/admin/badge-templates',
            states: ['filled', 'empty'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' }],
            seed: () => seedBadgeTemplatesFilled(),
        },
    ],
};

export const routes = uiReviewConfig.routes;
