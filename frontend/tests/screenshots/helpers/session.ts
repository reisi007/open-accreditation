import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Shared browser-session helpers for the screenshot suite (login + "the app has
 * settled"), so every spec in `tests/screenshots` reaches a page the same way.
 *
 * STRICT rules this module keeps:
 * - SPA navigation via UI clicks; `page.goto` only for the documented cases in
 *   `ui-screenshots.spec.ts`'s module comment (the login page is the relevant one
 *   here: the header "Anmelden" link overflows the mobile navbar, so on a phone
 *   the deep link is the only reliable way to reach the form).
 * - Login through the real form. No `localStorage` injection, no token setting —
 *   the point of a screenshot suite is to see what a user sees after the real
 *   flow, and the session cookie the login leaves behind is what the print
 *   capture later authenticates its export with.
 */

/** Lets the SPA + i18n settle so later clicks never race a layout shift. */
export async function waitForAppSettled(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
}

/**
 * UI login. The login page is loaded by direct URL (see the module comment — the
 * header "Anmelden" link is unreachable in the mobile navbar). After the submit
 * the auth redirect chain must finish before any nav step runs: a user lands on
 * "/" (the admin redirect bounces them back), an admin on "/admin/*".
 */
export async function loginViaUi(
    page: Page,
    origin: string,
    email: string,
    password: string,
    landing: RegExp,
): Promise<void> {
    await page.goto(`${origin}/login`);
    await expect(page).toHaveURL(/\/login$/);
    const main = page.getByRole('main');
    await main.getByLabel('E-Mail', { exact: true }).fill(email);
    await main.getByLabel('Passwort', { exact: true }).fill(password);
    await main.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page).toHaveURL(landing);
    await waitForAppSettled(page);
}
