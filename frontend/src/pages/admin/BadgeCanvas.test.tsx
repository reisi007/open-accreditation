import { fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { BadgeCanvas } from './BadgeCanvas';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    CANVAS_GRID_STEP_MM,
    type BadgeRowValues,
} from './badgeTemplateFormUtils';

/**
 * Canvas contract of the „Raster + konfigurierbare Labels" editor (decision
 * 2026-09-27, features/badge-template-editor.md).
 *
 * The canvas is a READ-ONLY preview that only carries selection: the geometry
 * is typed in mm in `BadgePropertiesPanel`. These tests are the guard rail
 * against the superseded interaction creeping back in — every test in the
 * "no drag interaction" block fails if pointer-drag, corner resize handles,
 * arrow-key nudge or the magnetic alignment guides return.
 */

function row(overrides: Partial<BadgeRowValues> = {}): BadgeRowValues {
    return {
        field: 'name',
        x: 0,
        y: 0,
        w: 40,
        h: 8,
        size: 12,
        align: 'left',
        srcKind: 'none',
        srcRef: 'logo',
        imageId: 0,
        fit: 'contain',
        ...overrides,
    };
}

function renderCanvas(rows: BadgeRowValues[], selectedIndex: number | null = null) {
    const onSelect = vi.fn();
    renderWithProviders(
        <BadgeCanvas rows={rows} selectedIndex={selectedIndex} overlapIndices={new Set()} onSelect={onSelect} />,
    );
    return { onSelect };
}

/** The canvas card (the element carrying the raster overlay). */
function canvasCard(): HTMLElement {
    return screen.getByRole('group', { name: 'Ausweis-Vorschau' }).querySelector('.badge-canvas-container')!;
}

/** Inline left/top/width/height in %, i.e. the projected mm geometry. */
function geometryOf(element: HTMLElement) {
    return {
        left: element.style.left,
        top: element.style.top,
        width: element.style.width,
        height: element.style.height,
    };
}

describe('BadgeCanvas raster', () => {
    it('draws a 5 mm grid overlay on the A6 card', () => {
        renderCanvas([row()]);

        const raster = canvasCard().querySelector<HTMLElement>('div[aria-hidden="true"]')!;
        const expected = (CANVAS_GRID_STEP_MM / A6_WIDTH_MM) * 100;
        // 5 mm of 105 mm ≈ 4.7619 % horizontally, of 148 mm ≈ 3.3784 % vertically.
        expect(raster.style.backgroundSize).toBe(
            `${(expected * 100) / 100}% ${((CANVAS_GRID_STEP_MM / A6_HEIGHT_MM) * 100 * 100) / 100}%`,
        );
        expect(raster.style.backgroundImage).toContain('linear-gradient');
    });
});

