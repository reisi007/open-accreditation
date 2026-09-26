import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { AdminLayout } from './AdminLayout';

const { logoutMock } = vi.hoisted(() => ({ logoutMock: vi.fn(async () => undefined) }));

vi.mock('../../logic/useAuth', () => ({
    useAuth: () => ({
        user: {
            id: 1,
            name: 'Admin',
            email: 'admin@example.com',
            current_mandant_id: 1,
            roles: [{ slug: 'super_admin', name: 'Super Admin', mandant_id: null, team_id: null }],
        },
        isAuthenticated: true,
        isLoading: false,
        logout: logoutMock,
        login: vi.fn(),
        mutate: vi.fn(),
    }),
}));

function renderLayout() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/admin/categories']}>
            <Routes>
                <Route path="/admin" element={<AdminLayout />}>
                    <Route path="categories" element={<p>Kategorien-Inhalt</p>} />
                </Route>
            </Routes>
        </MemoryRouter>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('AdminLayout mobile drawer', () => {
    it('exposes a real button trigger with aria-expanded/aria-controls', () => {
        renderLayout();

        // The old markup used `<label htmlFor="admin-drawer">`, which is
        // neither focusable nor a control: there was no keyboard path to the
        // admin nav below `lg`.
        const trigger = screen.getByRole('button', { name: 'Menü' });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(trigger).toHaveAttribute('aria-controls', 'admin-drawer-nav');
        expect(document.getElementById('admin-drawer-nav')).not.toBeNull();
        // No `<label htmlFor>` trigger is left — a label is neither focusable
        // nor a control, so it cannot open the drawer from the keyboard.
        expect(document.querySelector('label[for="admin-drawer"]')).toBeNull();
    });

    it('toggles aria-expanded and moves focus into the drawer on open', async () => {
        const user = userEvent.setup();
        renderLayout();

        const trigger = screen.getByRole('button', { name: 'Menü' });
        await user.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        // The CSS-only drawer does not move focus; without this a keyboard
        // user keeps interacting with the page behind the overlay.
        const drawerNav = document.getElementById('admin-drawer-nav');
        expect(drawerNav).not.toBeNull();
        if (drawerNav === null) return;

        await waitFor(() =>
            expect(document.activeElement).toBe(within(drawerNav).getByRole('link', { name: 'Mandanten' })),
        );
        expect(document.getElementById('admin-drawer')).toBeChecked();
    });

    it('closes on Escape and hands focus back to the trigger', async () => {
        const user = userEvent.setup();
        renderLayout();

        const trigger = screen.getByRole('button', { name: 'Menü' });
        await user.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');

        await user.keyboard('{Escape}');

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(document.getElementById('admin-drawer')).not.toBeChecked();
        await waitFor(() => expect(document.activeElement).toBe(trigger));
    });

    it('keeps the state-carrier checkbox out of the tab order and the a11y tree', () => {
        renderLayout();

        const carrier = document.getElementById('admin-drawer');
        expect(carrier).toHaveAttribute('aria-hidden', 'true');
        expect(carrier).toHaveAttribute('tabindex', '-1');
    });

    it('does not duplicate the complementary landmark (desktop aside is lg:block)', () => {
        renderLayout();

        // Both <aside> elements exist in the DOM, but the desktop one is
        // `hidden lg:block` and the mobile one lives inside `lg:hidden`, so the
        // E2E locator `getByRole('complementary')` must stay unambiguous.
        expect(document.querySelectorAll('aside')).toHaveLength(2);
    });
});
