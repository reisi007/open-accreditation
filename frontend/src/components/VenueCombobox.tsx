import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useId, useRef, useState, type FocusEvent, type KeyboardEvent } from 'react';
import { ApiError, createVenue, updateVenue } from '../api/client';
import type { Venue } from '../api/types';
import { useVenues } from '../logic/useVenues';

interface VenueComboboxProps {
    /** Label text; also the `aria-label` of the listbox. */
    label: string;
    /** Selected venue id as a string, `''` for "no venue". */
    value: string;
    onChange: (value: string) => void;
    /**
     * Resolved name of `value`, taken from the team/event resource
     * (`TeamResource.venue.name` / `EventResource.venue.name`). Used until the
     * venue list has loaded, and as the fallback when the list request fails —
     * an editing form must not flash an empty field for the stored value just
     * because a second request is still in flight.
     */
    valueLabel?: string | null;
    /** Field-level error from the form resolver (rendered under the control). */
    error?: string | null;
    disabled?: boolean;
    /**
     * Called with `true` while a venue create or reactivation is in flight, and
     * `false` once it settled, so the owning form can refuse a submit.
     *
     * Why the form needs this: the field only learns the new id when the request
     * answers, and it shows the typed NAME long before that. A save inside that
     * window would persist an empty reference (team form) or the previously
     * defaulted one (event form) behind a field that already looks committed.
     */
    onBusyChange?: (busy: boolean) => void;
    /**
     * Optional stable `id` for the input. Defaults to a `useId()` value. The
     * `<label htmlFor>` is rendered from it, so an E2E locator is
     * `getByLabel(...)` — never a CSS class.
     */
    inputId?: string;
    /**
     * The mandant this form WRITES to, for a page that addresses one by URL
     * (`MandantDetailPage`). `null` (the default) keeps the host-scoped surface,
     * which is what every host-relative page (categories, events,
     * accreditations) wants.
     *
     * It has to reach the create/reactivate calls, not just the list: the
     * mandant-scoped LIST alone would still let the inline create post to the
     * host and place the row in the wrong tenant.
     */
    mandantId?: number | null;
}

/** One row of the listbox: either a mandant venue or the inline-create entry. */
type VenueOption = { kind: 'venue'; venue: Venue } | { kind: 'create'; name: string };

/**
 * An inactive venue can be re-activated but never re-created, so it is never
 * "selectable" in the keyboard sense — the explicit reactivate button inside
 * the row is the only action.
 */
function isSelectable(option: VenueOption): boolean {
    return option.kind === 'create' || option.venue.is_active;
}

/**
 * Steps `current` by `step` over the selectable options, wrapping around.
 * Inactive rows are skipped so the keyboard path never lands on a dead end.
 */
function moveActiveIndex(current: number, step: number, options: VenueOption[]): number {
    if (options.length === 0) {
        return -1;
    }

    let index = current;
    for (let attempt = 0; attempt < options.length; attempt += 1) {
        index = (index + step + options.length) % options.length;
        if (isSelectable(options[index])) {
            return index;
        }
    }

    return current;
}

/** The row a freshly opened / freshly filtered list highlights: the first
 *  selectable one. `null` when the list has none (empty, or all inactive). */
function firstSelectableIndex(options: VenueOption[]): number | null {
    for (let index = 0; index < options.length; index += 1) {
        if (isSelectable(options[index])) {
            return index;
        }
    }

    return null;
}

