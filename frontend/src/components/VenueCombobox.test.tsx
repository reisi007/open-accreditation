import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { SWRConfig } from 'swr';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { Venue } from '../api/types';
import { renderWithProviders } from '../test-setup';
import { VenueCombobox } from './VenueCombobox';

const { listVenuesMock, createVenueMock, updateVenueMock, venueStore } = vi.hoisted(() => {
    const venueStore: Venue[] = [];
    return {
        venueStore,
        // A copy per call: SWR keeps the resolved array, and a test that mutates
        // `venueStore` afterwards must not retroactively change what it rendered.
        listVenuesMock: vi.fn(async () => venueStore.map((venue) => ({ ...venue }))),
        createVenueMock: vi.fn(),
        updateVenueMock: vi.fn(),
    };
});

vi.mock('../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/client')>();
    return { ...actual, listVenues: listVenuesMock, createVenue: createVenueMock, updateVenue: updateVenueMock };
});

function makeVenue(overrides: Partial<Venue> & { id: number; name: string }): Venue {
    return {
        is_active: true,
        teams_count: 0,
        events_count: 0,
        created_at: '2026-09-01T10:00:00Z',
        updated_at: '2026-09-01T10:00:00Z',
        ...overrides,
    };
}

function setVenues(venues: Venue[]): void {
    venueStore.splice(0, venueStore.length, ...venues);
}

/**
 * The combobox is a CONTROLLED component (the react-hook-form field feeds
 * `value`/`onChange`), so the harness is the thinnest possible stand-in for
 * `Controller` and lets a test observe the emitted value.
 */
function ControlledCombobox({
    initial = '',
    valueLabel,
    onChangeSpy,
    onBusyChangeSpy,
}: {
    initial?: string;
    valueLabel?: string | null;
    onChangeSpy?: (value: string) => void;
    onBusyChangeSpy?: (busy: boolean) => void;
}) {
    const [value, setValue] = useState(initial);
    return (
        <SWRConfig value={{ provider: () => new Map() }}>
            <VenueCombobox
                label="Spielort"
                value={value}
                valueLabel={valueLabel}
                onChange={(next) => {
                    setValue(next);
                    onChangeSpy?.(next);
                }}
                onBusyChange={onBusyChangeSpy}
            />
        </SWRConfig>
    );
}

function renderCombobox(
    props: {
        initial?: string;
        valueLabel?: string | null;
        onChangeSpy?: (value: string) => void;
        onBusyChangeSpy?: (busy: boolean) => void;
    } = {},
) {
    return renderWithProviders(<ControlledCombobox {...props} />);
}

function combobox(): HTMLElement {
    return screen.getByRole('combobox', { name: 'Spielort' });
}

function listbox(): HTMLElement {
    return screen.getByRole('listbox', { name: 'Spielort' });
}

beforeEach(() => {
    setVenues([]);
});

afterEach(() => {
    vi.clearAllMocks();
});

