import { expect, request, test } from '@playwright/test';
import { FRONTEND_BASE_URL, uniqueSuffix } from './helpers/admin-data';
import { MailpitHelper } from './helpers/mailpit';
import { pngFixture } from '../screenshots/helpers/png-fixtures';
import { reclaimOwnedRows, rememberOwnedByUser, rememberOwnedUserAccount, resetOwnedRows } from './helpers/ownership';
import { throttleActorHeaders } from './helpers/throttle-actor';
// Per-test ownership (tests/e2e/helpers/ownership.ts): the ledger is emptied BEFORE
// the first create and drained AFTER every test, so a spec that dies half-way
// still gives back what it managed to build — three fixtures created, the fourth
// throws, the three go back. The serial globalTeardown stays as the net for a run
// that was KILLED before this hook could run: a different failure, needing a
// different net.
//
// At FILE scope, not inside a describe, on purpose: admin-mobile-layout.spec.ts
// has two describes, and a describe-scoped hook would have covered only the
// first — the exact "the teardown exists somewhere in this file" illusion the
// gate in namespace-isolation.spec.ts is meant to end. The teardown exits before
// its admin login when the ledger is empty, so a test that creates nothing pays
// nothing.
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});


// The portrait is the shared, CRC-verified PROBE from
// `tests/screenshots/helpers/png-fixtures.ts` (100x100, asymmetric colour
// bands). The inline literal that used to sit here was the SAME corrupt file as
// the one in `helpers/admin-data.ts` (IDAT CRC stored 0xfb7d5809, computed
// 0xfb7d58c9): ImageMagick refuses to decode it, and dompdf renders an empty
// photo box without an error. `tests/e2e/png-fixtures.spec.ts` now fails on any
// unregistered PNG literal in this tree, so the class cannot come back.
const PORTRAIT_PNG_BASE64 = pngFixture('portrait-probe').toString('base64');

// P1c: The accreditation-profile UI (P2) does not exist yet — this spec covers
// the live backend surface end-to-end through the Vite proxy, following the
// same pure-API pattern as auth.spec.ts (P1b):
//   - `PUT /api/user/profile` — accreditation profile field CRUD
//   - `/api/user/media*` — portrait/press_id/attachment upload & delivery
//   (the backend groups "Profile & media" together, routes/api.php)
// Once the profile page ships, extend this spec with UI flows (login →
// navigation → form interaction) instead of replacing these API assertions.

/**
 * Registers a fresh user, activates the account via the Mailpit delivery and
 * logs in — so the returned context carries the httpOnly JWT cookie and every
 * subsequent call is authenticated.
 *
 * Note: files under `tests/e2e` are linted with the espree parser (ES2020, no
 * TS syntax) but type-checked strictly by `tsc` — so parameter types come from
 * default values instead of annotations.
 */
async function createActivatedSession(prefix = 'profile') {
    // Worker- and process-scoped stamp (see `uniqueSuffix`): one mint per
    // invocation, so every caller of this helper registers a distinct account.
    const email = `${prefix}-${uniqueSuffix()}@example.test`;
    const password = 'SecurePassw0rd!';

    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name: 'E2E Profile User', email, password, password_confirmation: password },
        });
        if (register.status() !== 201) {
            throw new Error(`register failed with ${register.status()}`);
        }
        // Registered by email — the account DELETE route exists, and the
        // teardown resolves the email to its id. The MEDIA is a separate
        // ownership: that route is owner-scoped, this helper holds the
        // credentials, and the upload sites below register their own rows.
        rememberOwnedUserAccount(email);

        const mailpit = new MailpitHelper();
        const activationPath = await mailpit.extractActivationPath(email);

        // The activation link in the mail is built from APP_URL, which may not
        // resolve locally — normalize it onto the local frontend base URL.
        const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
        if (activation.status() !== 200) {
            throw new Error(`activation failed with ${activation.status()}`);
        }

        const login = await api.post('/api/auth/login', { data: { email, password } });
        if (login.status() !== 200) {
            throw new Error(`login failed with ${login.status()}`);
        }
    } catch (error) {
        await api.dispose();
        throw error;
    }

    return { api, email, password }; // credentials let a caller register its media for reclamation
}

