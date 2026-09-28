import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { request } from '@playwright/test';
import type { APIRequestContext } from '@playwright/test';
import {
    FRONTEND_BASE_URL,
    acquirePrimaryMandantLogoLock,
    allocateAccreditationApi,
    loginAdminApi,
    resetPrimaryMandantLogo,
} from '../../e2e/helpers/admin-data';
import { MailpitHelper } from '../../e2e/helpers/mailpit';
import {
    ALL_REVIEW_NAMES,
    ALL_REVIEW_SLUGS,
    REVIEW_FIXTURE_NAMES,
    REVIEW_FIXTURE_SLUGS,
    REVIEW_USER_EMAILS,
    REVIEW_USER_PREFIX,
    REVIEW_VENUE_NAMES,
} from './fixture-names';
import { pngFixture } from './png-fixtures';
import { captureStoreRoot } from './store-paths';

/**
 * The ONE fixed dataset a ui-review run is captured against.
 *
 * ## Why: the captures were not reproducible at all
 *
 * MEASURED, three runs of unchanged code on this machine: 35 → 45 → 66 section
 * bands. The page heights behind them grew the same way (`home`/mobile 3586 →
 * 3814 → 5112 CSS px) because the seeds *create* instead of *ensure*:
 * `ensurePrimaryMandantAccreditation()` mints a fresh category + event +
 * accreditation per call, the badge-template seeds create a row per call, and
 * every route that needs a user registers one. The dev DB carried 210 users and
 * 14 badge templates when this file was written, and nothing ever gave them
 * back — `purgeAllE2EArtifacts` lives in the E2E suite's `globalTeardown`, which
 * `playwright.screenshots.config.ts` does not have.
 *
 * A design-QA pass whose result depends on how often it ran is half a pass, and
 * worse: the first vision loop of that session judged one particular data state
 * and could not be reproduced afterwards.
 *
 * ## What this fixes, and how
 *
 * **1. Reset, once per run, under a lock — of THIS suite's rows only.** The old
 * version called the E2E suite's `purgeAllE2EArtifacts()`, which meant a
 * design-QA run silently deleted whatever the functional suite had left in the
 * shared dev database, while the functional suite's own teardown deleted the
 * review's rows in return (MEASURED: one full E2E run took the review's badge
 * templates from 321/322 to 0, and one screenshot run broke the next E2E run at
 * `badge.spec.ts:70` with a StrictMode violation). `purgeReviewFixtures()` below
 * replaces it: same cascade behaviour, own namespace only. The namespaces are
 * proved disjoint by `tests/e2e/namespace-isolation.spec.ts`, so the two suites
 * can no longer reach each other's rows.
 *
 * **2. Fixed-point fixtures, so a run converges instead of accumulating.** Every
 * fixture below is *find-or-create under a stable name*, so the second run of a
 * clean checkout produces the same rows with the same names and the captures are
 * comparable run over run. Only the deadline dates move, and they must — see
 * `deadlineInDays`.
 *
 * **3. A recorded fingerprint.** `fingerprint` counts what the captures were
 * rendered against and lands in every capture's `.meta.json`, so a review batch
 * can prove which data state it judged.
 *
 * ## What this deliberately does NOT do
 *
 * **Users are not purged** — there is no delete route for them
 * (`/api/admin/users` has GET + PUT roles only), and 210 were already in the dev
 * DB, mostly from the E2E suite. Deleting them is a backend concern, so the leak
 * is closed the only way available here: the review's own users are created ONCE
 * under a stable address and reused, which turns an ever-growing list into a
 * fixed point. The E2E suite's own leak and the missing API are a separate
 * board position, deliberately NOT worked around here.
 *
 * The one exception to "own namespace only" is `resetPrimaryMandantLogo()`,
 * which is a namespace-free primitive (the primary mandant's logo carries no E2E
 * name marker at all) and is what guarantees the documented "no uploaded logo"
 * baseline of the `admin-mandants` / `admin-media` captures.
 */

/** Stable user addresses live in `fixture-names.ts`; re-exported for the seeds. */
const USER_PASSWORD = 'SecurePassw0rd!';

/** Stable fixture names — see `fixture-names.ts` for the namespace contract. */
const REVIEW_CATEGORY_SLUG = REVIEW_FIXTURE_SLUGS.category;
const REVIEW_CATEGORY_NAME = REVIEW_FIXTURE_NAMES.category;
const REVIEW_EVENT_TITLE = REVIEW_FIXTURE_NAMES.event;
const PRINT_CATEGORY_SLUG = REVIEW_FIXTURE_SLUGS.categoryPrint;
const PRINT_CATEGORY_NAME = REVIEW_FIXTURE_NAMES.categoryPrint;
const PRINT_EVENT_TITLE = REVIEW_FIXTURE_NAMES.eventPrint;
const PORTAL_EVENT_TITLE = REVIEW_FIXTURE_NAMES.portalEvent;
const PORTAL_COMPETITION = REVIEW_FIXTURE_NAMES.competition;
const TEAM_NAME = REVIEW_FIXTURE_NAMES.team;
const TEAM_SLUG = REVIEW_FIXTURE_SLUGS.team;
const TEMPLATE_LIST_NAME = REVIEW_FIXTURE_NAMES.templateList;
const TEMPLATE_EDITOR_NAME = REVIEW_FIXTURE_NAMES.templateEditor;

