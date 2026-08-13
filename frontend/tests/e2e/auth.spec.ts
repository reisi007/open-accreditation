import { expect, request, test } from '@playwright/test';
import { MailpitHelper } from './helpers/mailpit';

const FRONTEND_BASE_URL = 'http://localhost:5173';

test.describe('Auth flow (P1b)', () => {
    // Pure-API spec: run once (Desktop Chrome) instead of in both browser
    // projects — avoids redundant execution and keeps register/login calls
    // within the backend's `throttle:5,1` window even across CI retries.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('register → activate → login → me', { tag: ['@smoke', '@feature:auth'] }, async () => {
        const email = `auth-${Date.now()}@example.test`;
        const password = 'SecurePassw0rd!';

        // Dedicated context: the login cookie (accr_jwt) stays in its cookie jar,
        // so the subsequent /api/auth/me call is authenticated.
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const register = await api.post('/api/auth/register', {
                data: { name: 'E2E Auth User', email, password, password_confirmation: password },
            });
            expect(register.status()).toBe(201);

            const mailpit = new MailpitHelper();
            const activationPath = await mailpit.extractActivationPath(email);

            // The activation link in the mail is built from APP_URL, which may not
            // resolve locally — normalize it onto the local frontend base URL.
            const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
            expect(activation.status()).toBe(200);

            const login = await api.post('/api/auth/login', { data: { email, password } });
            expect(login.status()).toBe(200);

            const me = await api.get('/api/auth/me');
            expect(me.status()).toBe(200);
            const meBody = (await me.json());
            expect(meBody.data.email).toBe(email);
        } finally {
            await api.dispose();
        }
    });

    test('login with wrong password returns 401', { tag: ['@smoke', '@feature:auth'] }, async () => {
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const login = await api.post('/api/auth/login', {
                data: { email: `auth-${Date.now()}@example.test`, password: 'wrong-password-123' },
            });
            expect(login.status()).toBe(401);
        } finally {
            await api.dispose();
        }
    });
});
