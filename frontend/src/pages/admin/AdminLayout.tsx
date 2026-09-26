import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { isMandantAdminUser, isSuperAdminUser } from '../../logic/adminRoles';
import { useAuth } from '../../logic/useAuth';

interface AdminNavProps {
    className: string;
    showMandants: boolean;
    showUsers: boolean;
    showTemplates: boolean;
    showMedia: boolean;
    onNavigate: () => void;
}

function AdminNav({ className, showMandants, showUsers, showTemplates, showMedia, onNavigate }: AdminNavProps) {
    const { i18n } = useLingui();

    return (
        <ul className={className}>
            {showMandants ? (
                <li>
                    <NavLink
                        to="/admin/mandants"
                        end
                        className={({ isActive }) => (isActive ? 'menu-active' : '')}
                        onClick={onNavigate}
                    >
                        {i18n._(t`Mandanten`)}
                    </NavLink>
                </li>
            ) : null}
            <li>
                <NavLink
                    to="/admin/categories"
                    className={({ isActive }) => (isActive ? 'menu-active' : '')}
                    onClick={onNavigate}
                >
                    {i18n._(t`Kategorien`)}
                </NavLink>
            </li>
            <li>
                <NavLink
                    to="/admin/events"
                    className={({ isActive }) => (isActive ? 'menu-active' : '')}
                    onClick={onNavigate}
                >
                    {i18n._(t`Events`)}
                </NavLink>
            </li>
            <li>
                <NavLink
                    to="/admin/accreditations"
                    className={({ isActive }) => (isActive ? 'menu-active' : '')}
                    onClick={onNavigate}
                >
                    {i18n._(t`Akkreditierungen`)}
                </NavLink>
            </li>
            <li>
                <NavLink
                    to="/admin/freigaben"
                    className={({ isActive }) => (isActive ? 'menu-active' : '')}
                    onClick={onNavigate}
                >
                    {i18n._(t`Freigaben`)}
                </NavLink>
            </li>
            {showUsers ? (
                <li>
                    <NavLink
                        to="/admin/users"
                        className={({ isActive }) => (isActive ? 'menu-active' : '')}
                        onClick={onNavigate}
                    >
                        {i18n._(t`Benutzer`)}
                    </NavLink>
                </li>
            ) : null}
            {showTemplates ? (
                <li>
                    <NavLink
                        to="/admin/badge-templates"
                        className={({ isActive }) => (isActive ? 'menu-active' : '')}
                        onClick={onNavigate}
                    >
                        {i18n._(t`Ausweis-Templates`)}
                    </NavLink>
                </li>
            ) : null}
            {showMedia ? (
                <li>
                    <NavLink
                        to="/admin/media"
                        className={({ isActive }) => (isActive ? 'menu-active' : '')}
                        onClick={onNavigate}
                    >
                        {i18n._(t`Logo & Header`)}
                    </NavLink>
                </li>
            ) : null}
        </ul>
    );
}