/**
 * Mandant-scoped venue picker with inline create.
 *
 * Why a combobox and not a `<select>`: the venue is created MANUALLY, and the
 * place an admin needs that affordance is inside the team/event form. A
 * select-only dropdown would leave a mandant with zero venues unable to set a
 * home venue at all. So the dropdown offers `create <name>` whenever the typed
 * text matches no existing venue — including when the list is completely empty.
 *
 * Inactive venues stay visible (greyed, `aria-disabled`) with a reactivate
 * button: deactivation is the reversible way to retire a referenced venue, so
 * a retired name must never silently disappear and become re-creatable.
 *
 * Row structure (and why it is not the obvious one): the `option` role has
 * PRESENTATIONAL CHILDREN — every descendant is treated as plain text of the
 * option. An action button inside the option would therefore (a) be folded
 * into the option's accessible name ("Stadion Ost inaktiv Reaktivieren")
 * instead of staying a button, and (b) be unreachable: the row is
 * `aria-disabled`, and an `aria-disabled` subtree is not activatable (Playwright
 * refuses the click outright, so the E2E could not drive it at all). So each
 * `<li>` is `role="presentation"` and carries the LAYOUT only, the `option` is
 * the name `<span>`, and the badge + the button are that span's SIBLINGS.
 * `presentation` (not `none` — the two are exact synonyms per ARIA 1.2, and the
 * spec asks authors to use `presentation` alone) drops the `<li>`'s own
 * `listitem` semantics while leaving every descendant role intact; see the
 * ARIA spec's own `<ul role="tree"><li role="presentation"><a role="treeitem">`
 * example. Measured in Chromium: with `presentation` the listbox's exposed
 * children are exactly `option` + `button`; without it the option role is lost.
 */