describe('VenueCombobox — wiring', () => {
    it('wires the ARIA combobox contract to the listbox it controls', async () => {
        setVenues([makeVenue({ id: 1, name: 'Stadion Nord' })]);
        const user = userEvent.setup();
        renderCombobox();

        const input = combobox();
        expect(input).toHaveAttribute('aria-expanded', 'false');
        expect(input).toHaveAttribute('aria-autocomplete', 'list');
        // Collapsed: no listbox in the DOM, so no dangling aria-controls.
        expect(input).not.toHaveAttribute('aria-controls');
        expect(screen.queryByRole('listbox', { name: 'Spielort' })).not.toBeInTheDocument();

        await user.click(input);

        expect(input).toHaveAttribute('aria-expanded', 'true');
        expect(input.getAttribute('aria-controls')).toBe(listbox().id);
        // The label carries the accessible name — no CSS-class dependency.
        expect(input).toHaveAccessibleName('Spielort');
    });

    it('points aria-activedescendant at the keyboard-highlighted option', async () => {
        setVenues([makeVenue({ id: 1, name: 'Stadion Nord' }), makeVenue({ id: 2, name: 'Arena Süd' })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());
        // An open list highlights its first selectable row — the same a native
        // select does — so Enter works without an extra arrow press.
        const firstId = combobox().getAttribute('aria-activedescendant');
        expect(firstId).not.toBeNull();
        expect(document.getElementById(firstId as string)).toHaveTextContent('Stadion Nord');

        await user.keyboard('{ArrowDown}');

        const activeId = combobox().getAttribute('aria-activedescendant');
        expect(activeId).not.toBeNull();
        const active = document.getElementById(activeId as string);
        expect(active).not.toBeNull();
        expect(active).toHaveTextContent('Arena Süd');
        expect(active).toHaveAttribute('aria-selected', 'false');
    });

    it('never highlights an inactive row', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        // The only row is aria-disabled, so there is nothing to highlight and
        // Enter must stay inert (asserted in the inactive-venue block).
        expect(combobox()).not.toHaveAttribute('aria-activedescendant');
    });

    it('renders a chevron affordance so the field does not read as plain text', async () => {
        setVenues([makeVenue({ id: 1, name: 'Stadion Nord' })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        // Decorative only: the accessible control is the combobox input itself,
        // so the icon must not add a second one.
        const chevron = document.querySelector('[aria-hidden="true"] .mdi--unfold-more-horizontal');
        expect(chevron).not.toBeNull();
        expect(screen.getAllByRole('combobox', { name: 'Spielort' })).toHaveLength(1);
    });

    it('shows the selected venue name for an externally set value (the event form team default)', async () => {
        setVenues([makeVenue({ id: 7, name: 'Heimstadion Süd' })]);
        const user = userEvent.setup();
        renderCombobox({ initial: '7' });

        // No effect involved: the displayed text is derived from `value`.
        await waitFor(() => expect(combobox()).toHaveValue('Heimstadion Süd'));

        await user.click(combobox());
        expect(screen.getByRole('option', { name: /Heimstadion Süd/ })).toHaveAttribute('aria-selected', 'true');
    });

    it('shows valueLabel before the list has loaded, so the stored venue never flashes as empty', () => {
        listVenuesMock.mockImplementationOnce(
            () => new Promise(() => {}) as unknown as Promise<Venue[]>,
        );
        renderCombobox({ initial: '7', valueLabel: 'Heimstadion Süd' });

        // The venue list is still in flight; the team resource already carried
        // the resolved name, so the editing form is complete on first paint.
        expect(combobox()).toHaveValue('Heimstadion Süd');
    });

    it('keeps valueLabel visible when the venue list fails to load', async () => {
        listVenuesMock.mockRejectedValueOnce(new ApiError(500, 'Serverfehler', {}));
        const user = userEvent.setup();
        renderCombobox({ initial: '7', valueLabel: 'Heimstadion Süd' });

        await user.click(combobox());

        expect(await screen.findByText('Spielorte konnten nicht geladen werden.')).toBeInTheDocument();
        expect(combobox()).toHaveValue('Heimstadion Süd');
    });
});

describe('VenueCombobox — filtering and selection', () => {
    beforeEach(() => {
        setVenues([
            makeVenue({ id: 1, name: 'Stadion Nord' }),
            makeVenue({ id: 2, name: 'Arena Süd' }),
            makeVenue({ id: 3, name: 'Stadion Süd' }),
        ]);
    });

    it('filters the list case-insensitively while typing', async () => {
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'sTaDiOn');

        // Two substring matches — and, correctly, still a create offer for the
        // literal name "sTaDiOn", which is a DIFFERENT name than both.
        const options = within(listbox()).getAllByRole('option');
        expect(options.map((option) => option.textContent)).toEqual([
            'Stadion Nord',
            'Stadion Süd',
            'sTaDiOn neu anlegen',
        ]);
    });

    it('drops the create offer as soon as the text equals an existing name', async () => {
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Arena Süd');

        const options = within(listbox()).getAllByRole('option');
        expect(options.map((option) => option.textContent)).toEqual(['Arena Süd']);
    });

    it('selects an existing venue and reports its id', async () => {
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Arena Süd');
        await user.click(screen.getByRole('option', { name: /Arena Süd/ }));

        expect(onChangeSpy).toHaveBeenCalledWith('2');
        expect(combobox()).toHaveValue('Arena Süd');
        expect(combobox()).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('listbox', { name: 'Spielort' })).not.toBeInTheDocument();
    });

    it('selects with the keyboard and skips nothing that is selectable', async () => {
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.click(combobox());
        await user.keyboard('{ArrowDown}{Enter}');

        expect(onChangeSpy).toHaveBeenCalledWith('2');
        expect(combobox()).toHaveValue('Arena Süd');
    });

    it('creates with Enter straight after typing, without an extra arrow press', async () => {
        setVenues([]);
        createVenueMock.mockImplementation(async (payload: { name: string }) => makeVenue({ id: 9, name: payload.name }));
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Arena West');
        // The create offer is auto-highlighted as the only selectable row, so
        // Enter must act on it rather than being swallowed.
        expect(combobox().getAttribute('aria-activedescendant')).not.toBeNull();
        await user.keyboard('{Enter}');

        await waitFor(() => expect(createVenueMock).toHaveBeenCalledWith({ name: 'Arena West' }));
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('9'));
    });

    it('reverts uncommitted text on Escape instead of leaving it in the field', async () => {
        const user = userEvent.setup();
        renderCombobox({ initial: '1' });

        await user.click(combobox());
        await user.clear(combobox());
        await user.type(combobox(), 'nirgends');
        expect(combobox()).toHaveValue('nirgends');

        await user.keyboard('{Escape}');

        // The selection is untouched, so the field falls back to its name.
        expect(combobox()).toHaveValue('Stadion Nord');
    });

    it('does not offer a create entry for a name that already exists', async () => {
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Arena Süd');

        expect(screen.queryByRole('option', { name: /neu anlegen/ })).not.toBeInTheDocument();
        expect(screen.getAllByRole('option')).toHaveLength(1);
    });
});