test.describe('Profile flow (P1c)', () => {

    // Pure-API spec: run once (Desktop Chrome) instead of in both browser
    // projects — avoids redundant execution and keeps register/login calls
    // within the backend's named throttle windows even across CI retries.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('update own profile fields persists and echoes them', { tag: ['@feature:profile'] }, async () => {
        const { api } = await createActivatedSession();
        try {
            const update = await api.put('/api/user/profile', {
                data: {
                    title: 'Dr.',
                    gender: 'divers',
                    birth_date: '1990-05-17',
                    street: 'Ringstraße 1',
                    zip: '1010',
                    city: 'Wien',
                    country: 'Österreich',
                    company: 'E2E Medien GmbH',
                    phone: '+43 1 2345678',
                    fax: '+43 1 2345679',
                    branch: 'print',
                    position: 'Chefredakteurin',
                    vest_available: true,
                    vest_number: 'VT-42',
                },
            });
            expect(update.status()).toBe(200);

            const updateBody = await update.json();
            // Functional assertion: the profile was updated. Avoid coupling to
            // the exact backend copy ("Profil aktualisiert.") — only assert the
            // stable semantic marker so the test survives copy tweaks.
            expect(updateBody.message).toContain('aktualisiert');
            expect(updateBody.data.title).toBe('Dr.');
            expect(updateBody.data.city).toBe('Wien');
            expect(updateBody.data.branch).toBe('print');
            expect(updateBody.data.vest_available).toBe(true);

            // Persistence check: /auth/me must serve the stored values, not
            // just echo the request payload back.
            const me = await api.get('/api/auth/me');
            expect(me.status()).toBe(200);
            const meBody = await me.json();
            expect(meBody.data.email).toContain('profile-');
            expect(meBody.data.title).toBe('Dr.');
            expect(meBody.data.gender).toBe('divers');
            // Serialized by Laravel as full ISO-8601 ("1990-05-17T00:00:00.000000Z") —
            // assert the persisted calendar day, not the exact format.
            expect(meBody.data.birth_date).toContain('1990-05-17');
            expect(meBody.data.street).toBe('Ringstraße 1');
            expect(meBody.data.zip).toBe('1010');
            expect(meBody.data.city).toBe('Wien');
            expect(meBody.data.country).toBe('Österreich');
            expect(meBody.data.company).toBe('E2E Medien GmbH');
            expect(meBody.data.phone).toBe('+43 1 2345678');
            expect(meBody.data.fax).toBe('+43 1 2345679');
            expect(meBody.data.branch).toBe('print');
            expect(meBody.data.position).toBe('Chefredakteurin');
            expect(meBody.data.vest_available).toBe(true);
            expect(meBody.data.vest_number).toBe('VT-42');

            // A second update overwrites individual fields (no create-once).
            const secondUpdate = await api.put('/api/user/profile', {
                data: { city: 'Graz', phone: '' },
            });
            expect(secondUpdate.status()).toBe(200);
            const meAfterSecond = await api.get('/api/auth/me');
            const meAfterSecondBody = await meAfterSecond.json();
            expect(meAfterSecondBody.data.city).toBe('Graz');
            // Empty string is converted to NULL by Laravel's
            // ConvertEmptyStringsToNull middleware — sending "" clears the field.
            expect(meAfterSecondBody.data.phone).toBeNull();
            expect(meAfterSecondBody.data.company).toBe('E2E Medien GmbH');
        } finally {
            await api.dispose();
        }
    });

    test('rejects invalid branch enum and future birth date with 422', { tag: ['@feature:profile'] }, async () => {
        const { api } = await createActivatedSession('profile-invalid');
        try {
            const invalidBranch = await api.put('/api/user/profile', {
                data: { branch: 'podcast' },
            });
            expect(invalidBranch.status()).toBe(422);
            const branchBody = await invalidBranch.json();
            expect(branchBody.errors?.branch).toBeTruthy();

            const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
            const futureBirthDate = await api.put('/api/user/profile', {
                data: { birth_date: tomorrow },
            });
            expect(futureBirthDate.status()).toBe(422);
            const birthDateBody = await futureBirthDate.json();
            expect(birthDateBody.errors?.birth_date).toBeTruthy();

            // Rejected requests must not have persisted anything.
            const me = await api.get('/api/auth/me');
            const meBody = await me.json();
            expect(meBody.data.branch).toBeNull();
            expect(meBody.data.birth_date).toBeNull();
        } finally {
            await api.dispose();
        }
    });

    test('profile update requires authentication (401)', { tag: ['@feature:profile'] }, async () => {
        // Fresh context without login → no accr_jwt cookie → guard rejects.
        const anon = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
        try {
            const put = await anon.put('/api/user/profile', { data: { city: 'Wien' } });
            expect(put.status()).toBe(401);
        } finally {
            await anon.dispose();
        }
    });

    // The backend groups "Profile & media" together (routes/api.php) — the
    // portrait/press_id/attachment upload surface is part of the profile
    // feature. These tests cover the media endpoints end-to-end through the
    // Vite proxy, mirroring the pure-API pattern used above.

    test('uploads a portrait, lists it, and delivers it inline', { tag: ['@feature:profile'] }, async () => {
        const { api, email, password } = await createActivatedSession('profile-media');
        try {
            const upload = await api.post('/api/user/media', {
                multipart: {
                    type: 'portrait',
                    file: {
                        name: 'portrait.png',
                        mimeType: 'image/png',
                        buffer: Buffer.from(PORTRAIT_PNG_BASE64, 'base64'),
                    },
                },
            });
            expect(upload.status()).toBe(201);
            const uploadBody = await upload.json();
            expect(uploadBody.data.type).toBe('portrait');
            expect(uploadBody.data.mime).toBe('image/png');
            expect(uploadBody.data.id).toBeGreaterThan(0);
            // Registered with the OWNER's credentials, because the media delete
            // route answers only for the owning account (`UserMediaController::
            // destroy` compares `auth('api')->id()` with the row's `user_id`) —
            // an admin session gets a 403, not a 404, so the wrong session here
            // would look like a missing route. This is the ONE row kind in this
            // spec that a test can genuinely give back.
            rememberOwnedByUser('userMedia', uploadBody.data.id, email, password);
            // The resource exposes the authenticated delivery URL, never the
            // private storage path.
            expect(uploadBody.data.url).toContain(`/api/user/media/${uploadBody.data.id}`);

            // Persistence: the own-media list must surface the new upload.
            const list = await api.get('/api/user/media');
            expect(list.status()).toBe(200);
            const listBody = await list.json();
            expect(listBody.data).toHaveLength(1);
            expect(listBody.data[0].id).toBe(uploadBody.data.id);
            expect(listBody.data[0].type).toBe('portrait');

            // Delivery: owner streams the original bytes inline.
            const delivery = await api.get(`/api/user/media/${uploadBody.data.id}`);
            expect(delivery.status()).toBe(200);
            // The private-disk stream sets the file's own MIME; tolerate a
            // possible charset suffix added by the web server/proxy.
            expect(delivery.headers()['content-type']).toContain('image/png');
        } finally {
            await api.dispose();
        }
    });

    test('media endpoints enforce ownership (owner deletes, foreign user is 403)', { tag: ['@feature:profile'] }, async () => {
        const owner = await createActivatedSession('profile-owner');
        try {
            const upload = await owner.api.post('/api/user/media', {
                multipart: {
                    type: 'portrait',
                    file: {
                        name: 'portrait.png',
                        mimeType: 'image/png',
                        buffer: Buffer.from(PORTRAIT_PNG_BASE64, 'base64'),
                    },
                },
            });
            expect(upload.status()).toBe(201);
            const mediaId = (await upload.json()).data.id;
            // Deliberately NOT registered: this test deletes the row itself
            // (the "Owner can delete" step below is what it is testing), so a
            // ledger entry would only buy a second owner login to reach a 404.

            // Foreign authenticated user must not deliver or delete the file.
            const foreign = await createActivatedSession('profile-foreign');
            try {
                const deliver = await foreign.api.get(`/api/user/media/${mediaId}`);
                expect(deliver.status()).toBe(403);
                const remove = await foreign.api.delete(`/api/user/media/${mediaId}`);
                expect(remove.status()).toBe(403);
            } finally {
                await foreign.api.dispose();
            }

            // Owner can delete — row and file are gone.
            const remove = await owner.api.delete(`/api/user/media/${mediaId}`);
            expect(remove.status()).toBe(200);
            const removeBody = await remove.json();
            expect(removeBody.message).toContain('gelöscht');

            const listAfter = await owner.api.get('/api/user/media');
            const listAfterBody = await listAfter.json();
            expect(listAfterBody.data).toHaveLength(0);
        } finally {
            await owner.api.dispose();
        }
    });

    test('media upload requires authentication and validates input', { tag: ['@feature:profile'] }, async () => {
        // Unauthenticated upload is rejected before reaching the controller.
        const anon = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
        try {
            const upload = await anon.post('/api/user/media', {
                multipart: {
                    type: 'portrait',
                    file: {
                        name: 'portrait.png',
                        mimeType: 'image/png',
                        buffer: Buffer.from(PORTRAIT_PNG_BASE64, 'base64'),
                    },
                },
            });
            expect(upload.status()).toBe(401);
        } finally {
            await anon.dispose();
        }

        // Authenticated but invalid media type → 422 with validation errors.
        const { api } = await createActivatedSession('profile-invalid-media');
        try {
            const invalidType = await api.post('/api/user/media', {
                multipart: {
                    type: 'video',
                    file: {
                        name: 'clip.mp4',
                        mimeType: 'video/mp4',
                        buffer: Buffer.from('AAAAIGZ0eXBpc29t', 'base64'),
                    },
                },
            });
            expect(invalidType.status()).toBe(422);
            const invalidBody = await invalidType.json();
            expect(invalidBody.errors?.type).toBeTruthy();
        } finally {
            await api.dispose();
        }
    });
});