describe('BadgeCanvas geometry preview', () => {
    it('projects the mm row onto percent of the real A6 sheet', () => {
        renderCanvas([row({ x: 21, y: 37, w: 42, h: 12 })]);

        const box = screen.getByRole('button', { name: 'Feld Name' });
        expect(geometryOf(box)).toEqual({
            left: `${(21 / A6_WIDTH_MM) * 100}%`,
            top: `${(37 / A6_HEIGHT_MM) * 100}%`,
            width: `${(42 / A6_WIDTH_MM) * 100}%`,
            height: `${(12 / A6_HEIGHT_MM) * 100}%`,
        });
    });

    it('marks the selected box as pressed and leaves the others unselected', () => {
        renderCanvas([row({ field: 'name' }), row({ field: 'category', y: 20 })], 1);

        expect(screen.getByRole('button', { name: 'Feld Name' })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByRole('button', { name: 'Feld Kategorie' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('flags an overlapping box with a soft warning title without blocking it', () => {
        renderCanvas([row({ field: 'name' }), row({ field: 'category' })], 0);
        // Second row overlaps the first; the canvas itself never hides the box.
        const canvas = screen.getByRole('group', { name: 'Ausweis-Vorschau' });
        expect(within(canvas).getByRole('button', { name: 'Feld Kategorie' })).toBeVisible();
    });
});

describe('BadgeCanvas selection', () => {
    it('selects a box on click and clears the selection on the card background', async () => {
        const user = userEvent.setup();
        const { onSelect } = renderCanvas([row({ field: 'name' }), row({ field: 'category', y: 20 })]);

        await user.click(screen.getByRole('button', { name: 'Feld Kategorie' }));
        expect(onSelect).toHaveBeenLastCalledWith(1);

        await user.click(screen.getByRole('group', { name: 'Ausweis-Vorschau' }));
        expect(onSelect).toHaveBeenLastCalledWith(null);
    });

    it('clears the selection on Escape', () => {
        const { onSelect } = renderCanvas([row()], 0);

        fireEvent.keyDown(screen.getByRole('group', { name: 'Ausweis-Vorschau' }), { key: 'Escape' });
        expect(onSelect).toHaveBeenLastCalledWith(null);
    });
});

/**
 * Regression guard: the superseded drag family (FE3 move / FE4 resize, nudge,
 * magnetic guides) must not come back. The props that fed it (`onMove`,
 * `onResize`) no longer exist, so a regression can only express itself as DOM
 * affordances or as a handler mutating the previewed geometry.
 */
describe('BadgeCanvas has no drag interaction', () => {
    it('renders no corner resize handles, not even on the selected box', () => {
        const { container } = renderWithProviders(
            <BadgeCanvas rows={[row()]} selectedIndex={0} overlapIndices={new Set()} onSelect={vi.fn()} />,
        );
        expect(container.querySelectorAll('[data-resize-handle]')).toHaveLength(0);
    });

    it('renders no alignment guide lines', () => {
        const { container } = renderWithProviders(
            <BadgeCanvas rows={[row()]} selectedIndex={0} overlapIndices={new Set()} onSelect={vi.fn()} />,
        );
        expect(container.querySelectorAll('[data-badge-guide]')).toHaveLength(0);
    });

    it('keeps the projected geometry on a full pointer drag over the box', () => {
        renderCanvas([row({ x: 10, y: 10, w: 40, h: 8 })], 0);
        const box = screen.getByRole('button', { name: 'Feld Name' });
        const before = geometryOf(box);

        // Down on the box, drag far across the card, release — the exact gesture
        // the removed handleDragStart/Move/End trio used to consume.
        fireEvent.pointerDown(box, { pointerId: 1, isPrimary: true, clientX: 40, clientY: 40 });
        fireEvent.pointerMove(box, { pointerId: 1, isPrimary: true, clientX: 400, clientY: 300 });
        fireEvent.pointerMove(box, { pointerId: 1, isPrimary: true, clientX: 900, clientY: 700 });
        fireEvent.pointerUp(box, { pointerId: 1, isPrimary: true, clientX: 900, clientY: 700 });
        fireEvent.pointerCancel(box, { pointerId: 1, isPrimary: true, clientX: 900, clientY: 700 });

        expect(geometryOf(box)).toEqual(before);
    });

    it('keeps the projected geometry on arrow keys (the removed keyboard nudge)', () => {
        renderCanvas([row({ x: 10, y: 10, w: 40, h: 8 })], 0);
        const box = screen.getByRole('button', { name: 'Feld Name' });
        const before = geometryOf(box);

        for (const key of ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown']) {
            fireEvent.keyDown(box, { key });
        }
        fireEvent.keyDown(box, { key: 'ArrowRight', shiftKey: true });

        expect(geometryOf(box)).toEqual(before);
    });

    it('carries no drag affordance classes on the boxes', () => {
        const { container } = renderWithProviders(
            <BadgeCanvas rows={[row()]} selectedIndex={0} overlapIndices={new Set()} onSelect={vi.fn()} />,
        );
        for (const box of container.querySelectorAll('.badge-canvas-box')) {
            const className = box.className;
            expect(className).not.toContain('cursor-grab');
            expect(className).not.toContain('touch-none');
        }
    });
});
