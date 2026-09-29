import { expect, request, test } from '@playwright/test';
import { FRONTEND_BASE_URL, uniqueSuffix } from './helpers/admin-data';
import { MailpitHelper } from './helpers/mailpit';
import { reclaimOwnedRows, rememberOwnedUserAccount, resetOwnedRows } from './helpers/ownership';
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


test.describe('Auth flow (P1b)', () => {

    // Pure-API spec: run once (Desktop Chrome) instead of in both browser
    // projects — avoids redundant execution and keeps register/login calls
    // within the backend's `throttle:5,1` window even across CI retries.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('register → activate → login → me', { tag: ['@smoke', '@feature:auth'] }, async () => {
        // One stamp per test, reused for register + activation + login: two
        // same-millisecond mints inside one worker would defeat the purpose.
        const email = `auth-${uniqueSuffix()}@example.test`;
        const password = 'SecurePassw0rd!';

        // Dedicated context: the login cookie (accr_jwt) stays in its cookie jar,
        // so the subsequent /api/auth/me call is authenticated.
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const register = await api.post('/api/auth/register', {
                data: { name: 'E2E Auth User', email, password, password_confirmation: password },
            });
            expect(register.status()).toBe(201);
            // Registered by email: `register` answers a bare `{message}`, and the
            // teardown resolves the email to the id `DELETE /api/admin/users/{id}`
            // addresses (see `rememberOwnedUserAccount`).
            rememberOwnedUserAccount(email);

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
        // Separate test from the register flow above, so it mints its OWN stamp —
        // but still exactly one, hoisted out of the request body.
        const email = `auth-${uniqueSuffix()}@example.test`;
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const login = await api.post('/api/auth/login', {
                data: { email, password: 'wrong-password-123' },
            });
            expect(login.status()).toBe(401);
        } finally {
            await api.dispose();
        }
    });
});