/** Stable user addresses. `.test` is the reserved TLD the E2E helpers already use. */
const USER_REVIEWER = REVIEW_USER_EMAILS.reviewer;
const USER_APPLICANT = REVIEW_USER_EMAILS.applicant;
const USER_APPLY = REVIEW_USER_EMAILS.apply;
const USER_FREIGABEN = REVIEW_USER_EMAILS.freigaben;
const USER_EMPTY = REVIEW_USER_EMAILS.empty;
const USER_PRINT = REVIEW_USER_EMAILS.print;

export interface ReviewCredentials {
    email: string;
    password: string;
}

interface MandantRow {
    id: number;
    name: string;
    is_primary: boolean;
    teams_enabled: boolean;
}

interface CategoryRow {
    id: number;
    name: string;
    slug: string;
}

interface EventRow {
    id: number;
    title: string;
    team_id: number | null;
}

interface TeamRow {
    id: number;
    name: string;
}

interface VenueRow {
    id: number;
    name: string;
    is_active: boolean;
}

interface AccreditationRow {
    id: number;
    category_id: number;
}

interface BadgeTemplateRow {
    id: number;
    name: string;
}

interface AdminUserRow {
    id: number;
    email: string;
}

interface ApplicationRow {
    id: number;
    /** `AdminApplicationResource` nests the applicant; null only for a deleted user. */
    user: { id: number; email: string; name: string } | null;
    status: string;
    /** Only set for approved applications. */
    qr_url: string | null;
}

interface MediaRow {
    type: string;
}

/** The complete fixture a run is captured against. See the module docblock. */
export interface UiReviewDataset {
    /** Run identity: the shared parent process id (see `uiReviewRunKey`). */
    runKey: string;
    builtAt: string;
    primaryMandantId: number;
    /** The shared event-scoped accreditation: public list, apply page, approvals. */
    accreditation: { id: number; categoryId: number; categoryName: string };
    /** The portal calendar event: home, event detail. */
    portalEvent: { id: number; title: string; teamId: number; mandantName: string };
    badgeTemplates: {
        /** The list route's row (legacy three-field layout). */
        list: BadgeTemplateRow;
        /** The schema-v2 layout the editor routes show AND the print check renders. */
        editor: BadgeTemplateRow;
    };
    users: {
        reviewer: ReviewCredentials;
        applicant: ReviewCredentials;
        apply: ReviewCredentials;
        freigaben: ReviewCredentials;
        empty: ReviewCredentials;
    };
    /** Own accreditation + approved applications, so the export has badges to render. */
    print: {
        accreditationId: number;
        applications: Array<{ id: number; token: string; email: string }>;
    };
    /** Entity counts the captures were rendered against. */
    fingerprint: Record<string, number>;
}

let datasetPromise: Promise<UiReviewDataset> | null = null;

/**
 * Identity of the current capture run, shared by every worker process of it.
 *
 * MEASURED: both workers of one run report the same `process.ppid` (the
 * Playwright runner) with distinct pids, so the parent id is exactly "this run".
 * The harness is macOS/Linux-only anyway (`pdf-to-png-vision.sh`, the E2E
 * scripts), where `process.ppid` always exists; the fallback exists only so the
 * degraded case is loud instead of silent.
 */
export function uiReviewRunKey(): string {
    if (typeof process.ppid === 'number' && process.ppid > 0) {
        return `run-${process.ppid}`;
    }
    console.warn(
        '[ui-review] process.ppid unavailable — the dataset reset degenerates to per-process and ' +
            'parallel workers may reset the database while another worker captures.',
    );
    return `process-${process.pid}`;
}

// Inside the capture store, and DERIVED from it: a lock outside the store would be
// wiped by `--output` while the run that held it is still capturing.
const LOCK_FILE = path.join(captureStoreRoot(), '.dataset.lock');
/** How long a waiter gives up. Comfortably above the stale window below. */
const LOCK_TIMEOUT_MS = 180000;
/**
 * A lock older than this belongs to a process that died mid-build. The healthy
 * critical section is the reset plus the fixture build — seconds, measured at
 * well under 10 s on a warm dev DB — so 60 s is generous, and it must stay BELOW
 * `LOCK_TIMEOUT_MS`, otherwise a dead holder would wedge the run until the
 * timeout instead of being reclaimed.
 */
const LOCK_STALE_MS = 60000;

function isErrnoException(error: unknown, code: string): boolean {
    return error instanceof Error && 'code' in error && error.code === code;
}

function unlinkIfPresent(file: string): void {
    try {
        fs.unlinkSync(file);
    } catch (error) {
        if (!isErrnoException(error, 'ENOENT')) {
            throw error;
        }
    }
}

/**
 * Serialises the dataset build across worker processes: plain exclusive-create,
 * the same technique as the E2E logo mutex. The critical section is the reset
 * plus the fixture build (seconds), so a lock older than the stale window is
 * reclaimed instead of being handled with the logo lock's steal-and-verify dance.
 */
async function withDatasetLock<T>(critical: () => Promise<T>): Promise<T> {
    fs.mkdirSync(path.dirname(LOCK_FILE), { recursive: true });
    const deadline = Date.now() + LOCK_TIMEOUT_MS;
    for (;;) {
        try {
            fs.writeFileSync(LOCK_FILE, `${process.pid} ${new Date().toISOString()}\n`, { flag: 'wx' });
            break;
        } catch (error) {
            if (!isErrnoException(error, 'EEXIST')) {
                throw error;
            }
            let ageMs: number | null;
            try {
                ageMs = Date.now() - fs.statSync(LOCK_FILE).mtimeMs;
            } catch (statError) {
                if (isErrnoException(statError, 'ENOENT')) {
                    continue;
                }
                throw statError;
            }
            if (ageMs > LOCK_STALE_MS) {
                console.warn(`[ui-review] reclaiming stale dataset lock (age ${ageMs}ms)`);
                unlinkIfPresent(LOCK_FILE);
                continue;
            }
            if (Date.now() > deadline) {
                throw new Error(`Timed out after ${LOCK_TIMEOUT_MS}ms waiting for the ui-review dataset lock`);
            }
            await new Promise((resolve) => {
                setTimeout(resolve, 100);
            });
        }
    }
    try {
        return await critical();
    } finally {
        unlinkIfPresent(LOCK_FILE);
    }
}

