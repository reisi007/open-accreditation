import { screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import type { Accreditation, AdminUser, BadgeTemplate, Event } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { AccreditationsPage } from './AccreditationsPage';
import { BadgeTemplatesPage } from './BadgeTemplatesPage';
import { UsersPage } from './UsersPage';
import { EventsPage } from './EventsPage';

/**
 * Layout contract of the four admin tables, first Vision-Loop 2026-09-28.
 *
 * These assertions are about CLASSES, not pixels — jsdom has no layout engine,
 * so what can be pinned here is *which rule the component asks for*. The
 * measurable half (does the header actually fit, is the action button inside
 * the viewport) lives in `tests/e2e/admin-mobile-layout.spec.ts`, which runs
 * in a real browser. Both halves are needed: a class that is never applied
 * passes this file, and a browser that would be fine with a missing class
 * passes that one.
 *
 * Grouped per finding because the fixes are independent — a failure names the
 * finding it regressed.
 */

const { listBadgeTemplatesMock, listUsersMock, listEventsMock, listAdminAccreditationsMock, userList, eventList, accreditationList, templateList } =
    vi.hoisted(() => {
        const userList: AdminUser[] = [];
        const eventList: Event[] = [];
        const accreditationList: Accreditation[] = [];
        const templateList: BadgeTemplate[] = [];
        return {
            userList,
            eventList,
            accreditationList,
            templateList,
            listBadgeTemplatesMock: vi.fn(async () => templateList),
            listUsersMock: vi.fn(async () => userList),
            listEventsMock: vi.fn(async () => eventList),
            // `AccreditationsPage` reads `listAdminAccreditations` (the
            // filter-aware one), NOT `listAccreditations` — mocking the wrong
            // export leaves the real fetcher running and the page renders its
            // error alert, which is exactly the "vacuously green" trap.
            listAdminAccreditationsMock: vi.fn(async () => accreditationList),
        };
    });

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        listBadgeTemplates: listBadgeTemplatesMock,
        listUsers: listUsersMock,
        listEvents: listEventsMock,
        listAdminAccreditations: listAdminAccreditationsMock,
    };
});

function renderIsolated(ui: React.ReactElement) {
    return renderWithProviders(<SWRConfig value={{ provider: () => new Map() }}>{ui}</SWRConfig>);
}

// The fixture arrays are module-scoped (they have to be, for `vi.hoisted`), so
// they accumulate across tests unless they are emptied here. Without this the
// row-count assertions would see the previous test's rows — a test that passes
// for the wrong reason.
beforeEach(() => {
    templateList.length = 0;
    userList.length = 0;
    eventList.length = 0;
    accreditationList.length = 0;
});

function cellOf(row: HTMLElement, header: string): HTMLElement {
    const table = row.closest('table');
    expect(table).not.toBeNull();
    if (table === null) throw new Error('no table');
    const headers = within(table).getAllByRole('columnheader');
    const index = headers.findIndex((th) => th.textContent?.trim() === header);
    expect(index, `column "${header}" exists`).toBeGreaterThanOrEqual(0);
    const cells = within(row).getAllByRole('cell');
    const cell = cells[index];
    expect(cell, `row has a cell for "${header}"`).toBeDefined();
    if (cell === undefined) throw new Error('no cell');
    return cell;
}

function makeTemplate(id: number, name: string): BadgeTemplate {
    return {
        id,
        name,
        is_default: false,
        layout: [{ field: 'name', x: 10, y: 10, w: 80, h: 10, size: 14, align: 'left' }],
        created_at: '2026-09-01T00:00:00Z',
        updated_at: '2026-09-01T00:00:00Z',
    } as unknown as BadgeTemplate;
}

describe('P6 — badge template actions are reachable on mobile', () => {
    it('renders the actions cell only from lg up and repeats the buttons in a full-width row below', async () => {
        templateList.push(makeTemplate(1, 'Standard-Ausweis'));

        renderIsolated(<BadgeTemplatesPage />);

        const row = (await screen.findByRole('row', { name: /Standard-Ausweis/ })) as HTMLElement;

        // The `Aktionen` HEADER is gone below lg: the column would otherwise be
        // the one that pushes the table past the viewport.
        const actionsHeader = within(row.closest('table') as HTMLElement)
            .getAllByRole('columnheader')
            .find((th) => th.textContent?.trim() === 'Aktionen');
        expect(actionsHeader).toBeDefined();
        expect(actionsHeader?.className).toContain('hidden');
        expect(actionsHeader?.className).toContain('lg:table-cell');

        // Two `<tr>`s per template: the data row, then the mobile action row.
        // A single row could not host a block below its own cells.
        const body = row.closest('tbody') as HTMLElement;
        const rows = within(body).getAllByRole('row');
        expect(rows).toHaveLength(2);

        const actionRow = rows[1] as HTMLElement;
        expect(actionRow.className).toContain('lg:hidden');
        // One cell spanning the row — that is what keeps the buttons inside the
        // table's own width instead of behind the horizontal scroll.
        const actionCells = within(actionRow).getAllByRole('cell');
        expect(actionCells).toHaveLength(1);
        expect(actionCells[0]?.className).toContain('w-full');

        // Both controls are real, named buttons in the mobile row.
        expect(within(actionRow).getByRole('button', { name: 'Bearbeiten' })).toBeInTheDocument();
        expect(within(actionRow).getByRole('button', { name: 'Löschen' })).toBeInTheDocument();

        // … and the desktop cell still carries them for lg up.
        const dataRow = rows[0] as HTMLElement;
        const actionsCell = cellOf(dataRow, 'Aktionen');
        expect(actionsCell.className).toContain('hidden');
        expect(actionsCell.className).toContain('lg:table-cell');
        expect(within(actionsCell).getByRole('button', { name: 'Bearbeiten' })).toBeInTheDocument();
    });

    it('keeps the mobile row bound to its own template, not a shared one', async () => {
        templateList.push(makeTemplate(1, 'Erster'), makeTemplate(2, 'Zweiter'));

        const user = (await import('@testing-library/user-event')).default;
        const u = user.setup();
        renderIsolated(<BadgeTemplatesPage />);

        const firstRow = (await screen.findByRole('row', { name: /Zweiter/ })) as HTMLElement;
        const body = firstRow.closest('tbody') as HTMLElement;
        const rows = within(body).getAllByRole('row');
        // Newest first: the row found above is "Zweiter", its action row is next.
        expect(rows).toHaveLength(4);
        const actionRowOfZweiter = rows[1] as HTMLElement;

        await u.click(within(actionRowOfZweiter).getByRole('button', { name: 'Bearbeiten' }));

        // It must open THAT template, not the first one — two identical button
        // pairs are only safe if each closure captures its own row. The name
        // lives in the form's `Name` INPUT (not in text content), so the value
        // is what identifies which template was opened.
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('Zweiter');
    });
});