describe('VenueCombobox — inline create', () => {
    it('creates a venue from the typed name and selects the result', async () => {
        setVenues([makeVenue({ id: 1, name: 'Stadion Nord' })]);
        createVenueMock.mockImplementation(async (payload: { name: string }) => {
            const created = makeVenue({ id: 9, name: payload.name });
            setVenues([...venueStore, created]);
            return created;
        });
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Arena West');
        const createOption = screen.getByRole('option', { name: 'Arena West neu anlegen' });
        await user.click(createOption);

        await waitFor(() => expect(createVenueMock).toHaveBeenCalledWith({ name: 'Arena West' }));
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('9'));
        expect(combobox()).toHaveValue('Arena West');
        // The list is refetched so the new row is in the shared SWR cache.
        await waitFor(() => expect(listVenuesMock.mock.calls.length).toBeGreaterThanOrEqual(2));
    });

    it('offers inline create with an EMPTY venue list — the point of the whole control', async () => {
        setVenues([]);
        createVenueMock.mockImplementation(async (payload: { name: string }) => makeVenue({ id: 1, name: payload.name }));
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.click(combobox());
        // The empty state is the entry point, not a dead end.
        expect(within(listbox()).getByText('Noch keine Spielorte vorhanden.')).toBeInTheDocument();
        expect(within(listbox()).getByText('Tippe einen Namen ein, um den Spielort hier anzulegen.')).toBeInTheDocument();

        await user.type(combobox(), 'Arena Ost');

        expect(screen.getByRole('option', { name: 'Arena Ost neu anlegen' })).toBeInTheDocument();
        await user.click(screen.getByRole('option', { name: 'Arena Ost neu anlegen' }));
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('1'));
    });

    it('trims the typed name before creating', async () => {
        setVenues([]);
        createVenueMock.mockImplementation(async (payload: { name: string }) => makeVenue({ id: 1, name: payload.name }));
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), '  Arena Ost  ');
        await user.click(screen.getByRole('option', { name: 'Arena Ost neu anlegen' }));

        await waitFor(() => expect(createVenueMock).toHaveBeenCalledWith({ name: 'Arena Ost' }));
    });
});