/**
 * Where the built dataset of a run is recorded, so the SECOND worker of that run
 * reads it instead of building it again.
 *
 * Without this, "build once per run" would only be true per process: worker A
 * resets + builds, releases the lock, and worker B — which has its own process
 * memo — resets again, deleting the applications A had just seeded and
 * re-applying them. The captures would then be rendered against rows that moved
 * underneath them, which is exactly the class of nondeterminism this file exists
 * to remove. The marker is keyed by the run (`runKey`) and lives INSIDE the
 * capture store, which is not wiped by Playwright (see `capture-store.ts`), so it
 * survives for the run and is ignored by the next one.
 */
const DATASET_FILE = path.join(captureStoreRoot(), '.dataset.json');

async function readList<T>(api: APIRequestContext, url: string): Promise<T[]> {
    const body = (await (await api.get(url)).json()) as { data?: T[] };
    return body.data ?? [];
}

function assertStatus(response: { status: () => number }, what: string, expected: number[]): void {
    if (!expected.includes(response.status())) {
        throw new Error(`${what} failed with status ${response.status()}`);
    }
}

/**
 * A deadline/date N days out, as `YYYY-MM-DD`.
 *
 * Relative, not a pinned constant, on purpose: a pinned date goes stale and the
 * accreditation silently turns inactive (or the portal drops the event), which
 * would break every capture instead of one. The consequence is that the DATE
 * fields inside the captures legitimately differ from day to day — the page
 * heights and band counts do not, which is what the reproducibility check
 * compares.
 */
