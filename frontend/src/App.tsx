import { Link, Outlet, RouterProvider, createBrowserRouter } from 'react-router-dom'
import { useLingui } from '@lingui/react'
import { t } from '@lingui/core/macro'

function LanguageSwitcher() {
    const { i18n } = useLingui()

    return (
        <select
            className="select select-sm select-ghost"
            aria-label={i18n._(t`Sprache`)}
            value={i18n.locale}
            onChange={(event) => {
                i18n.activate(event.target.value)
            }}
        >
            <option value="de">Deutsch</option>
            <option value="en">English</option>
        </select>
    )
}

function RootLayout() {
    const { i18n } = useLingui()

    return (
        <div className="min-h-dvh bg-base-100">
            <header className="navbar bg-base-200 shadow-sm">
                <div className="navbar-start">
                    <Link to="/" className="btn btn-ghost text-xl">
                        <span className="iconify material-symbols--badge text-2xl text-primary"></span>
                        {i18n._(t`Akkreditierung`)}
                    </Link>
                </div>
                <div className="navbar-end">
                    <LanguageSwitcher />
                </div>
            </header>
            <main className="mx-auto w-full max-w-5xl px-4 py-8">
                <Outlet />
            </main>
        </div>
    )
}

function HomePage() {
    const { i18n } = useLingui()

    return (
        <section className="flex flex-col gap-4">
            <h1 className="text-3xl font-bold">{i18n._(t`Akkreditierungs-Plattform`)}</h1>
            <p className="text-base-content/80">{i18n._(t`Willkommen bei der Akkreditierungs-Plattform.`)}</p>
        </section>
    )
}

const router = createBrowserRouter([
    {
        path: '/',
        element: <RootLayout />,
        children: [{ index: true, element: <HomePage /> }],
    },
])

export default function App() {
    return <RouterProvider router={router} />
}