export function VenueCombobox({
    label,
    value,
    onChange,
    valueLabel,
    error,
    disabled = false,
    onBusyChange,
    inputId,
    mandantId = null,
}: VenueComboboxProps) {
    const { i18n } = useLingui();
    const { venues, isLoading, error: listError, mutate } = useVenues(mandantId);
    const generatedId = useId();
    const fieldId = inputId ?? `${generatedId}-venue`;
    const listboxId = `${fieldId}-listbox`;
    const errorId = `${fieldId}-error`;

    const wrapperRef = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [storedActiveIndex, setStoredActiveIndex] = useState<number | null>(null);
    /**
     * A venue create or reactivation is in flight. `pendingId` says WHICH row is
     * reactivating; this says that the field's value is not trustworthy yet, so
     * it drives `aria-busy` and the submit lock of the owning form.
     */
    const [busy, setBusy] = useState(false);
    const [pendingId, setPendingId] = useState<number | null>(null);
    const [localError, setLocalError] = useState<string | null>(null);
    /**
     * The message of a create failure the refetch RESOLVED (the name is now a
     * row in the list). Rendered as the panel's first line, because the field
     * error lives under the control and the open panel would cover it. Kept
     * apart from `localError` on purpose: "the name now exists in the list" is
     * also true while a reactivation is failing, and repeating THAT message
     * inside the panel would bury the row the admin has to retry.
     */
    const [raceNotice, setRaceNotice] = useState<string | null>(null);
    /**
     * The uncommitted text in the input. `null` means "show the selected
     * venue's name" — so an externally changed `value` (the event form
     * defaulting the venue from the team) is reflected without an effect, and
     * closing the dropdown reverts typed text instead of leaving it behind.
     */
    const [draft, setDraft] = useState<string | null>(null);

    const all = venues ?? [];
    const selected = all.find((venue) => String(venue.id) === value) ?? null;
    // The list wins over `valueLabel` (it is the live source), but `valueLabel`
    // covers the window before the list arrives and the case where it failed.
    const display = draft ?? selected?.name ?? (value === '' ? '' : (valueLabel ?? ''));

    const needle = display.trim().toLowerCase();
    const activeMatches = all.filter((venue) => venue.is_active && venue.name.toLowerCase().includes(needle));
    const inactiveMatches = all.filter((venue) => !venue.is_active && venue.name.toLowerCase().includes(needle));
    // A name that already exists — active OR inactive — must never offer a
    // create entry: that is what would make a deactivated name re-creatable.
    const nameTaken = all.some((venue) => venue.name.trim().toLowerCase() === needle);
    const canCreate = needle !== '' && !nameTaken;

    const options: VenueOption[] = [
        ...activeMatches.map((venue): VenueOption => ({ kind: 'venue', venue })),
        ...(canCreate ? [{ kind: 'create' as const, name: display.trim() }] : []),
        ...inactiveMatches.map((venue): VenueOption => ({ kind: 'venue', venue })),
    ];

    // The highlighted row is DERIVED: `null` in the state means "the first
    // selectable row", which is what an open or freshly filtered list must
    // highlight. That also keeps Enter working straight after typing — a
    // create offer with nothing highlighted would swallow the key. A stored
    // index that outlived a shrunk list falls back the same way, so
    // aria-activedescendant can never point at a row that is gone.
    const activeIndex = storedActiveIndex === null ? firstSelectableIndex(options) : (options[storedActiveIndex] === undefined ? firstSelectableIndex(options) : storedActiveIndex);
    const activeOption = activeIndex === null ? null : options[activeIndex];
    const activeOptionId = activeIndex === null ? undefined : `${listboxId}-option-${activeIndex}`;
    const showError = error !== undefined && error !== null && error !== '' ? error : localError;

    const close = () => {
        setOpen(false);
        setStoredActiveIndex(null);
        setDraft(null);
        setLocalError(null);
        setRaceNotice(null);
    };

    /**
     * One flag for every in-flight venue mutation, mirrored to the parent so it
     * can lock its submit. Driven from the mutation handlers and never from an
     * effect: it is the consequence of a user action, not state derived during
     * render.
     */
    const setPending = (pending: boolean) => {
        setBusy(pending);
        onBusyChange?.(pending);
    };

    const selectVenue = (venue: Venue) => {
        setDraft(venue.name);
        onChange(String(venue.id));
        setOpen(false);
        setStoredActiveIndex(null);
        // A resolved duplicate must not leave its 422 hanging under the field.
        setLocalError(null);
        setRaceNotice(null);
    };

    /**
     * Inline create. On a 422 (someone else created the same name between the
     * list load and this POST) the German API message is surfaced as the
     * field-level error AND the list is refetched, so the freshly created
     * venue appears as a normal option the admin can simply click.
     */
    const handleCreate = async (name: string) => {
        setPending(true);
        setLocalError(null);
        setRaceNotice(null);
        try {
            const created = await createVenue({ name }, mandantId);
            // The selection is committed HERE, not after the refetch: the id
            // exists, and the refetch only refreshes the shared list. Awaiting
            // it first left the field showing the name while the form still held
            // `''`, so a save in that window dropped the venue.
            selectVenue(created);
            await mutate();
        } catch (err) {
            const message = err instanceof ApiError ? err.message : i18n._(t`Spielort konnte nicht angelegt werden.`);
            setLocalError(message);
            // Refetch on failure too: the most likely cause is a concurrent
            // create, and the refetch is what turns the error into a choice.
            const fresh = await mutate();
            // Keep the list open ONLY when the refetch actually resolved the
            // race (the name is now a row the admin can click). For every other
            // failure — validation, network, 500 — close it, so the field error
            // is not hidden behind an open panel.
            const resolved = (fresh ?? []).some(
                (venue) => venue.name.trim().toLowerCase() === name.trim().toLowerCase(),
            );
            setOpen(resolved);
            setStoredActiveIndex(null);
            if (resolved) {
                setRaceNotice(message);
            }
        } finally {
            setPending(false);
        }
    };

    const handleReactivate = async (venue: Venue) => {
        setPendingId(venue.id);
        setPending(true);
        setLocalError(null);
        setRaceNotice(null);
        try {
            const reactivated = await updateVenue(venue.id, { is_active: true }, mandantId);
            // Same ordering rule as the create: the id is known, so it is
            // committed before the list refresh.
            selectVenue(reactivated);
            await mutate();
        } catch (err) {
            setLocalError(
                err instanceof ApiError ? err.message : i18n._(t`Spielort konnte nicht reaktiviert werden.`),
            );
        } finally {
            setPendingId(null);
            setPending(false);
        }
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                return;
            }
            setStoredActiveIndex(moveActiveIndex(activeIndex ?? 0, event.key === 'ArrowDown' ? 1 : -1, options));
            return;
        }

        if (event.key === 'Enter') {
            if (!open || activeOption === null || !isSelectable(activeOption)) {
                return;
            }
            event.preventDefault();
            if (activeOption.kind === 'create') {
                void handleCreate(activeOption.name);
                return;
            }
            selectVenue(activeOption.venue);
            return;
        }

        if (event.key === 'Escape') {
            if (!open) {
                return;
            }
            event.preventDefault();
            close();
            return;
        }

        if (event.key === 'Tab') {
            close();
        }
    };

    /**
     * Keep the dropdown open while focus moves to the reactivate button inside
     * it, and close it when focus really leaves. The click path never gets
     * here: `onMouseDown` on the listbox is prevented so the input keeps focus
     * and the click lands on the option.
     */
    const handleBlur = (event: FocusEvent<HTMLInputElement>) => {
        const next = event.relatedTarget;
        if (next instanceof Node && wrapperRef.current?.contains(next)) {
            return;
        }
        close();
    };

    return (
        <div className="form-control">
            <label className="label" htmlFor={fieldId}>
                <span className="label-text">{label}</span>
            </label>
            <div
                ref={wrapperRef}
                className={open ? 'dropdown dropdown-open w-full' : 'dropdown w-full'}
                onBlur={handleBlur}
            >
                <input
                    id={fieldId}
                    type="text"
                    role="combobox"
                    className={showError ? 'input input-error w-full pr-10' : 'input w-full pr-10'}
                    autoComplete="off"
                    placeholder={i18n._(t`Spielort suchen oder anlegen`)}
                    aria-expanded={open}
                    aria-controls={open ? listboxId : undefined}
                    aria-autocomplete="list"
                    aria-activedescendant={activeOptionId}
                    aria-describedby={showError ? errorId : undefined}
                    aria-busy={busy}
                    disabled={disabled}
                    value={display}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setLocalError(null);
                        setRaceNotice(null);
                        setOpen(true);
                        setStoredActiveIndex(null);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={handleKeyDown}
                />
                {/*
                  A chevron is the only signal that this input opens a list —
                  without it the field reads as a plain text input and the
                  inline create is undiscoverable. Purely decorative: the input
                  itself carries `role="combobox"`, so the icon is hidden from
                  assistive tech instead of adding a second control.
                */}
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-base-content/50"
                >
                    <span className="iconify mdi--unfold-more-horizontal"></span>
                </span>
                {open ? (
                    <ul
                        id={listboxId}
                        role="listbox"
                        aria-label={label}
                        className="dropdown-content menu z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-box bg-base-100 p-2 shadow"
                        onMouseDown={(event) => event.preventDefault()}
                    >
                        {/*
                          The field error lives UNDER the control, so an open
                          panel would cover it. Repeating it as the panel's first
                          line is what makes a resolved duplicate readable while
                          the list is still open.
                        */}
                        {raceNotice !== null ? (
                            <li className="px-2 pb-2 text-sm font-medium text-error">{raceNotice}</li>
                        ) : null}

                        {isLoading ? (
                            <li className="px-2 py-3">
                                <span className="loading loading-spinner loading-sm"></span>
                            </li>
                        ) : null}

                        {listError ? (
                            <li className="px-2 py-2 text-sm text-error">
                                {i18n._(t`Spielorte konnten nicht geladen werden.`)}
                            </li>
                        ) : null}

                        {!isLoading && !listError && all.length === 0 ? (
                            <>
                                <li className="px-2 py-2 text-sm font-medium">
                                    {i18n._(t`Noch keine Spielorte vorhanden.`)}
                                </li>
                                <li className="px-2 pb-2 text-sm text-base-content/70">
                                    {i18n._(t`Tippe einen Namen ein, um den Spielort hier anzulegen.`)}
                                </li>
                            </>
                        ) : null}

                        {options.map((option, index) => {
                            const optionId = `${listboxId}-option-${index}`;
                            if (option.kind === 'create') {
                                // Destructured for the Lingui macro: a member
                                // expression inside `t` is a lint error.
                                const { name } = option;
                                return (
                                    <li
                                        key="create"
                                        role="presentation"
                                        className={optionClasses(index === activeIndex, true)}
                                        onClick={() => void handleCreate(name)}
                                    >
                                        {busy && pendingId === null ? (
                                            <span className="loading loading-spinner loading-xs"></span>
                                        ) : (
                                            <span className="iconify mdi--plus text-lg"></span>
                                        )}
                                        <span
                                            id={optionId}
                                            role="option"
                                            aria-selected={false}
                                            className="flex-1 truncate"
                                        >
                                            {i18n._(t`${name} neu anlegen`)}
                                        </span>
                                    </li>
                                );
                            }

                            const { venue } = option;
                            const { name } = venue;
                            return (
                                <li
                                    key={venue.id}
                                    role="presentation"
                                    className={optionClasses(index === activeIndex, venue.is_active)}
                                    onClick={() => {
                                        if (venue.is_active) {
                                            selectVenue(venue);
                                        }
                                    }}
                                >
                                    <span
                                        id={optionId}
                                        role="option"
                                        aria-selected={String(venue.id) === value}
                                        aria-disabled={venue.is_active ? undefined : true}
                                        className="flex-1 truncate"
                                    >
                                        {venue.name}
                                    </span>
                                    {venue.is_active ? null : (
                                        <>
                                            <span className="badge badge-ghost badge-sm">{i18n._(t`inaktiv`)}</span>
                                            <button
                                                type="button"
                                                className="btn btn-xs btn-outline"
                                                disabled={pendingId === venue.id}
                                                /*
                                                  Venue FIRST on purpose — an
                                                  audit read the capitalisation
                                                  gap ("Reaktivieren" vs the
                                                  trailing "reaktivieren") as a
                                                  WCAG 2.5.3 failure. It is not
                                                  one: that criterion is
                                                  case-insensitive, and the W3C
                                                  Understanding doc says
                                                  capitalisation "is not
                                                  relevant when evaluating this
                                                  criterion". The venue also has
                                                  to stay: several inactive
                                                  venues render identical
                                                  buttons, so the name is the
                                                  only thing telling them apart.
                                                  See VenueCombobox.test.tsx.
                                                */
                                                aria-label={i18n._(t`${name} reaktivieren`)}
                                                onClick={() => void handleReactivate(venue)}
                                            >
                                                {pendingId === venue.id ? (
                                                    <span className="loading loading-spinner loading-xs"></span>
                                                ) : (
                                                    i18n._(t`Reaktivieren`)
                                                )}
                                            </button>
                                        </>
                                    )}
                                </li>
                            );
                        })}

                        {!isLoading && !listError && all.length > 0 && options.length === 0 ? (
                            <li className="px-2 py-2 text-sm text-base-content/70">{i18n._(t`Keine Treffer.`)}</li>
                        ) : null}
                    </ul>
                ) : null}
            </div>
            {showError ? (
                <span id={errorId} className="label-text-alt mt-1 text-error">
                    {showError}
                </span>
            ) : null}
        </div>
    );
}

/**
 * Static class strings only — the JIT cannot see a concatenation. An
 * inactive venue is greyed unconditionally; the keyboard highlight is
 * orthogonal to that and only ever applies to a selectable row.
 *
 * Deliberately NOT `menu-active`: daisyUI pairs it with
 * `--menu-active-fg: var(--color-neutral-content)` (near-white), so overriding
 * only its background with `bg-base-200` would leave near-white text on a
 * light panel. The highlight is expressed with the background alone.
 */
function optionClasses(isKeyboardActive: boolean, isSelectableRow: boolean): string {
    if (!isSelectableRow) {
        return 'flex flex-row items-center gap-2 px-2 text-base-content/50';
    }

    return isKeyboardActive
        ? 'flex flex-row items-center gap-2 bg-base-200 px-2'
        : 'flex flex-row items-center gap-2 px-2';
}
