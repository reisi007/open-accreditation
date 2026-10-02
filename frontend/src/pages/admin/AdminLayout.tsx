import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { LanguageSwitcher } from '../../components/LanguageSwitcher';
import { MandantSwitcher } from '../../components/MandantSwitcher';
import { isMandantAdminUser, isSuperAdminUser } from '../../logic/adminRoles';
import { useAuth } from '../../logic/useAuth';

interface AdminNavProps {
    className: string;
    showMandants: boolean;
    showUsers: boolean;
    showTemplates: boolean;
    showMedia: boolean;
    showFailedMails: boolean;
    onNavigate: () => void;
}

function AdminNav({
    className,
    showMandants,
    showUsers,
    showTemplates,
    showMedia,
    showFailedMails,
    onNavigate,
}: AdminNavProps) {
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
                    to="/admin/venues"
                    className={({ isActive }) => (isActive ? 'menu-active' : '')}
                    onClick={onNavigate}
                >
                    {i18n._(t`Spielorte`)}
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
            {showFailedMails ? (
                <li>
                    <NavLink
                        to="/admin/tote-briefe"
                        className={({ isActive }) => (isActive ? 'menu-active' : '')}
                        onClick={onNavigate}
                    >
                        {i18n._(t`Tote Briefe`)}
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
    // Same set: `mails.dlq.manage` is held by `mandant_admin`, `super_admin`
    // bypasses via `Gate::before`, and a `team_admin` must never reach a
    // Verband-wide list of recipient addresses.
    const showFailedMails = isSuperAdmin || isMandantAdminUser(user);

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
                        {/*
                          `w-auto min-w-0` on BOTH halves, and deliberately NOT
                          daisyUI's `width: 50%`.

                          Measured on a Galaxy A55 (480 CSS px) at
                          `/admin/accreditations`, logged in as `super_admin`:
                          `.navbar-end`'s children want 283.5 px + 32 px of gaps
                          = 315.5 px ("Hauptseite"), and up to 390.9 px with a
                          long mandant name (the switcher at its `max-w-48`).
                          daisyUI's 50 % gave `navbar-end` exactly 232 px of the
                          464 px content box, so the deficit was absorbed by
                          whichever child still had `flex-shrink` left — and the
                          only one that did was the `<select>`: it collapsed from
                          its natural 88.9 px to 42 px and its option text
                          clipped to "Deut…" on every admin page.

                          That is why the finding read as "the header overflows":
                          the document never scrolled
                          (`documentElement.scrollWidth === clientWidth` at 480
                          AND at 360, short AND long mandant name) — the header
                          starved its own control instead. The lock, not the
                          switcher, is the defect: at 480 px there were 232 px of
                          empty `navbar-start` next to a crushed select.

                          `w-auto` hands each half its natural width, `min-w-0`
                          lets the two of them share a genuinely tight viewport
                          instead of pushing the row wider than the screen, and
                          `justify-end` keeps the controls right-aligned exactly
                          as daisyUI's `width: 50%` did — the desktop layout is
                          unchanged because at ≥ lg both halves fit anyway.
                        */}
                        {/*
                          `min-w-0` + `truncate` on the brand, not just on the
                          two halves. Measured consequence of dropping the 50 %
                          lock: at 360 px `navbar-start` is 100 px against a
                          natural 130.8 px, and the brand `<a>` is a flex item
                          that did not shrink — its text painted straight through
                          the hamburger and the switcher (captured, see the
                          loop's own artifact). `min-w-0` lets the flex item
                          shrink, and `truncate` on the label turns that into an
                          ellipsis instead of an overlap. The word is a
                          non-essential label (the hamburger carries the
                          product), so truncating it is the cheapest place to
                          spend the deficit.
                        */}
                        {/*
                          `shrink` on the BRAND half, but only as the THIRD
                          fallback: the two controls above it (the switcher with
                          its `shrink-[4]` weighting, then the language
                          `<select>` with `shrink-0`) are resolved first. What
                          remains goes to the product name.

                          A floor on the switcher matters more than the order:
                          weighted shrinkage alone let it fall to 22.3 px at
                          360 px — a target too small to hit and a label with no
                          room for even one character. `min-w-20` (80 px) keeps
                          the trigger usable, and the brand label is what
                          absorbs the rest.
                        */}
                        <div className="navbar-start w-auto min-w-0 shrink overflow-hidden">
                            <Link
                                to="/"
                                className="btn btn-ghost min-w-0 max-w-full px-2 text-base lg:px-4 lg:text-xl"
                            >
                                <span className="iconify material-symbols--badge hidden text-2xl text-primary lg:inline-block"></span>
                                <span className="truncate">{i18n._(t`Akkreditierung`)}</span>
                            </Link>
                        </div>
                        {/*
                          `min-w-0` here is what makes the weighting above
                          reachable: a flex item's automatic minimum size is its
                          min-content width, so without it this half refuses to
                          shrink below the full switcher label no matter what
                          its children are told. Measured at 360 px with it
                          removed: the brand absorbed the whole 113.7 px
                          deficit and collapsed to 36.5 px.
                        */}
                        <div className="navbar-end flex w-auto min-w-0 shrink items-center gap-2">
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
                            {/*
                              The domain switcher belongs to the HEADER, not to
                              the nav list (E2): the list is instantiated twice
                              (desktop `aside` + mobile drawer), which would give
                              two states and two ARIA trees for one control,
                              while the header is rendered exactly once at every
                              viewport. `MandantSwitcher` renders nothing for
                              every role but `super_admin` (E1).
                            */}
                            {/*
                              `shrink` + `min-w-0` (not `shrink-0`): the
                              switcher is the widest control, so it is the one
                              that must be allowed to yield. Its own label
                              spans carry `truncate` and the full name stays in
                              `aria-label`, which is what makes it a safe
                              shrink victim — a control that cannot degrade must
                              not be the one the browser squeezes.
                            */}
                            {/*
                              `shrink-[4]` is a deliberate share of the
                              deficit, not a random number. Flexbox distributes
                              shrinkage in proportion to `flex-shrink × basis`,
                              so with both halves at the default `shrink-1` a
                              360 px viewport crushed the BRAND to 36.5 px
                              ("A" + ellipsis) before the switcher gave up
                              anything — the wrong victim for a product name the
                              hamburger already stands in for. Weighting the
                              switcher 4× makes it absorb the shortfall first.
                              Measured at 360 px afterwards: brand keeps its full
                              130.8 px, the switcher yields instead.
                            */}
                            <div className="min-w-20 shrink-[4]">
                                <MandantSwitcher />
                            </div>
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
                                showFailedMails={showFailedMails}
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
                            showFailedMails={showFailedMails}
                            onNavigate={closeDrawer}
                        />
                    </aside>
                </div>
            </div>
        </div>
    );
}
