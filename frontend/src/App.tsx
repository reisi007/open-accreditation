import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect } from 'react';
import {
    Link,
    Navigate,
    Outlet,
    RouterProvider,
    createBrowserRouter,
    isRouteErrorResponse,
    useNavigate,
    useRouteError,
} from 'react-router-dom';
import { setUnauthorizedHandler } from './api/client';
import { LanguageSwitcher } from './components/LanguageSwitcher';
import { isAdminUser, isSuperAdminUser } from './logic/adminRoles';
import { RequireAdmin } from './logic/RequireAdmin';
import { RequireAuth } from './logic/RequireAuth';
import { RequireRole } from './logic/RequireRole';
import { RequireRoles } from './logic/RequireRoles';
import { useAuth } from './logic/useAuth';
import { LoginPage } from './pages/LoginPage';
import { EventDetailPage } from './pages/portal/EventDetailPage';
import { PortalHomePage } from './pages/portal/PortalHomePage';
import { AccreditationsPage } from './pages/AccreditationsPage';
import { ApplyPage } from './pages/ApplyPage';
import { MyAccreditationsPage } from './pages/MyAccreditationsPage';
import { AdminLayout } from './pages/admin/AdminLayout';
import { AccreditationsPage as AdminAccreditationsPage } from './pages/admin/AccreditationsPage';
import { ApprovalsPage } from './pages/admin/ApprovalsPage';
import { CategoriesPage } from './pages/admin/CategoriesPage';
import { EventsPage } from './pages/admin/EventsPage';
import { MandantDetailPage } from './pages/admin/MandantDetailPage';
import { MandantFormPage } from './pages/admin/MandantFormPage';
import { MandantListPage } from './pages/admin/MandantListPage';
import { MandantMediaPage } from './pages/admin/MandantMediaPage';
import { UsersPage } from './pages/admin/UsersPage';
import { BadgeTemplatesPage } from './pages/admin/BadgeTemplatesPage';
import { VerifyPage } from './pages/VerifyPage';

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

function MobileNavMenu() {
    const { i18n } = useLingui();
    const { user, isAuthenticated } = useAuth();
    const isAdmin = isAdminUser(user);

    return (
        <div className="dropdown lg:hidden">
            <div
                tabIndex={0}
                role="button"
                aria-label={i18n._(t`Menü`)}
                className="btn btn-ghost btn-sm btn-square"
            >
                <span className="iconify mdi--menu text-2xl"></span>
            </div>
            <ul tabIndex={-1} className="dropdown-content menu z-50 w-56 rounded-box bg-base-100 p-2 shadow">
                <li>
                    <Link to="/akkreditierungen">{i18n._(t`Akkreditierungen`)}</Link>
                </li>
                <li>
                    <Link to="/verify">{i18n._(t`Verifizieren`)}</Link>
                </li>
                {isAuthenticated ? (
                    <li>
                        <Link to="/meine-akkreditierungen">{i18n._(t`Meine Akkreditierungen`)}</Link>
                    </li>
                ) : null}
                {isAdmin ? (
                    <li>
                        <Link to="/admin">{i18n._(t`Admin`)}</Link>
                    </li>
                ) : null}
            </ul>
        </div>
    );
}

function AuthNav() {
    const { i18n } = useLingui();
    const { user, isAuthenticated, logout } = useAuth();
    const navigate = useNavigate();

    // `useAuth().logout()` never rejects (it tears the cache down even when the
    // request fails), so the navigation below always runs and the shell can
    // never keep rendering the stale session.
    const handleLogout = async () => {
        await logout();
        navigate('/');
    };

    const isAdmin = isAdminUser(user);

    return (
        <div className="navbar-end flex items-center gap-2">
            {isAdmin ? (
                <Link to="/admin" className="btn btn-ghost btn-sm hidden lg:inline-flex lg:btn-md">
                    {i18n._(t`Admin`)}
                </Link>
            ) : null}
            {isAuthenticated ? (
                <button
                    type="button"
                    className="btn btn-ghost btn-sm lg:btn-md"
                    onClick={() => void handleLogout()}
                >
                    <span className="iconify mdi--logout text-xl"></span>
                    {i18n._(t`Abmelden`)}
                </button>
            ) : (
                <Link to="/login" className="btn btn-ghost btn-sm lg:btn-md">
                    {i18n._(t`Anmelden`)}
                </Link>
            )}
            <LanguageSwitcher />
        </div>
    );
}