describe('VenueCombobox — commit order (the selection must not wait for the list refetch)', () => {
    it('commits the created id while the refetch is still in flight', async () => {
        // The regression: `onChange` used to run only AFTER `await mutate()`, so
        // for a whole round trip the field showed the new venue's name while the
        // form still held `''` — and a save in that window dropped the venue.
        setVenues([]);
        // 1st call = the initial list load, 2nd = the refetch after the create.
        let releaseRefetch: (venues: Venue[]) => void = () => {};
        listVenuesMock
            .mockImplementationOnce(async () => [])
            .mockImplementationOnce(
                () =>
                    new Promise<Venue[]>((resolve) => {
                        releaseRefetch = resolve;
                    }),
            );
        createVenueMock.mockImplementation(async (payload: { name: string }) => makeVenue({ id: 9, name: payload.name }));
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));

        // The POST answered, the refetch has NOT: the id is already committed.
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('9'));
        releaseRefetch([makeVenue({ id: 9, name: 'Arena West' })]);
    });

    it('commits the reactivated id while the refetch is still in flight', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        let releaseRefetch: (venues: Venue[]) => void = () => {};
        listVenuesMock
            .mockImplementationOnce(async () => [makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })])
            .mockImplementationOnce(
                () =>
                    new Promise<Venue[]>((resolve) => {
                        releaseRefetch = resolve;
                    }),
            );
        updateVenueMock.mockImplementation(async (id: number) => makeVenue({ id, name: 'Stadion Ost' }));
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' }));

        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('2'));
        releaseRefetch([makeVenue({ id: 2, name: 'Stadion Ost' })]);
    });
});

describe('VenueCombobox — busy reporting for the owning form', () => {
    it('reports busy for the whole create, including the list refetch, then clears it', async () => {
        setVenues([]);
        let releaseCreate: (venue: Venue) => void = () => {};
        createVenueMock.mockImplementation(
            () =>
                new Promise<Venue>((resolve) => {
                    releaseCreate = resolve;
                }),
        );
        const user = userEvent.setup();
        const onBusyChangeSpy = vi.fn<(busy: boolean) => void>();
        renderCombobox({ onBusyChangeSpy });

        await user.type(combobox(), 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));

        // The form must know the field is not trustworthy yet — otherwise it can
        // be saved with the old (or an empty) value.
        expect(onBusyChangeSpy).toHaveBeenLastCalledWith(true);
        expect(combobox()).toHaveAttribute('aria-busy', 'true');

        releaseCreate(makeVenue({ id: 9, name: 'Arena West' }));

        await waitFor(() => expect(onBusyChangeSpy).toHaveBeenLastCalledWith(false));
        await waitFor(() => expect(combobox()).toHaveAttribute('aria-busy', 'false'));
    });

    it('reports busy for a reactivation and clears it even when the request fails', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        updateVenueMock.mockRejectedValue(new Error('boom'));
        const user = userEvent.setup();
        const onBusyChangeSpy = vi.fn<(busy: boolean) => void>();
        renderCombobox({ onBusyChangeSpy });

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' }));

        expect(onBusyChangeSpy).toHaveBeenCalledWith(true);
        // A failed mutation must unlock the form again, or the save is stuck.
        await waitFor(() => expect(onBusyChangeSpy).toHaveBeenLastCalledWith(false));
    });
});