async function dateInDays(days: number): Promise<string> {
    const date = new Date();
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

/**
 * Reset before the run — the REVIEW's rows, and only those.
 *
 * Replaces the previous `purgeAllE2EArtifacts()` call, which was a mutual
 * destruction waiting to happen: the E2E suite's serial teardown reclaims
 * `E2E Ausweis*` templates and `E2E Akkreditierung *` categories, and the
 * review's fixtures used to live under exactly those names (MEASURED: one full
 * E2E run took the review's templates from 321/322 to 0; one screenshot run
 * broke the following E2E run at `badge.spec.ts:70`). The namespaces are now
 * disjoint — `fixture-names.ts` owns them, `tests/e2e/namespace-isolation.spec.ts`
 * proves it — so this sweep is precise instead of mandant-wide:
 *
 * - categories by SLUG (deleting one cascades to its accreditations, their
 *   applications and their sub-accreditations — the bulk of the dataset),
 * - badge templates by their exact stable name,
 * - events by their exact stable title,
 * - the team and both venues by their exact stable name.
 *
 * `resetPrimaryMandantLogo()` is the one deliberate exception: the primary
 * mandant's logo carries NO name marker at all, so it is nobody's namespace, and
 * the `admin-mandants` / `admin-media` captures document the "no uploaded logo"
 * baseline as part of their fixture.
 *
 * ## Why the logo reset is taken UNDER THE LOGO MUTEX (F3)
 *
 * That exception is the one place this sweep writes a row the functional suite
 * also writes, so it is the one place it needs the functional suite's mutex.
 * `acquirePrimaryMandantLogoLock()` is already taken by `admin-mandant.spec.ts`
 * (around its upload), by `portal.spec.ts` (around its "no logo" assertion) and
 * by the E2E `globalSetup`; `purgeReviewFixtures()` was the only caller of
 * `resetPrimaryMandantLogo()` that took **none** of them, so a screenshot run
 * beside a live E2E run could delete the logo out from under the upload test's
 * open file dialog.
 *
 * **Where the lock is held, stated precisely — this is not the dataset lock.**
 * `withDatasetLock()` (the `.dataset.lock` file in the capture store) serialises
 * the review harness against ITSELF: worker B waits for worker A's fixture build
 * so the two do not reset each other's rows. It has never had anything to say
 * about the E2E suite, and it cannot: the two harnesses have separate processes,
 * separate configs and separate lock files. The logo mutex is a DIFFERENT lock —
 * an exclusive-create file in `os.tmpdir()` — and it is the only artefact the
 * two suites share. Taking it here is what extends the exclusion from
 * "review vs review" to "review vs E2E", which is precisely the gap F3 names.
 *
 * The residual is stated rather than papered over: the mutex orders the logo
 * against a *concurrent* writer, not against a run that starts afterwards. An
 * E2E `globalSetup` that lands after this sweep still resets the same row, but
 * that is the idempotent half (both call sites are the same "restore the seeded
 * no-logo state" operation), not a delete of somebody's upload.
 *
 * The fixtures of the PREVIOUS namespace do not need a legacy sweep: they were
 * named `E2E Akkreditierung …` / `E2E Ausweis…` / `E2E Heimverein …`, so the next
 * full E2E run's teardown reclaims them. This harness stops touching them.
 */
async function purgeReviewFixtures(api: APIRequestContext): Promise<void> {
    // Under the SAME mutex the E2E logo writers take, and released in a `finally`
    // so a throw below cannot strand the lock (a stranded lock is only reclaimed
    // after `LOGO_LOCK_STALE_MS`, which would stall the other suite's upload
    // test for 30 s). See the docblock above for why this call needs a lock that
    // `withDatasetLock` does not provide.
    const releaseLogoLock = await acquirePrimaryMandantLogoLock();
    try {
        await resetPrimaryMandantLogo();
    } finally {
        releaseLogoLock();
    }

    for (const template of await readList<BadgeTemplateRow>(api, '/api/admin/badge-templates')) {
        if (ALL_REVIEW_NAMES.includes(template.name)) {
            const removed = await api.delete(`/api/admin/badge-templates/${template.id}`);
            assertStatus(removed, `Removing review badge template ${template.name}`, [200, 204]);
        }
    }

    for (const category of await readList<CategoryRow>(api, '/api/admin/categories')) {
        if (ALL_REVIEW_SLUGS.includes(category.slug)) {
            const removed = await api.delete(`/api/admin/categories/${category.id}`);
            assertStatus(removed, `Removing review category ${category.slug}`, [200, 204]);
        }
    }

    for (const event of await readList<EventRow>(api, '/api/admin/events')) {
        if (ALL_REVIEW_NAMES.includes(event.title)) {
            const removed = await api.delete(`/api/admin/events/${event.id}`);
            assertStatus(removed, `Removing review event ${event.title}`, [200, 204]);
        }
    }

    // Teams and venues last: an event or a team still referencing one is refused
    // with a 409, so they have to go after their referrers.
    const mandants = await readList<MandantRow>(api, '/api/admin/mandants');
    const mandant = mandants.find((row) => row.is_primary) ?? mandants[0];
    if (mandant === undefined) {
        throw new Error('No mandant found for the ui-review purge');
    }
    for (const team of await readList<TeamRow>(api, `/api/admin/mandants/${mandant.id}/teams`)) {
        if (team.name === TEAM_NAME) {
            const removed = await api.delete(`/api/admin/mandants/${mandant.id}/teams/${team.id}`);
            assertStatus(removed, `Removing review team ${team.name}`, [200, 204]);
        }
    }
    for (const venue of await readList<VenueRow>(api, '/api/admin/venues')) {
        if (Object.values(REVIEW_VENUE_NAMES).includes(venue.name)) {
            const removed = await api.delete(`/api/admin/venues/${venue.id}`);
            assertStatus(removed, `Removing review venue ${venue.name}`, [200, 204]);
        }
    }
}

/**
 * Registers + activates the stable review user if it does not exist yet, logs it
 * in, and hands a logged-in context to `afterLogin`. Returns its credentials.
 *
 * Find-or-create by a STABLE address is what keeps the user list from growing:
 * the review's users are created on the first run and reused forever after (the
 * rows survive the reset — there is no delete route — while their applications
 * do not, so `afterLogin` re-applies them on every run).
 */
async function withReviewUser(
    email: string,
    name: string,
    afterLogin?: (api: APIRequestContext) => Promise<void>,
): Promise<ReviewCredentials> {
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        let login = await api.post('/api/auth/login', { data: { email, password: USER_PASSWORD } });
        if (login.status() !== 200) {
            const register = await api.post('/api/auth/register', {
                data: { name, email, password: USER_PASSWORD, password_confirmation: USER_PASSWORD },
            });
            if (register.status() !== 201) {
                throw new Error(
                    `Review user ${email} exists but is not usable (login ${login.status()}, ` +
                        `register ${register.status()}): delete it in the admin to recover.`,
                );
            }
            const mailpit = new MailpitHelper();
            const activationPath = await mailpit.extractActivationPath(email);
            const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
            assertStatus(activation, `Activation of ${email}`, [200]);
            login = await api.post('/api/auth/login', { data: { email, password: USER_PASSWORD } });
            assertStatus(login, `Login of ${email} after activation`, [200]);
        }
        await afterLogin?.(api);
        return { email, password: USER_PASSWORD };
    } finally {
        await api.dispose();
    }
}

async function applyForAccreditation(api: APIRequestContext, accreditationId: number): Promise<void> {
    const apply = await api.post(`/api/accreditations/${accreditationId}/apply`);
    // 422 is "already applied": after a reset the application is gone, so it can
    // only happen when a previous run of this harness died before its reset — and
    // then the state is already what the capture needs. `assertApplications`
    // verifies the expected application exists either way, so a swallowed 422
    // cannot hide a genuinely missing one.
    assertStatus(apply, `Apply for accreditation ${accreditationId}`, [200, 201, 422]);
}

/** Uploads the portrait exactly once per user (the endpoint replaces a `portrait`). */
async function ensurePortrait(api: APIRequestContext): Promise<void> {
    const media = await readList<MediaRow>(api, '/api/user/media');
    if (media.some((row) => row.type === 'portrait')) {
        return;
    }
    const upload = await api.post('/api/user/media', {
        multipart: {
            type: 'portrait',
            file: {
                name: 'portrait.png',
                mimeType: 'image/png',
                buffer: pngFixture('portrait-probe'),
            },
        },
    });
    assertStatus(upload, 'Portrait upload', [201]);
}

/**
 * One event-scoped accreditation (category + event + accreditation), found by its
 * stable slug or created. Same shape as the E2E helper
 * `ensurePrimaryMandantAccreditation`, with the difference that matters here:
 * that one mints `uniqueSuffix()` names per call, which is right for parallel E2E
 * specs and fatal for a review that has to be reproducible.
 */
