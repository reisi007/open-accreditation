import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import type { Mandant } from '../api/types';
import { isSuperAdminUser } from '../logic/adminRoles';
import { useAuth } from '../logic/useAuth';
import { useMandants } from '../logic/useMandants';

/**
 * ONE id, because E2 guarantees exactly one instance: the switcher lives in the
 * `navbar-end`, which `AdminLayout` renders at every viewport, while the nav
 * list it must NOT live in is instantiated twice (desktop `aside` + mobile
 * drawer). A `useId()` would work too, but a constant keeps `aria-controls`
 * greppable from the DOM dump and stable across renders.
 */
const PANEL_ID = 'mandant-switcher-panel';

/** The slice of `window.location` the target URL is built from (E7, E9). */
interface CurrentLocation {
    protocol: string;
    hostname: string;
    pathname: string;
    search: string;
}

/** One `<li>` of the panel: a mandant, its domains, and where it leads. */
interface SwitchRow {
    id: number;
    name: string;
    /** Every hostname of the mandant, first one first (E3). */
    hostnames: string[];
    isActive: boolean;
    isCurrent: boolean;
    /** `null` for the current, an inactive and a domainless mandant. */
    href: string | null;
}

/**
 * One row per mandant, target = its FIRST domain by ascending id (E3).
 *
 * "One row per domain" was rejected: the question is "in which association am I
 * working", not "under which hostname", so five nearly identical rows
 * (`Hauptseite · localhost`, `Hauptseite · accreditation.test`, …) would make
 * the "you are here" marker wobble per host instead of per association. The
 * `id` order is the one `MandantDomainController::index` already uses.
 *
 * A row is a destination only if it can actually be reached: active (E5 — an
 * inactive mandant's hostname resolves to `null` and 404s), has a domain (E4 —
 * there is no URL to navigate to) and is not the mandant the current host
 * already resolved to. Those three render without an `href`, which is also what
 * makes them unreachable by the keyboard path.
 *
 * The target URL carries the current path and query verbatim (E7) and takes its
 * scheme from the running origin (E9) — a stored hostname has no scheme, and a
 * dev PORT belongs to the dev origin, not to the mandant. Nothing about the
 * switch is persisted (E6): the host is the only truth, so the "switch" is a
 * plain top-level navigation and the server re-resolves everything.
 */
function buildRows(mandants: Mandant[], currentMandantId: number | null, location: CurrentLocation): SwitchRow[] {
    return mandants.map((mandant) => {
        const { domains, id, name } = mandant;
        const ordered = [...domains].sort((left, right) => left.id - right.id);
        const target = ordered[0]?.hostname ?? null;
        const isCurrent = id === currentMandantId;
        const reachable = mandant.is_active && target !== null && !isCurrent;

        return {
            id,
            name,
            hostnames: ordered.map((domain) => domain.hostname),
            isActive: mandant.is_active,
            isCurrent,
            href: reachable ? `${location.protocol}//${target}${location.pathname}${location.search}` : null,
        };
    });
}

/**
 * The rows the keyboard can land on: the real `<a href>` destinations.
 *
 * Not every row is one of them, and that is the point: an inactive or domainless
 * row, and the current mandant itself, are spans. Focusing one would be a dead
 * end (nothing to activate), so the arrows step over them.
 */
function focusableRows(panel: HTMLUListElement | null): HTMLAnchorElement[] {
    if (panel === null) {
        return [];
    }

    return Array.from(panel.querySelectorAll<HTMLAnchorElement>('a[href]'));
}

/**
 * Domain switcher for the `super_admin` (E1), rendered in the admin header.
 *
 * Rows are `<a href>` on a FOREIGN origin and the panel is a plain disclosure
 * (`aria-expanded` + `aria-controls` on the trigger) over a `<ul>` — E8, and
 * deliberately NOT the `combobox`/`listbox`/`option` shape of `VenueCombobox`.
 * A switch across an origin boundary IS a navigation: a link gives middle
 * click, "open in new tab", "copy link address" and — the actual security
 * argument — the target domain in the status bar BEFORE the click. It also
 * removes the whole error class the combobox had to fight: no interactive
 * element inside a `role="option"`, no `aria-activedescendant` bookkeeping that
 * can point at a shrunk list. "You are here" is `aria-current="true"`.
 */
