import { expect, request, test } from '@playwright/test';
import { MailpitHelper } from './helpers/mailpit';

const FRONTEND_BASE_URL = 'http://localhost:5173';

// P1c: The accreditation-profile UI (P2) does not exist yet — this spec covers
// the live backend surface (`PUT /api/user/profile`) end-to-end through the
// Vite proxy, following the same pure-API pattern as auth.spec.ts (P1b).
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
    const email = `${prefix}-${Date.now()}@example.test`;
    const password = 'SecurePassw0rd!';

    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name: 'E2E Profile User', email, password, password_confirmation: password },
        });
        if (register.status() !== 201) {
            throw new Error(`register failed with ${register.status()}`);
        }

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

    return { api, email };
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
            expect(updateBody.message).toBe('Profil aktualisiert.');
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
        const anon = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const put = await anon.put('/api/user/profile', { data: { city: 'Wien' } });
            expect(put.status()).toBe(401);
        } finally {
            await anon.dispose();
        }
    });
});