async function ensureEventAccreditation(
    api: APIRequestContext,
    names: { slug: string; categoryName: string; eventTitle: string },
): Promise<{ id: number; categoryId: number; categoryName: string }> {
    let categoryId = (await readList<CategoryRow>(api, '/api/admin/categories')).find(
        (row) => row.slug === names.slug,
    )?.id;
    if (categoryId === undefined) {
        const created = await api.post('/api/admin/categories', {
            data: { name: names.categoryName, slug: names.slug },
        });
        assertStatus(created, `Creating category ${names.slug}`, [201]);
        categoryId = ((await created.json()) as { data: CategoryRow }).data.id;
    }

    let eventId = (await readList<EventRow>(api, '/api/admin/events')).find(
        (row) => row.title === names.eventTitle,
    )?.id;
    if (eventId === undefined) {
        const created = await api.post('/api/admin/events', {
            data: { title: names.eventTitle, active: true },
        });
        assertStatus(created, `Creating event ${names.eventTitle}`, [201]);
        eventId = ((await created.json()) as { data: EventRow }).data.id;
    }

    for (const accreditation of await readList<AccreditationRow>(api, '/api/admin/accreditations')) {
        if (accreditation.category_id === categoryId) {
            return { id: accreditation.id, categoryId, categoryName: names.categoryName };
        }
    }
    const created = await api.post('/api/admin/accreditations', {
        data: {
            category_id: categoryId,
            scope: 'event',
            event_id: eventId,
            quota: 5,
            deadline_start: await dateInDays(-5),
            deadline_end: await dateInDays(30),
            auto_approve: false,
            active: true,
        },
    });
    assertStatus(created, 'Creating a review accreditation', [201]);
    return {
        id: ((await created.json()) as { data: AccreditationRow }).data.id,
        categoryId,
        categoryName: names.categoryName,
    };
}

/**
 * The review's OWN two venues, found by their stable names or created.
 *
 * The harness used to call the E2E helper `ensurePrimaryMandantHasVenues()`,
 * which creates `E2E Heimstadion` / `E2E Portal Arena` — the E2E suite's rows, in
 * the E2E suite's namespace (`E2E ` is a venue purge marker). So the review's
 * portal event pointed at rows the functional suite's serial teardown deletes,
 * and the `home` capture's venue name was at the mercy of another suite's
 * cleanup. Owning the two rows is what makes `home` reproducible.
 *
 * Deactivated rows are reactivated rather than duplicated, exactly like the E2E
 * helper: a second `POST` of an existing name is refused with a 422 anyway.
 */
async function ensureReviewVenues(): Promise<{ home: VenueRow; portal: VenueRow }> {
    // `loginAdminApi()`, not a bare context: the venue routes are admin-gated, and
    // an unauthenticated context answers 401 — which is exactly what a first
    // version of this function did.
    const api = await loginAdminApi();
    try {
        const existing = await readList<VenueRow>(api, '/api/admin/venues');
        const resolved = new Map<string, VenueRow>();
        for (const name of [REVIEW_VENUE_NAMES.home, REVIEW_VENUE_NAMES.portal]) {
            const match = existing.find((venue) => venue.name === name);
            if (match === undefined) {
                const created = await api.post('/api/admin/venues', { data: { name } });
                assertStatus(created, `Creating review venue ${name}`, [201]);
                resolved.set(name, ((await created.json()) as { data: VenueRow }).data);
                continue;
            }
            if (!match.is_active) {
                const reactivated = await api.put(`/api/admin/venues/${match.id}`, { data: { is_active: true } });
                assertStatus(reactivated, `Reactivating review venue ${name}`, [200]);
                resolved.set(name, ((await reactivated.json()) as { data: VenueRow }).data);
                continue;
            }
            resolved.set(name, match);
        }
        const home = resolved.get(REVIEW_VENUE_NAMES.home);
        const portal = resolved.get(REVIEW_VENUE_NAMES.portal);
        if (home === undefined || portal === undefined) {
            throw new Error('Review venues were not resolved');
        }
        return { home, portal };
    } finally {
        await api.dispose();
    }
}

/**
 * One ACTIVE portal event with the review's own team and venue, found by its
 * stable title or created. The portal calendar renders it, so its determinism is
 * what keeps the `home` captures comparable.
 *
 * The team is resolved by the review's stable NAME, not by "the mandant's first
 * team": `teams[0]` is whatever a previous run or the E2E suite left behind, so
 * the fixture it returned was never the fixture the capture was documented to
 * show.
 */
async function ensurePortalEvent(
    api: APIRequestContext,
    mandant: MandantRow,
): Promise<{ id: number; title: string; teamId: number; mandantName: string }> {
    const events = await readList<EventRow>(api, '/api/admin/events');
    const existing = events.find((row) => row.title === PORTAL_EVENT_TITLE);
    if (existing !== undefined) {
        if (existing.team_id !== null) {
            return { id: existing.id, title: existing.title, teamId: existing.team_id, mandantName: mandant.name };
        }
        // A team-less leftover from a crashed run cannot render on the portal, so
        // it is replaced instead of being counted as a second calendar entry.
        await api.delete(`/api/admin/events/${existing.id}`);
    }

    if (!mandant.teams_enabled) {
        const enable = await api.put(`/api/admin/mandants/${mandant.id}`, { data: { teams_enabled: true } });
        assertStatus(enable, 'Enabling teams', [200]);
    }
    const venues = await ensureReviewVenues();
    const teams = await readList<TeamRow>(api, `/api/admin/mandants/${mandant.id}/teams`);
    let teamId = teams.find((row) => row.name === TEAM_NAME)?.id;
    if (teamId === undefined) {
        const created = await api.post(`/api/admin/mandants/${mandant.id}/teams`, {
            data: { name: TEAM_NAME, slug: TEAM_SLUG, venue_id: venues.home.id },
        });
        assertStatus(created, `Creating the review team ${TEAM_NAME}`, [201]);
        teamId = ((await created.json()) as { data: { id: number } }).data.id;
    }

    const portalVenue = venues.portal;
    const created = await api.post('/api/admin/events', {
        data: {
            title: PORTAL_EVENT_TITLE,
            team_id: teamId,
            date: await dateInDays(60),
            venue_id: portalVenue.id,
            competition: PORTAL_COMPETITION,
            deadline_start: await dateInDays(20),
            deadline_end: await dateInDays(30),
            active: true,
        },
    });
    assertStatus(created, `Creating the portal event ${PORTAL_EVENT_TITLE}`, [201]);
    const event = ((await created.json()) as { data: EventRow }).data;
    return { id: event.id, title: event.title, teamId, mandantName: mandant.name };
}

