import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect } from 'react';
import { Link, Navigate, Outlet, RouterProvider, createBrowserRouter, useNavigate } from 'react-router-dom';
import { setUnauthorizedHandler } from './api/client';
import { LanguageSwitcher } from './components/LanguageSwitcher';
import { isAdminUser, isSuperAdminUser } from './logic/adminRoles';
import { RequireAdmin } from './logic/RequireAdmin';
import { RequireRole } from './logic/RequireRole';
import { useAuth } from './logic/useAuth';
import { LoginPage } from './pages/LoginPage';
import { AdminLayout } from './pages/admin/AdminLayout';
import { CategoriesPage } from './pages/admin/CategoriesPage';
import { EventsPage } from './pages/admin/EventsPage';
import { MandantDetailPage } from './pages/admin/MandantDetailPage';
import { MandantFormPage } from './pages/admin/MandantFormPage';
import { MandantListPage } from './pages/admin/MandantListPage';

function UnauthorizedBridge() {
    const navigate = useNavigate();

    useEffect(() => {
        setUnauthorizedHandler(() => navigate('/login'));
        return () => setUnauthorizedHandler(null);
    }, [navigate]);

    return null;
}

function RouterShell() {
    return (
        <>
            <UnauthorizedBridge />
            <Outlet />
        </>
    );
}

function AuthNav() {
    const { i18n } = useLingui();
    const { user, isAuthenticated, logout } = useAuth();
    const navigate = useNavigate();

    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const isAdmin = isAdminUser(user);

    return (
        <div className="navbar-end flex items-center gap-2">
            {isAdmin ? (
                <Link to="/admin" className="btn btn-ghost">
                    {i18n._(t`Admin`)}
                </Link>
            ) : null}
            {isAuthenticated ? (
                <button type="button" className="btn btn-ghost" onClick={() => void handleLogout()}>
                    <span className="iconify mdi--logout text-xl"></span>
                    {i18n._(t`Abmelden`)}
                </button>
            ) : (
                <Link to="/login" className="btn btn-ghost">
                    {i18n._(t`Anmelden`)}
                </Link>
            )}
            <LanguageSwitcher />
        </div>
    );
}

function RootLayout() {
    const { i18n } = useLingui();

    return (
        <div className="min-h-dvh bg-base-100">
            <header className="navbar bg-base-200 shadow-sm">
                <div className="navbar-start">
                    <Link to="/" className="btn btn-ghost text-xl">
                        <span className="iconify material-symbols--badge text-2xl text-primary"></span>
                        {i18n._(t`Akkreditierung`)}
                    </Link>
                </div>
                <AuthNav />
            </header>
            <main className="mx-auto w-full max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    );
}

function HomePage() {
    const { i18n } = useLingui();

    return (
        <section className="flex flex-col gap-4">
            <h1 className="text-3xl font-bold">{i18n._(t`Akkreditierungs-Plattform`)}</h1>
            <p className="text-base-content/80">{i18n._(t`Willkommen bei der Akkreditierungs-Plattform.`)}</p>
        </section>
    );
}

function AdminIndexRedirect() {
    const { user } = useAuth();

    return <Navigate to={isSuperAdminUser(user) ? 'mandants' : 'categories'} replace />;
}

const router = createBrowserRouter([
    {
        element: <RouterShell />,
        children: [
            {
                path: '/',
                element: <RootLayout />,
                children: [
                    { index: true, element: <HomePage /> },
                    { path: 'login', element: <LoginPage /> },
                ],
            },
            {
                path: '/admin',
                element: (
                    <RequireAdmin>
                        <AdminLayout />
                    </RequireAdmin>
                ),
                children: [
                    { index: true, element: <AdminIndexRedirect /> },
                    {
                        element: (
                            <RequireRole role="super_admin">
                                <Outlet />
                            </RequireRole>
                        ),
                        children: [
                            { path: 'mandants', element: <MandantListPage /> },
                            { path: 'mandants/new', element: <MandantFormPage /> },
                            { path: 'mandants/:id', element: <MandantDetailPage /> },
                        ],
                    },
                    { path: 'categories', element: <CategoriesPage /> },
                    { path: 'events', element: <EventsPage /> },
                ],
            },
        ],
    },
]);

export default function App() {
    return <RouterProvider router={router} />
}