function RootLayout() {
    const { i18n } = useLingui();
    const { isAuthenticated } = useAuth();

    return (
        <div className="min-h-dvh bg-base-100">
            <header className="navbar bg-base-200 shadow-sm">
                <div className="navbar-start">
                    <MobileNavMenu />
                    <Link to="/" className="btn btn-ghost px-2 text-base lg:px-4 lg:text-xl">
                        <span className="iconify material-symbols--badge hidden text-2xl text-primary lg:inline-block"></span>
                        {i18n._(t`Akkreditierung`)}
                    </Link>
                </div>
                <div className="navbar-center hidden gap-1 lg:flex">
                    <Link to="/akkreditierungen" className="btn btn-ghost btn-sm">
                        {i18n._(t`Akkreditierungen`)}
                    </Link>
                    <Link to="/verify" className="btn btn-ghost btn-sm">
                        {i18n._(t`Verifizieren`)}
                    </Link>
                    {isAuthenticated ? (
                        <Link to="/meine-akkreditierungen" className="btn btn-ghost btn-sm">
                            {i18n._(t`Meine Akkreditierungen`)}
                        </Link>
                    ) : null}
                </div>
                <AuthNav />
            </header>
            <main className="mx-auto w-full max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    );
}

function AdminIndexRedirect() {
    const { user } = useAuth();

    return <Navigate to={isSuperAdminUser(user) ? 'mandants' : 'categories'} replace />;
}

/**
 * Rendered in place of the route that threw. Without it React Router falls back
 * to its built-in error page, which renders outside the app shell (no header,
 * no styling, no translated copy). A 404 from the API is reported as the
 * generic load failure — the distinction only matters for router-level errors.
 */
function RouteError() {
    const error = useRouteError();
    const { i18n } = useLingui();

    return (
        <div role="alert" className="alert alert-error">
            <span>
                {isRouteErrorResponse(error)
                    ? i18n._(t`Seite nicht gefunden.`)
                    : i18n._(t`Die Seite konnte nicht geladen werden.`)}
            </span>
        </div>
    );
}

/** Catch-all for unmatched URLs, rendered inside the app shell. */
function NotFoundPage() {
    const { i18n } = useLingui();

    return (
        <section className="flex flex-col gap-4">
            <h1 className="text-3xl font-bold">{i18n._(t`Seite nicht gefunden.`)}</h1>
            <p className="text-base-content/70">
                {i18n._(t`Die angeforderte Seite existiert nicht.`)}
            </p>
            <Link to="/" className="btn btn-primary self-start">
                {i18n._(t`Zur Startseite`)}
            </Link>
        </section>
    );
}

const router = createBrowserRouter([
    {
        element: <RouterShell />,
        // Last-resort boundary: keeps a render error inside the app's i18n and
        // Tailwind context instead of React Router's raw default page.
        errorElement: <RouteError />,
        children: [
            {
                path: '/',
                element: <RootLayout />,
                errorElement: <RouteError />,
                children: [
                    { index: true, element: <PortalHomePage /> },
                    { path: 'events/:id', element: <EventDetailPage /> },
                    { path: 'akkreditierungen', element: <AccreditationsPage /> },
                    {
                        path: 'apply/:accreditationId',
                        element: (
                            <RequireAuth>
                                <ApplyPage />
                            </RequireAuth>
                        ),
                    },
                    {
                        path: 'meine-akkreditierungen',
                        element: (
                            <RequireAuth>
                                <MyAccreditationsPage />
                            </RequireAuth>
                        ),
                    },
                    { path: 'verify', element: <VerifyPage /> },
                    { path: 'verify/:token', element: <VerifyPage /> },
                    { path: 'login', element: <LoginPage /> },
                    { path: '*', element: <NotFoundPage /> },
                ],
            },
            {
                path: '/admin',
                element: (
                    <RequireAdmin>
                        <AdminLayout />
                    </RequireAdmin>
                ),
                errorElement: <RouteError />,
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
                    { path: 'accreditations', element: <AdminAccreditationsPage /> },
                    { path: 'freigaben', element: <ApprovalsPage /> },
                    {
                        element: (
                            <RequireRoles roles={['super_admin', 'mandant_admin']}>
                                <Outlet />
                            </RequireRoles>
                        ),
                        children: [
                            { path: 'users', element: <UsersPage /> },
                            { path: 'badge-templates', element: <BadgeTemplatesPage /> },
                            { path: 'media', element: <MandantMediaPage /> },
                        ],
                    },
                    // Catch-all inside the admin shell too: without it a typo
                    // under /admin matches no leaf at all and React Router
                    // renders its own default error page.
                    { path: '*', element: <NotFoundPage /> },
                ],
            },
        ],
    },
]);

export default function App() {
    return <RouterProvider router={router} />
}