/**
 * The complete schema-v2 layout (FE3): photo top-left, qr top-right, seven data
 * fields with size + align. Non-overlapping and inside the A6 bounds
 * (105 × 148 mm). It is the mandant's DEFAULT template, so even the export path
 * without an explicit `template_id` renders the layout the editor routes show;
 * the print check passes the id explicitly as well, so both paths are pinned.
 */
const SCHEMA_V2_LAYOUT: unknown[] = [
    { field: 'photo', x: 8, y: 8, w: 22, h: 28, size: 12, align: 'left' },
    { field: 'qr', x: 78, y: 8, w: 20, h: 20 },
    { field: 'name', x: 35, y: 8, w: 40, h: 8, size: 14, align: 'left' },
    { field: 'category', x: 35, y: 18, w: 40, h: 6, size: 10, align: 'left' },
    { field: 'event', x: 35, y: 26, w: 40, h: 6, size: 9, align: 'left' },
    { field: 'date', x: 35, y: 34, w: 40, h: 6, size: 9, align: 'left' },
    { field: 'status', x: 8, y: 42, w: 30, h: 6, size: 9, align: 'left' },
    { field: 'team', x: 8, y: 50, w: 60, h: 6, size: 9, align: 'left' },
    { field: 'vest_number', x: 8, y: 58, w: 30, h: 6, size: 9, align: 'left' },
];

/** The legacy three-field layout — only so the list route shows a second row shape. */
const LEGACY_LAYOUT: unknown[] = [
    { field: 'name', x: 5, y: 5, w: 50, h: 10, size: 12, align: 'left' },
    { field: 'category', x: 5, y: 20, w: 50, h: 10, size: 10, align: 'left' },
    { field: 'date', x: 5, y: 35, w: 50, h: 10, size: 10, align: 'left' },
];

async function ensureBadgeTemplate(
    api: APIRequestContext,
    name: string,
    layout: unknown[],
    isDefault: boolean,
): Promise<BadgeTemplateRow> {
    const existing = (await readList<BadgeTemplateRow>(api, '/api/admin/badge-templates')).find(
        (row) => row.name === name,
    );
    if (existing !== undefined) {
        return existing;
    }
    const created = await api.post('/api/admin/badge-templates', { data: { name, layout, is_default: isDefault } });
    assertStatus(created, `Creating badge template ${name}`, [201]);
    return (await created.json()).data;
}

async function fingerprint(api: APIRequestContext): Promise<Record<string, number>> {
    const [users, categories, events, accreditations, templates, applications] = await Promise.all([
        readList<AdminUserRow>(api, '/api/admin/users'),
        readList<CategoryRow>(api, '/api/admin/categories'),
        readList<EventRow>(api, '/api/admin/events'),
        readList<AccreditationRow>(api, '/api/admin/accreditations'),
        readList<BadgeTemplateRow>(api, '/api/admin/badge-templates'),
        readList<ApplicationRow>(api, '/api/admin/applications'),
    ]);
    return {
        users: users.length,
        reviewUsers: users.filter((row) => row.email.startsWith(`${REVIEW_USER_PREFIX}-`)).length,
        categories: categories.length,
        events: events.length,
        accreditations: accreditations.length,
        badgeTemplates: templates.length,
        applications: applications.length,
    };
}

/**
 * Postcondition of the fixture build: every application the captures rely on is
 * really there, under the expected applicant, with the expected status. A seed
 * that silently did nothing is worse than a failing one — it turns a "filled"
 * capture into an empty state that nobody notices.
 */
async function assertApplications(
    api: APIRequestContext,
    accreditationId: number,
    expected: Array<{ email: string; status: string }>,
): Promise<void> {
    const applications = await readList<ApplicationRow>(
        api,
        `/api/admin/applications?accreditation_id=${accreditationId}`,
    );
    const missing = expected.filter(
        (want) => !applications.some((row) => row.user?.email === want.email && row.status === want.status),
    );
    if (missing.length > 0) {
        throw new Error(
            `Dataset postcondition failed for accreditation ${accreditationId}: missing ` +
                `${missing.map((row) => `${row.status}@${row.email}`).join(', ')} ` +
                `(applications present: ${applications.length})`,
        );
    }
}

