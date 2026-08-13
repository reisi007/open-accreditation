import { expect, test } from '@playwright/test';

test('App loads and shows the header', { tag: '@smoke' }, async ({ page }) => {
    await page.goto('/');

    const banner = page.getByRole('banner');
    await expect(banner.getByRole('link', { name: 'Akkreditierung' })).toBeVisible();

    const main = page.getByRole('main');
    await expect(main.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(main.getByRole('heading', { level: 2, name: 'Veranstaltungskalender' })).toBeVisible();
});

test('Language switcher switches the UI to English', { tag: '@smoke' }, async ({ page }) => {
    await page.goto('/');

    await page.getByRole('combobox', { name: 'Sprache' }).selectOption('en');

    await expect(page.getByRole('heading', { level: 2, name: 'Event calendar' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Accreditation' })).toBeVisible();
});
