import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { useAuth } from '../../logic/useAuth';

export function AdminLayout() {
    const { i18n } = useLingui();
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    return (
        <div className="min-h-dvh bg-base-100">
            <header className="navbar bg-base-200 shadow-sm">
                <div className="navbar-start">
                    <Link to="/" className="btn btn-ghost text-xl">
                        <span className="iconify material-symbols--badge text-2xl text-primary"></span>
                        {i18n._(t`Akkreditierung`)}
                    </Link>
                </div>
                <div className="navbar-end flex items-center gap-2">
                    <span className="hidden text-sm text-base-content/70 sm:inline">{user?.email}</span>
                    <button type="button" className="btn btn-ghost" onClick={() => void handleLogout()}>
                        <span className="iconify mdi--logout text-xl"></span>
                        {i18n._(t`Abmelden`)}
                    </button>
                    <LanguageSwitcher />
                </div>
            </header>
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 md:flex-row">
                <aside className="w-full md:w-48">
                    <ul className="menu rounded-box bg-base-200 p-2 md:sticky md:top-8">
                        <li>
                            <NavLink
                                to="/admin/mandants"
                                end
                                className={({ isActive }) => (isActive ? 'menu-active' : '')}
                            >
                                {i18n._(t`Mandanten`)}
                            </NavLink>
                        </li>
                    </ul>
                </aside>
                <main className="min-w-0 flex-1">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