describe('P8 — a date never wraps mid-number', () => {
    it('puts whitespace-nowrap on both the Frist header and its cell', async () => {
        accreditationList.push({
            id: 1,
            quota: 5,
            available: 2,
            active: true,
            scope: 'season',
            category: { id: 1, name: 'Presse' },
            deadline_start: '2026-09-23',
            deadline_end: '2026-10-28',
        } as unknown as Accreditation);

        renderIsolated(<AccreditationsPage />);

        const row = (await screen.findByRole('row', { name: /Presse/ })) as HTMLElement;
        const frist = cellOf(row, 'Frist');

        // The measured defect: "2026-09-23 – 2026-10-28" over FIVE lines, because
        // an ISO date's only break points are its hyphens.
        expect(frist.className).toContain('whitespace-nowrap');
        expect(frist).toHaveTextContent('2026-09-23 – 2026-10-28');

        const header = within(row.closest('table') as HTMLElement)
            .getAllByRole('columnheader')
            .find((th) => th.textContent?.trim() === 'Frist');
        expect(header?.className).toContain('whitespace-nowrap');
    });

    it('puts whitespace-nowrap on the Datum and Frist cells of the events table', async () => {
        eventList.push({
            id: 1,
            title: 'Endspiel',
            date: '2026-11-27',
            active: true,
            scope: 'season',
            deadline_start: '2026-10-01',
            deadline_end: '2026-11-01',
        } as unknown as Event);

        renderIsolated(<EventsPage />);

        const row = (await screen.findByRole('row', { name: /Endspiel/ })) as HTMLElement;
        expect(cellOf(row, 'Datum').className).toContain('whitespace-nowrap');
        expect(cellOf(row, 'Frist').className).toContain('whitespace-nowrap');
    });
});

describe('P9 / P11 — free-text columns truncate instead of exploding the row', () => {
    it('truncates a long category name and keeps it in the title attribute', async () => {
        const longCategory = 'E2E Akkreditierung w0-p86470-1790548253933';
        accreditationList.push({
            id: 1,
            quota: 5,
            available: 2,
            active: true,
            scope: 'season',
            category: { id: 1, name: longCategory },
        } as unknown as Accreditation);

        renderIsolated(<AccreditationsPage />);

        const row = (await screen.findByRole('row', { name: new RegExp(longCategory.slice(0, 20)) })) as HTMLElement;
        const kategorie = cellOf(row, 'Kategorie');

        // The pattern the Event/Team cell already used, now on the category cell.
        expect(kategorie.className).toContain('min-w-0');
        expect(kategorie.className).toContain('max-w-48');
        const label = within(kategorie).getByText(longCategory);
        expect(label.className).toContain('truncate');
        // The full name stays reachable — truncate must not lose information.
        expect(label).toHaveAttribute('title', longCategory);
    });

    it('caps the e-mail column at max-w-48 so a seeded address cannot force the scroll', async () => {
        const email = 'approve-1790548254011-7p09eo@example.test';
        userList.push({
            id: 1,
            name: 'Max Mustermann',
            email,
            roles: [{ role: { slug: 'user', name: 'User' }, mandant_id: null, team_id: null, team: null }],
        });

        renderIsolated(<UsersPage />);

        const row = (await screen.findByRole('row', { name: /Max Mustermann/ })) as HTMLElement;
        const cell = cellOf(row, 'E-Mail');
        expect(cell.className).toContain('min-w-0');
        expect(cell.className).toContain('max-w-48');
        // The old `max-w-72` (288 px) kept a long fragment visible and still ran
        // past a 480 px viewport.
        expect(cell.className).not.toContain('max-w-72');
        const label = within(cell).getByText(email);
        expect(label).toHaveAttribute('title', email);
    });
});
