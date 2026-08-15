import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
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

    const isSuperAdmin = isSuperAdminUser(user);
    const showUsers = isSuperAdmin || isMandantAdminUser(user);
    const showTemplates = isSuperAdmin || isMandantAdminUser(user);
    const showMedia = isSuperAdmin || isMandantAdminUser(user);

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    return (
        <div className="min-h-dvh bg-base-100">
            <div className="drawer">
                <input
                    id="admin-drawer"
                    type="checkbox"
                    className="drawer-toggle sr-only"
                    checked={drawerOpen}
                    onChange={(event) => setDrawerOpen(event.target.checked)}
                />
                <div className="drawer-content">
                    <header className="navbar bg-base-200 shadow-sm">
                        <div className="navbar-start">
                            <Link to="/" className="btn btn-ghost text-xl">
                                <span className="iconify material-symbols--badge text-2xl text-primary"></span>
                                {i18n._(t`Akkreditierung`)}
                            </Link>
                        </div>
                        <div className="navbar-end flex items-center gap-2">
                            <label
                                htmlFor="admin-drawer"
                                aria-label={i18n._(t`Menü`)}
                                className="btn btn-ghost lg:hidden"
                            >
                                <span className="iconify mdi--menu text-2xl"></span>
                            </label>
                            <span className="hidden text-sm text-base-content/70 sm:inline">{user?.email}</span>
                            <button type="button" className="btn btn-ghost" onClick={() => void handleLogout()}>
                                <span className="iconify mdi--logout text-xl"></span>
                                {i18n._(t`Abmelden`)}
                            </button>
                            <LanguageSwitcher />
                        </div>
                    </header>
                    <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 lg:flex-row">
                        <aside className="hidden w-48 lg:block">
                            <AdminNav
                                className="menu rounded-box bg-base-200 p-2 lg:sticky lg:top-8"
                                showMandants={isSuperAdmin}
                                showUsers={showUsers}
                                showTemplates={showTemplates}
                                showMedia={showMedia}
                                onNavigate={() => setDrawerOpen(false)}
                            />
                        </aside>
                        <main className="min-w-0 flex-1">
                            <Outlet />
                        </main>
                    </div>
                </div>
                <div className="drawer-side lg:hidden">
                    <label
                        htmlFor="admin-drawer"
                        aria-label={i18n._(t`Schließen`)}
                        className="drawer-overlay"
                    ></label>
                    <aside>
                        <AdminNav
                            className="menu min-h-full w-64 bg-base-200 p-2"
                            showMandants={isSuperAdmin}
                            showUsers={showUsers}
                            showTemplates={showTemplates}
                            showMedia={showMedia}
                            onNavigate={() => setDrawerOpen(false)}
                        />
                    </aside>
                </div>
            </div>
        </div>
    );
}