export function AdminLayout() {
    const { i18n } = useLingui();
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const drawerRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const wasOpen = useRef(false);

    const isSuperAdmin = isSuperAdminUser(user);
    const showUsers = isSuperAdmin || isMandantAdminUser(user);
    const showTemplates = isSuperAdmin || isMandantAdminUser(user);
    const showMedia = isSuperAdmin || isMandantAdminUser(user);

    // `useAuth().logout()` never rejects (it tears the cache down even when the
    // request fails), so the navigation below always runs and the shell can
    // never keep rendering the stale session.
    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const closeDrawer = () => setDrawerOpen(false);

    /**
     * Focus management for the mobile drawer. The daisyUI drawer is a pure CSS
     * state, so opening it does not move focus — without this the keyboard focus
     * would stay on the hamburger button behind the overlay. It has to run in an
     * effect (not in the click handler) because the nav links stay
     * `visibility: hidden` until the checkbox state has been applied, and
     * daisyUI transitions that flip with a 100 ms delay
     * (`transition: … visibility .3s ease-out .1s allow-discrete`) while
     * `focus()` is a no-op on a `visibility: hidden` element. Hence: try
     * immediately and once more when the transition has finished.
     */
    useEffect(() => {
        if (!drawerOpen) {
            if (wasOpen.current) {
                triggerRef.current?.focus();
            }
            wasOpen.current = false;
            return;
        }

        wasOpen.current = true;
        const side = drawerRef.current;
        if (side === null) {
            return;
        }

        const focusFirst = () => {
            if (side.contains(document.activeElement)) {
                return;
            }
            side.querySelector<HTMLElement>('a[href], button:not([disabled])')?.focus();
        };

        focusFirst();
        side.addEventListener('transitionend', focusFirst);
        return () => side.removeEventListener('transitionend', focusFirst);
    }, [drawerOpen]);

    const handleDrawerKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (drawerOpen && event.key === 'Escape') {
            closeDrawer();
        }
    };

    return (
        <div className="min-h-dvh bg-base-100">
            <div className="drawer">
                {/*
                  daisyUI opens `.drawer-side` only via
                  `:where(.drawer-toggle:checked~.drawer-side)`, so the checkbox
                  is kept purely as the CSS state carrier. It is `sr-only` and
                  previously a focusable-but-invisible tab stop, so it is taken
                  out of the a11y tree and the tab order; the real control is the
                  `<button aria-expanded aria-controls>` below.
                */}
                <input
                    id="admin-drawer"
                    type="checkbox"
                    className="drawer-toggle sr-only"
                    tabIndex={-1}
                    aria-hidden="true"
                    checked={drawerOpen}
                    onChange={(event) => setDrawerOpen(event.target.checked)}
                />
                <div className="drawer-content">
                    <header className="navbar bg-base-200 shadow-sm">
                        <div className="navbar-start">
                            <Link to="/" className="btn btn-ghost px-2 text-base lg:px-4 lg:text-xl">
                                <span className="iconify material-symbols--badge hidden text-2xl text-primary lg:inline-block"></span>
                                {i18n._(t`Akkreditierung`)}
                            </Link>
                        </div>
                        <div className="navbar-end flex items-center gap-2">
                            <button
                                ref={triggerRef}
                                type="button"
                                className="btn btn-ghost btn-sm btn-square lg:hidden"
                                aria-label={i18n._(t`Menü`)}
                                aria-expanded={drawerOpen}
                                aria-controls="admin-drawer-nav"
                                onClick={() => setDrawerOpen((current) => !current)}
                            >
                                <span className="iconify mdi--menu text-2xl"></span>
                            </button>
                            <span className="hidden text-sm text-base-content/70 sm:inline">{user?.email}</span>
                            <button
                                type="button"
                                className="btn btn-ghost btn-sm lg:btn-md"
                                aria-label={i18n._(t`Abmelden`)}
                                onClick={() => void handleLogout()}
                            >
                                <span className="iconify mdi--logout text-xl"></span>
                                <span className="hidden sm:inline">{i18n._(t`Abmelden`)}</span>
                            </button>
                            <LanguageSwitcher />
                        </div>
                    </header>
                    <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 lg:flex-row">
                        {/*
                          Desktop navigation. `lg:block` + the mobile `lg:hidden`
                          drawer below keep exactly ONE `complementary` landmark
                          in the a11y tree at any viewport.
                        */}
                        <aside className="hidden w-48 lg:block">
                            <AdminNav
                                className="menu rounded-box bg-base-200 p-2 lg:sticky lg:top-8"
                                showMandants={isSuperAdmin}
                                showUsers={showUsers}
                                showTemplates={showTemplates}
                                showMedia={showMedia}
                                onNavigate={closeDrawer}
                            />
                        </aside>
                        <main className="min-w-0 flex-1">
                            <Outlet />
                        </main>
                    </div>
                </div>
                <div ref={drawerRef} className="drawer-side lg:hidden" onKeyDown={handleDrawerKeyDown}>
                    {/*
                      Mouse-only backdrop: daisyUI's `.drawer-overlay` needs the
                      class, and a focusable full-screen button would be a
                      keyboard trap, so it stays `aria-hidden` — Escape and the
                      nav links' own navigation cover keyboard users.
                    */}
                    <div className="drawer-overlay" aria-hidden="true" onClick={closeDrawer}></div>
                    <aside id="admin-drawer-nav">
                        <AdminNav
                            className="menu min-h-full w-64 bg-base-200 p-2"
                            showMandants={isSuperAdmin}
                            showUsers={showUsers}
                            showTemplates={showTemplates}
                            showMedia={showMedia}
                            onNavigate={closeDrawer}
                        />
                    </aside>
                </div>
            </div>
        </div>
    );
}