async function buildDataset(): Promise<UiReviewDataset> {
    const runKey = uiReviewRunKey();
    const api = await loginAdminApi();
    try {
        const mandants = await readList<MandantRow>(api, '/api/admin/mandants');
        const mandant = mandants.find((row) => row.is_primary) ?? mandants[0];
        if (mandant === undefined) {
            throw new Error('No mandant found for the ui-review dataset');
        }

        await purgeReviewFixtures(api);

        const accreditation = await ensureEventAccreditation(api, {
            slug: REVIEW_CATEGORY_SLUG,
            categoryName: REVIEW_CATEGORY_NAME,
            eventTitle: REVIEW_EVENT_TITLE,
        });
        const printAccreditation = await ensureEventAccreditation(api, {
            slug: PRINT_CATEGORY_SLUG,
            categoryName: PRINT_CATEGORY_NAME,
            eventTitle: PRINT_EVENT_TITLE,
        });
        const portalEvent = await ensurePortalEvent(api, mandant);

        const listTemplate = await ensureBadgeTemplate(api, TEMPLATE_LIST_NAME, LEGACY_LAYOUT, false);
        const editorTemplate = await ensureBadgeTemplate(api, TEMPLATE_EDITOR_NAME, SCHEMA_V2_LAYOUT, true);

        const users = {
            // The admin users list only needs a mandant-scoped member.
            reviewer: await withReviewUser(USER_REVIEWER, 'E2E Reviewer'),
            applicant: await withReviewUser(USER_APPLICANT, 'E2E Antragsteller Screenshot', (userApi) =>
                applyForAccreditation(userApi, accreditation.id),
            ),
            apply: await withReviewUser(USER_APPLY, 'E2E Bewerber Screenshot', (userApi) =>
                applyForAccreditation(userApi, accreditation.id),
            ),
            freigaben: await withReviewUser(USER_FREIGABEN, 'E2E Freigaben Antrag', (userApi) =>
                applyForAccreditation(userApi, accreditation.id),
            ),
            // Applies for NOTHING on purpose: the "Meine Akkreditierungen" empty state.
            empty: await withReviewUser(USER_EMPTY, 'E2E Leerer Nutzer'),
        };

        // The print fixture gets its OWN accreditation, so approving its
        // applications can never promote the requested applications that the
        // approvals list captures depend on.
        for (const [index, email] of USER_PRINT.entries()) {
            await withReviewUser(email, `E2E Druck Inhaber ${index + 1}`, async (userApi) => {
                await ensurePortrait(userApi);
                await applyForAccreditation(userApi, printAccreditation.id);
            });
        }
        await allocateAccreditationApi(printAccreditation.id, 'all');

        const printApplications = (
            await readList<ApplicationRow>(api, `/api/admin/applications?accreditation_id=${printAccreditation.id}`)
        )
            .filter((row) => row.status === 'approved')
            .map((row) => ({
                id: row.id,
                email: row.user?.email ?? `user#${row.id}`,
                token: (row.qr_url ?? '').split('/').pop() ?? '',
            }));
        if (printApplications.length !== USER_PRINT.length) {
            throw new Error(
                `Dataset postcondition failed: expected ${USER_PRINT.length} approved print applications, ` +
                    `got ${printApplications.length}`,
            );
        }
        const tokenless = printApplications.filter((row) => row.token === '');
        if (tokenless.length > 0) {
            throw new Error(
                `Dataset postcondition failed: print application(s) without qr token: ` +
                    `${tokenless.map((row) => row.id).join(', ')}`,
            );
        }

        await assertApplications(api, accreditation.id, [
            { email: users.applicant.email, status: 'requested' },
            { email: users.apply.email, status: 'requested' },
            { email: users.freigaben.email, status: 'requested' },
        ]);

        return {
            runKey,
            builtAt: new Date().toISOString(),
            primaryMandantId: mandant.id,
            accreditation,
            portalEvent,
            badgeTemplates: { list: listTemplate, editor: editorTemplate },
            users,
            print: { accreditationId: printAccreditation.id, applications: printApplications },
            fingerprint: await fingerprint(api),
        };
    } finally {
        await api.dispose();
    }
}

/**
 * The run's dataset, built once and shared by every worker process.
 *
 * Per-process memoisation plus the cross-process lock plus the shared run key is
 * what makes "exactly one build per run" true: the first worker in builds it
 * under the lock, the second waits and then reads the same result — so both
 * workers' captures are rendered against the same rows.
 */
/** Reads the run's dataset if a previous worker of the SAME run already built it. */
function readRecordedDataset(runKey: string): UiReviewDatasetArtifact | null {
    try {
        const parsed = JSON.parse(fs.readFileSync(DATASET_FILE, 'utf8')) as UiReviewDatasetArtifact;
        // A marker from a FINISHED run must never be reused: its ids died with
        // that run's reset.
        return parsed.runKey === runKey ? parsed : null;
    } catch (error) {
        if (isErrnoException(error, 'ENOENT') || error instanceof SyntaxError) {
            return null;
        }
        throw error;
    }
}

/**
 * What the run's dataset file is allowed to contain: everything EXCEPT the two
 * secret classes.
 *
 * The file exists so the second worker of a run can reuse the first worker's
 * build instead of resetting the database under it. That contract needs the row
 * ids, the fixture names, the run key and the fingerprint — it does not need the
 * passwords, and the QR tokens are only *derived* data that the reader can fetch
 * itself (see `rehydrateSecrets`).
 *
 * MEASURED before the change: the file carried 5 cleartext passwords
 * (`SecurePassw0rd!`) and 2 QR verification tokens. `test-results/` is
 * gitignored, so nothing was ever committed — but the directory is exactly what
 * gets handed to a reviewer or a `vision` subagent as a batch, and a password
 * does not belong in it. So the secret classes are now *structurally* absent:
 * `recordDataset` cannot write them, and `assertNoSecretsInArtifact` throws if a
 * future field smuggles one in.
 */