describe('VenueCombobox — duplicate (422) on the create race', () => {
    it('surfaces the German 422 as a field error, refetches, and lets the admin pick the winner', async () => {
        // The mandant had no venue by the time the list was read, so the
        // combobox offered "create". Meanwhile somebody else POSTed the same
        // name — their row is committed BEFORE our POST is answered, which is
        // exactly why ours comes back 422.
        setVenues([]);
        const duplicate = 'Arena Nord existiert bereits.';
        createVenueMock.mockImplementation(async () => {
            setVenues([makeVenue({ id: 42, name: 'Arena Nord' })]);
            throw new ApiError(422, duplicate, { errors: { name: [duplicate] } });
        });
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Arena Nord');
        await user.click(screen.getByRole('option', { name: 'Arena Nord neu anlegen' }));

        // 1. the message lands on the FIELD, not in a crash or a root alert
        await screen.findByRole('option', { name: /Arena Nord/ });
        const message = screen.getByText(duplicate, { selector: 'span' });
        expect(message).toBeInTheDocument();
        expect(combobox()).toHaveClass('input-error');
        expect(combobox()).toHaveAttribute('aria-describedby', message.id);
        // 2. the dropdown stays open so the admin can act on the fresh list
        expect(combobox()).toHaveAttribute('aria-expanded', 'true');
        // 3. nothing was selected — a failed create must not invent a value
        expect(onChangeSpy).not.toHaveBeenCalled();

        // 4. the refetch brought the winner in, the create offer is gone, and
        //    the admin can simply click the row that now exists.
        const winner = screen.getByRole('option', { name: /Arena Nord/ });
        expect(winner).toHaveAttribute('aria-selected', 'false');
        expect(screen.queryByRole('option', { name: /neu anlegen/ })).not.toBeInTheDocument();
        // 5. the message is ALSO the panel's first line: the field error sits
        //    under the control and would otherwise be hidden by the open list.
        expect(within(listbox()).getByText(duplicate)).toBeInTheDocument();

        await user.click(winner);

        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('42'));
        // 6. and the resolved duplicate no longer haunts the field
        expect(screen.queryByText(duplicate)).not.toBeInTheDocument();
    });

    it('closes the list for a create failure the refetch did NOT resolve, so the field error is readable', async () => {
        // A 422 that is not the "someone else won the race" case (e.g. a
        // validation rule) must not leave an open panel on top of the message.
        setVenues([]);
        createVenueMock.mockRejectedValue(new ApiError(422, 'Der Name ist ungültig.', {}));
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Arena Nord');
        await user.click(screen.getByRole('option', { name: 'Arena Nord neu anlegen' }));

        expect(await screen.findByText('Der Name ist ungültig.')).toBeInTheDocument();
        expect(combobox()).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('listbox', { name: 'Spielort' })).not.toBeInTheDocument();
    });

    it('clears the duplicate error as soon as the admin types again', async () => {
        setVenues([]);
        createVenueMock.mockRejectedValue(new ApiError(422, 'Arena Nord existiert bereits.', {}));
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Arena Nord');
        await user.click(screen.getByRole('option', { name: 'Arena Nord neu anlegen' }));
        expect(await screen.findByText('Arena Nord existiert bereits.')).toBeInTheDocument();

        await user.type(combobox(), ' X');

        await waitFor(() => expect(screen.queryByText('Arena Nord existiert bereits.')).not.toBeInTheDocument());
        expect(combobox()).not.toHaveClass('input-error');
    });

    it('does not offer a create entry for a name that a DEACTIVATED venue already holds', async () => {
        // The race can also resolve onto a name that is present but inactive —
        // creating it again would break "a deactivated name is not re-creatable".
        setVenues([]);
        const duplicate = 'Stadion Ost existiert bereits.';
        createVenueMock.mockImplementation(async () => {
            setVenues([makeVenue({ id: 42, name: 'Stadion Ost', is_active: false })]);
            throw new ApiError(422, duplicate, {});
        });
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('option', { name: 'Stadion Ost neu anlegen' }));

        await screen.findByText(duplicate, { selector: 'span' });
        expect(screen.queryByRole('option', { name: /neu anlegen/ })).not.toBeInTheDocument();
        expect(screen.getByRole('option', { name: /Stadion Ost/ })).toHaveAttribute('aria-disabled', 'true');
    });

    it('falls back to a generic message when the create fails without an ApiError', async () => {
        setVenues([]);
        createVenueMock.mockRejectedValue(new Error('network down'));
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Arena Nord');
        await user.click(screen.getByRole('option', { name: 'Arena Nord neu anlegen' }));

        expect(await screen.findByText('Spielort konnte nicht angelegt werden.')).toBeInTheDocument();
        expect(combobox()).toHaveAttribute('aria-expanded', 'false');
    });
});