export function MandantSwitcher() {
    const { i18n } = useLingui();
    const { user } = useAuth();
    const isSuperAdmin = isSuperAdminUser(user);
    const { mandants, isLoading, error } = useMandants(isSuperAdmin);
    const [open, setOpen] = useState(false);
    const wrapperRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const panelRef = useRef<HTMLUListElement>(null);
    /**
     * "The freshly opened panel should take the focus". Set by the opening
     * handler, consumed by the effect below: the panel is rendered ONLY while
     * open, so the row to focus does not exist yet when the key is pressed.
     */
    const focusOnOpen = useRef(false);

    const currentMandantId = user?.current_mandant_id ?? null;
    const rows = buildRows(mandants ?? [], currentMandantId, window.location);
    const current = rows.find((row) => row.isCurrent) ?? null;
    /**
     * The host the browser is on — display only, never the identity: the
     * "you are here" marker is `current_mandant_id` from `/me`, because the
     * current domain is not necessarily the mandant's first one.
     */
    const hostName = window.location.hostname;
    const currentName = current?.name ?? null;
    const wideLabel = currentName === null ? hostName : `${currentName} · ${hostName}`;
    const narrowLabel = currentName ?? hostName;

    const closePanel = (returnFocus: boolean) => {
        setOpen(false);
        if (returnFocus) {
            triggerRef.current?.focus();
        }
    };

    /**
     * Open and remember that the first row has to be focused. `Enter` / `Space`
     * arrive here through the button's own `click`; only the arrows need a
     * keydown handler, because a `<button>` already acts on the other two and
     * handling them twice would toggle the panel shut again.
     */
    const openPanel = () => {
        focusOnOpen.current = true;
        setOpen(true);
    };

    useEffect(() => {
        if (!open || !focusOnOpen.current) {
            return;
        }
        const first = focusableRows(panelRef.current)[0];
        // No row yet means the list is still in flight: keep the flag and retry
        // when the rows arrive, instead of stranding the focus on the trigger.
        if (first === undefined) {
            return;
        }
        focusOnOpen.current = false;
        first.focus();
    }, [open, rows.length]);

    /**
     * Outside click. A `blur` on the wrapper (what `VenueCombobox` uses) does not
     * cover it: clicking the page background moves the focus nowhere, so no blur
     * fires and the panel would stay open over the content. A document-level
     * `pointerdown` fires for every click, and the containment test keeps the
     * panel's own clicks open.
     */
    useEffect(() => {
        if (!open) {
            return;
        }

        const handlePointerDown = (event: PointerEvent) => {
            const target = event.target;
            if (target instanceof Node && !wrapperRef.current?.contains(target)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', handlePointerDown);
        return () => document.removeEventListener('pointerdown', handlePointerDown);
    }, [open]);

    const handleTriggerKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            openPanel();
        }
    };

    const handlePanelKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel(true);
            return;
        }
        if (event.key === 'Tab') {
            // Deliberately no `preventDefault`: the panel closes and the focus
            // walks on. Trapping it would make the header a keyboard dead end.
            setOpen(false);
            return;
        }

        const rowsInPanel = focusableRows(panelRef.current);
        const index = rowsInPanel.indexOf(event.target as HTMLAnchorElement);
        if (index < 0) {
            return;
        }

        // No wrap-around: the first and the last row are the ends of the panel.
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? rowsInPanel.length - 1
                  : event.key === 'ArrowDown'
                    ? Math.min(index + 1, rowsInPanel.length - 1)
                    : event.key === 'ArrowUp'
                      ? Math.max(index - 1, 0)
                      : -1;
        if (next < 0) {
            return;
        }
        event.preventDefault();
        rowsInPanel[next]?.focus();
    };

    // E1: a `mandant_admin` is on his own domain and cannot read the list the
    // switcher is built from. Rendering nothing keeps that fact visible instead
    // of offering a control with a single, unusable entry.
    if (!isSuperAdmin) {
        return null;
    }

    // `aria-controls` is set ONLY while open, because the panel is rendered only
    // while open: a reference to a node that is not in the DOM is a dangling one
    // (the same reason `VenueCombobox` omits it in its collapsed state).
    return (
        <div ref={wrapperRef} className={open ? 'dropdown dropdown-open' : 'dropdown'}>
            <button
                ref={triggerRef}
                type="button"
                // `min-w-0 shrink` + a tighter ceiling below `sm` (128 px instead
                // of 192 px): this trigger is the widest thing in the header, and
                // it is the one control with a graceful degradation — its label
                // spans already carry `truncate`, and the full name stays in
                // `aria-label` for assistive tech. So when a mandant name is
                // long it is THIS that yields first, which is what keeps the
                // language `<select>` (which cannot truncate) intact.
                // Measured at 480 px: natural 192 px → 128 px, the select stays
                // at 88.9 px, and a 47-character mandant name now fits the header
                // with no truncation at all. At `sm` and up the ceiling returns
                // to 256 px, so desktop and tablet are untouched — this is a
                // below-`sm` budget decision, not a relabelling of the widget.
                className="btn btn-ghost btn-sm min-w-0 max-w-32 shrink sm:max-w-64 lg:btn-md"
                aria-label={currentName === null ? hostName : i18n._(t`Verband: ${currentName} (${hostName})`)}
                aria-expanded={open}
                aria-controls={open ? PANEL_ID : undefined}
                onClick={() => (open ? closePanel(false) : openPanel())}
                onKeyDown={handleTriggerKeyDown}
            >
                <span className="hidden truncate sm:inline">{wideLabel}</span>
                <span className="truncate sm:hidden">{narrowLabel}</span>
                {/*
                  A chevron is the only signal that this button opens a panel.
                  Decorative: the trigger itself carries the semantics, so the
                  icon is hidden instead of being announced as a second control.
                */}
                <span aria-hidden="true" className="iconify mdi--unfold-more-horizontal text-xl"></span>
            </button>
            {open ? (
                <ul
                    id={PANEL_ID}
                    ref={panelRef}
                    aria-label={i18n._(t`Verband wechseln`)}
                    className="dropdown-content menu z-50 mt-1 w-80 rounded-box bg-base-100 p-2 shadow"
                    onKeyDown={handlePanelKeyDown}
                    onMouseDown={(event) => event.preventDefault()}
                >
                    {/*
                      `onMouseDown` above keeps the focus on the trigger, so the
                      click LANDS on the row instead of focusing it and then
                      losing it (the pattern from `VenueCombobox`).
                    */}
                    {isLoading ? (
                        <li>
                            <span className="loading loading-spinner loading-sm"></span>
                        </li>
                    ) : null}

                    {/*
                      A failed list must say so: an empty panel would read as
                      "this is the complete set of associations", which is the one
                      answer a switcher must never give wrongly.
                    */}
                    {error ? (
                        /*
                          A row of the same `.menu`, and daisyUI's hover rule
                          reaches it: measured without the marker it takes
                          `cursor: auto → pointer` and a background of
                          `base-content` at 10% — a promise of a click on a line
                          that only reports a failure. The loading row above needs
                          no marker: its spinner carries `.loading`, which sets
                          `pointer-events: none`, so it is never hovered at all.
                        */
                        <li className="disabled">
                            <span className="text-sm text-error">{i18n._(t`Mandanten konnten nicht geladen werden.`)}</span>
                        </li>
                    ) : null}

                    {rows.map((row) => {
                        const { hostnames, name } = row;
                        const hasNoDomain = hostnames.length === 0;
                        /*
                          A row that cannot be navigated to must not LOOK
                          navigable — the reasoning and the measured numbers are
                          at the markup below. The current mandant is the one
                          non-navigable row that stays unmarked: its click closes
                          the panel, so it is not a dead end, and `[aria-current]`
                          already keeps daisyUI's hover rule off it.
                        */
                        const deadEnd = row.href === null && !row.isCurrent;
                        /*
                          The secondary line inherits the row's colour instead of
                          carrying one, because the CURRENT row's colour comes from
                          `menu-active` (near-white on neutral) and a hard-coded
                          muted grey would be dark-on-dark there.

                          `/70` is not a WCAG rescue on white: measured against the
                          built `accr-light` theme, `/60` composites
                          `oklab(0.2 0 0 / 0.6)` to rgb(115,115,115) on `base-100`
                          = 4.74:1, which PASSES AA for normal text (4.5:1). What
                          decides it is the state a REACHABLE row spends time in:
                          daisyUI's hover background is
                          `color-mix(in oklab, var(--color-base-content) 10%, transparent)`
                          = rgb(231,231,231) on white, and the muted `text-xs`
                          hostname line on that background measures 3.83:1 with
                          `/60` (fails AA) against 5.49:1 with `/70` = rgb(91,91,91).
                          `/70` is also the codebase's muted step, so the same
                          information reads the same everywhere.
                        */
                        const marker = (
                            <>
                                <span className="flex items-center gap-2">
                                    <span className="min-w-0 truncate font-medium">{name}</span>
                                    {/*
                                      E5: an inactive mandant's domain resolves
                                      to `null` and 404s, so it is not offered as
                                      a target. E4 and E5 are independent facts,
                                      so a mandant that is both gets both markers
                                      instead of the UI quietly dropping one.
                                    */}
                                    {row.isActive ? null : (
                                        <span className="badge badge-ghost badge-sm shrink-0">{i18n._(t`inaktiv`)}</span>
                                    )}
                                </span>
                                <span
                                    className={
                                        row.isCurrent
                                            ? 'flex items-center gap-2'
                                            : 'flex items-center gap-2 text-base-content/70'
                                    }
                                >
                                    {hasNoDomain ? null : <span className="min-w-0 truncate text-xs">{hostnames.join(', ')}</span>}
                                    {hasNoDomain ? <span className="shrink-0 text-xs">{i18n._(t`keine Domain`)}</span> : null}
                                </span>
                            </>
                        );

                        return (
                            <li key={row.id} className={deadEnd ? 'disabled' : undefined}>
                                {row.href === null ? (
                                    /*
                                      `aria-current` is the honest marker ("you are
                                      here", and it keeps daisyUI's active styling off
                                      the other rows). It does NOT keep the hover rule
                                      off a row: the rule is gated by
                                      `li:not(.menu-title,.disabled) > …:not(…[aria-current]:not([aria-current=false],[aria-current=""])):hover`,
                                      and `aria-current="false"` fails that inner
                                      `:not`, so a NON-navigable row was measured in
                                      Chromium taking `cursor: auto → pointer` and a
                                      background of `base-content` at 10% on hover —
                                      a promise of a click it cannot honour. The
                                      `disabled` marker on the `<li>` above is the
                                      exclusion that same selector offers — and no
                                      other rule of the built CSS mentions
                                      `.disabled` at all, so the row keeps its
                                      padding, radius and colour and only loses
                                      the affordance. The current mandant needs no
                                      marker: `[aria-current]` already excludes it,
                                      and it is not disabled — clicking it closes
                                      the panel. That close is reachable from the
                                      keyboard anyway (Escape / Tab / the trigger),
                                      so the row must not become a focus stop of its
                                      own.
                                    */
                                    <span
                                        aria-current={row.isCurrent ? 'true' : 'false'}
                                        className={
                                            row.isCurrent
                                                ? 'menu-active flex flex-col items-stretch gap-1'
                                                : 'flex flex-col items-stretch gap-1 text-base-content/70'
                                        }
                                        onClick={row.isCurrent ? () => setOpen(false) : undefined}
                                    >
                                        {marker}
                                    </span>
                                ) : (
                                    <a href={row.href} className="flex flex-col items-stretch gap-1">
                                        {marker}
                                    </a>
                                )}
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}