interface UiReviewDatasetArtifact {
    runKey: string;
    builtAt: string;
    primaryMandantId: number;
    accreditation: UiReviewDataset['accreditation'];
    portalEvent: UiReviewDataset['portalEvent'];
    badgeTemplates: { list: BadgeTemplateRow; editor: BadgeTemplateRow };
    /** Addresses only — the password is re-derived from `USER_PASSWORD`. */
    users: Record<keyof UiReviewDataset['users'], { email: string }>;
    print: { accreditationId: number; applications: Array<{ id: number; email: string }> };
    fingerprint: Record<string, number>;
}

/** Reduces the in-memory dataset to the fields the artifact may carry. */
function datasetForArtifact(dataset: UiReviewDataset): UiReviewDatasetArtifact {
    const address = (credentials: ReviewCredentials): { email: string } => ({ email: credentials.email });
    return {
        runKey: dataset.runKey,
        builtAt: dataset.builtAt,
        primaryMandantId: dataset.primaryMandantId,
        accreditation: dataset.accreditation,
        portalEvent: dataset.portalEvent,
        badgeTemplates: dataset.badgeTemplates,
        users: {
            reviewer: address(dataset.users.reviewer),
            applicant: address(dataset.users.applicant),
            apply: address(dataset.users.apply),
            freigaben: address(dataset.users.freigaben),
            empty: address(dataset.users.empty),
        },
        print: {
            accreditationId: dataset.print.accreditationId,
            applications: dataset.print.applications.map((application) => ({
                id: application.id,
                email: application.email,
            })),
        },
        fingerprint: dataset.fingerprint,
    };
}

/**
 * Fails the write if a secret reached the artifact. Not a lint: a check that
 * only ever runs in a reviewer's eyes is a comment, this one runs on every run
 * and aborts the harness instead.
 */
function assertNoSecretsInArtifact(json: string, dataset: UiReviewDataset): void {
    const forbidden: string[] = [USER_PASSWORD];
    for (const application of dataset.print.applications) {
        if (application.token !== '') {
            forbidden.push(application.token);
        }
    }
    for (const secret of forbidden) {
        if (json.includes(secret)) {
            throw new Error(
                'Refusing to write the ui-review dataset file: it contains a cleartext secret ' +
                    `(a ${secret === USER_PASSWORD ? 'password' : 'QR token'}). The file is handed to ` +
                    'reviewers as part of the capture batch; strip the field in datasetForArtifact().',
            );
        }
    }
}

function recordDataset(dataset: UiReviewDataset): void {
    const json = `${JSON.stringify(datasetForArtifact(dataset), null, 2)}\n`;
    assertNoSecretsInArtifact(json, dataset);
    fs.mkdirSync(path.dirname(DATASET_FILE), { recursive: true });
    fs.writeFileSync(DATASET_FILE, json);
}

/**
 * Restores the two secret classes the artifact deliberately omits.
 *
 * - **Passwords** are the module constant `USER_PASSWORD`; the artifact stored
 *   the addresses, the reader knows the constant.
 * - **QR tokens** are read back from the API: they belong to the approved
 *   applications of the print accreditation, whose ids the artifact does carry.
 *   A read, never a mutation — the reuse contract is "do not reset the database
 *   again", and this keeps it.
 */
async function rehydrateSecrets(artifact: UiReviewDatasetArtifact): Promise<UiReviewDataset> {
    const credential = (role: keyof UiReviewDataset['users']): ReviewCredentials => ({
        email: artifact.users[role].email,
        password: USER_PASSWORD,
    });
    const users: UiReviewDataset['users'] = {
        reviewer: credential('reviewer'),
        applicant: credential('applicant'),
        apply: credential('apply'),
        freigaben: credential('freigaben'),
        empty: credential('empty'),
    };

    const api = await loginAdminApi();
    try {
        const applications = await readList<ApplicationRow>(
            api,
            `/api/admin/applications?accreditation_id=${artifact.print.accreditationId}`,
        );
        const tokens = new Map(applications.map((row) => [row.id, (row.qr_url ?? '').split('/').pop() ?? '']));
        return {
            runKey: artifact.runKey,
            builtAt: artifact.builtAt,
            primaryMandantId: artifact.primaryMandantId,
            accreditation: artifact.accreditation,
            portalEvent: artifact.portalEvent,
            badgeTemplates: artifact.badgeTemplates,
            users,
            print: {
                accreditationId: artifact.print.accreditationId,
                applications: artifact.print.applications.map((application) => {
                    const token = tokens.get(application.id) ?? '';
                    if (token === '') {
                        throw new Error(
                            `Approved application ${application.id} carries no qr token; the reused dataset ` +
                                'cannot serve the verify route.',
                        );
                    }
                    return { ...application, token };
                }),
            },
            fingerprint: artifact.fingerprint,
        };
    } finally {
        await api.dispose();
    }
}

export function uiReviewDataset(): Promise<UiReviewDataset> {
    datasetPromise ??= withDatasetLock(async () => {
        const recorded = readRecordedDataset(uiReviewRunKey());
        if (recorded !== null) {
            const rehydrated = await rehydrateSecrets(recorded);
            console.log(`[ui-review] reusing the dataset built by another worker of ${recorded.runKey}`);
            return rehydrated;
        }
        const built = await buildDataset();
        recordDataset(built);
        console.log(
            `[ui-review] dataset built for ${built.runKey}: ${built.fingerprint.users} users ` +
                `(${built.fingerprint.reviewUsers} review), ${built.fingerprint.applications} applications`,
        );
        return built;
    }).catch((error: unknown) => {
        // A failure is not memoised: a second caller in this process must be able
        // to retry rather than inherit a broken promise, and a retry is cheap
        // compared to a wrong capture.
        datasetPromise = null;
        throw error;
    });
    return datasetPromise;
}