describe('VenueCombobox — inactive venues', () => {
    it('shows a deactivated venue greyed, never as a create offer', async () => {
        setVenues([
            makeVenue({ id: 1, name: 'Arena West' }),
            makeVenue({ id: 2, name: 'Stadion Ost', is_active: false, teams_count: 2 }),
        ]);
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Stadion Ost');

        // Re-creation is impossible by design: the name is taken.
        expect(screen.queryByRole('option', { name: /neu anlegen/ })).not.toBeInTheDocument();
        // The option is the NAME element only — an exact name here is what keeps
        // the row's badge and action out of the option's own accessible name.
        const option = screen.getByRole('option', { name: 'Stadion Ost' });
        expect(option).toHaveAttribute('aria-disabled', 'true');
        // Greying and the badge belong to the presentational row wrapper, which
        // is the element that is not an option.
        const row = option.closest('li');
        expect(row).not.toBeNull();
        expect(row?.className).toContain('text-base-content/50');
        expect(row).toHaveTextContent('inaktiv');
        // The reactivate button is the only action an inactive row offers.
        expect(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' })).toBeEnabled();
    });

    it('reactivates an inactive venue via PUT and selects it', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        updateVenueMock.mockImplementation(async (id: number, payload: { is_active?: boolean }) => {
            const reactivated = makeVenue({ id, name: 'Stadion Ost', is_active: payload.is_active ?? true });
            setVenues([reactivated]);
            return reactivated;
        });
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' }));

        await waitFor(() => expect(updateVenueMock).toHaveBeenCalledWith(2, { is_active: true }));
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('2'));
        expect(combobox()).toHaveValue('Stadion Ost');
    });

    it('never selects an inactive venue by clicking its row or pressing Enter', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('option', { name: /Stadion Ost/ }));
        expect(onChangeSpy).not.toHaveBeenCalled();

        await user.keyboard('{Enter}');
        expect(onChangeSpy).not.toHaveBeenCalled();
    });

    it('surfaces a failed reactivation as a field error', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        updateVenueMock.mockRejectedValue(new Error('boom'));
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' }));

        expect(await screen.findByText('Spielort konnte nicht reaktiviert werden.')).toBeInTheDocument();
        // The list stays open: the reactivate failed, so the row is still there
        // to try again. The message sits ON TOP of the panel here (the panel's
        // first line is reserved for a resolved duplicate), so the row must
        // stay reachable — it is the only actionable thing in the dropdown.
        expect(combobox()).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('option', { name: /Stadion Ost/ })).toBeInTheDocument();
    });
});

/**
 * The row structure is a CONTRACT, not an implementation detail: `option` has
 * presentational children, so anything inside an option is flattened to text
 * and — because the inactive row is `aria-disabled` — not activatable at all.
 * These tests exist so the button can never silently move back inside the
 * option, which is what made the E2E click impossible in the first place.
 */
describe('VenueCombobox — listbox ARIA structure (the reactivate action must stay operable)', () => {
    it('keeps the reactivate button out of the aria-disabled subtree', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Stadion Ost');

        const option = screen.getByRole('option', { name: 'Stadion Ost' });
        const button = screen.getByRole('button', { name: 'Stadion Ost reaktivieren' });
        // `option` has presentational children: a button inside it would be
        // announced as part of the option instead of as an operable control.
        expect(option).not.toContainElement(button);
        // The real blocker: Playwright refuses any action inside an
        // `aria-disabled` subtree, so the E2E could not click this button.
        expect(option.closest('[aria-disabled="true"]')).toBe(option);
        expect(button.closest('[aria-disabled="true"]')).toBeNull();
        // The role itself survives, i.e. it is a button and not flattened text.
        expect(button.tagName).toBe('BUTTON');
    });

    it('names an option after the venue alone, so the row action cannot leak into the name', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.type(combobox(), 'Stadion Ost');

        // Exact, not a regex: with the badge and the button inside the option
        // this name was "Stadion Ost inaktiv Stadion Ost reaktivieren".
        expect(screen.getByRole('option', { name: 'Stadion Ost' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /Reaktivieren/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /inaktiv/ })).not.toBeInTheDocument();
    });

    it('does not also select the row when the reactivate button is clicked', async () => {
        setVenues([makeVenue({ id: 2, name: 'Stadion Ost', is_active: false })]);
        updateVenueMock.mockImplementation(async (id: number) => {
            const reactivated = makeVenue({ id, name: 'Stadion Ost' });
            setVenues([reactivated]);
            return reactivated;
        });
        const user = userEvent.setup();
        const onChangeSpy = vi.fn<(value: string) => void>();
        renderCombobox({ onChangeSpy });

        await user.type(combobox(), 'Stadion Ost');
        await user.click(screen.getByRole('button', { name: 'Stadion Ost reaktivieren' }));

        // The click bubbles to the row, which is selectable for active venues
        // only — so exactly ONE selection happens, and it is the reactivation's.
        await waitFor(() => expect(onChangeSpy).toHaveBeenCalledWith('2'));
        expect(onChangeSpy).toHaveBeenCalledTimes(1);
        expect(combobox()).toHaveValue('Stadion Ost');
    });

    it('marks only inactive options aria-disabled', async () => {
        setVenues([
            makeVenue({ id: 1, name: 'Arena West' }),
            makeVenue({ id: 2, name: 'Stadion Ost', is_active: false }),
        ]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        expect(screen.getByRole('option', { name: 'Arena West' })).not.toHaveAttribute('aria-disabled');
        expect(screen.getByRole('option', { name: 'Stadion Ost' })).toHaveAttribute('aria-disabled', 'true');
    });

    it('points aria-activedescendant at the option element, not at the row wrapper', async () => {
        setVenues([makeVenue({ id: 1, name: 'Stadion Nord' })]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        const activeId = combobox().getAttribute('aria-activedescendant');
        expect(activeId).not.toBeNull();
        const active = document.getElementById(activeId as string);
        // The reference has to resolve to the OPTION — that is the element the
        // listbox owns, so a wrapper with `role="presentation"` would break it.
        expect(active).toHaveAttribute('role', 'option');
        expect(active).toHaveTextContent('Stadion Nord');
        expect(active?.closest('li')).toHaveAttribute('role', 'presentation');
    });

    it('wraps every row in a presentational li and puts role=option on the name alone', async () => {
        // Both branches, so the create offer cannot drift back into the other
        // structure: an active venue row AND the inline-create row.
        setVenues([makeVenue({ id: 1, name: 'Arena West' })]);
        const user = userEvent.setup();
        renderCombobox();

        // "Arena" matches the active venue AND — not being an existing name —
        // still offers the create row, so both branches are present at once.
        await user.type(combobox(), 'Arena');

        const options = within(listbox()).getAllByRole('option');
        expect(options.map((option) => option.textContent)).toEqual(['Arena West', 'Arena neu anlegen']);
        for (const option of options) {
            // The option holds the name and nothing else.
            expect(option.children).toHaveLength(0);
            // Its row wrapper is presentational: the listbox owns the option,
            // not a listitem.
            expect(option.closest('li')).toHaveAttribute('role', 'presentation');
        }
    });
});

describe('VenueCombobox — list failures and the empty state', () => {
    it('shows the empty state and still allows typing when the list is empty', async () => {
        setVenues([]);
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        expect(screen.getByText('Noch keine Spielorte vorhanden.')).toBeInTheDocument();
        expect(combobox()).toHaveAttribute('aria-expanded', 'true');
    });

    it('reports a failed list load without disabling the inline create', async () => {
        listVenuesMock.mockRejectedValueOnce(new ApiError(500, 'Serverfehler', {}));
        const user = userEvent.setup();
        renderCombobox();

        await user.click(combobox());

        expect(await screen.findByText('Spielorte konnten nicht geladen werden.')).toBeInTheDocument();
        // Unknown list ⇒ we cannot know the name is taken, so creating stays
        // available; a duplicate would come back as a 422 field error.
        await user.type(combobox(), 'Arena Nord');
        expect(screen.getByRole('option', { name: 'Arena Nord neu anlegen' })).toBeInTheDocument();
    });

    it('keeps a non-listbox element out of the option set (no "Keine Treffer" dead end)', async () => {
        setVenues([makeVenue({ id: 1, name: 'Arena Süd' })]);
        const user = userEvent.setup();
        renderCombobox({ initial: '1' });

        await user.click(combobox());
        await user.keyboard('{Escape}');

        // With the field closed and a selection intact the dropdown is gone.
        expect(screen.queryByText('Keine Treffer.')).not.toBeInTheDocument();
    });
});
