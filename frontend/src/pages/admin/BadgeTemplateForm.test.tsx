import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SWRConfig } from 'swr';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { BadgeTemplateForm } from './BadgeTemplateForm';
import { A6_HEIGHT_MM, A6_WIDTH_MM } from './badgeTemplateFormUtils';

const { listBadgeImagesMock } = vi.hoisted(() => ({
    listBadgeImagesMock: vi.fn(async () => []),
}));

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return { ...actual, listBadgeImages: listBadgeImagesMock };
});

/**
 * Editor wiring of the „Raster + konfigurierbare Labels" model (decision
 * 2026-09-27, features/badge-template-editor.md): `BadgePropertiesPanel` is the
 * canonical writer for the absolute geometry, the canvas adds selection and the
 * arrow-key nudge. This is the counterpart to `BadgeCanvas.test.tsx`, which
 * pins that the canvas carries no pointer interaction — together they cover
 * the single-source-of-truth claim of both writers.
 */
function renderForm() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <BadgeTemplateForm
                initial={null}
                submitLabel="Template erstellen"
                submitError={null}
                onSubmit={vi.fn()}
                onCancel={vi.fn()}
            />
        </SWRConfig>,
    );
}

function canvas(): HTMLElement {
    return screen.getByRole('group', { name: 'Ausweis-Vorschau' });
}

function propertiesPanel(): HTMLElement {
    return screen.getByRole('complementary');
}

function mmInput(label: string): HTMLInputElement {
    return within(propertiesPanel()).getByLabelText(label) as HTMLInputElement;
}

/** The projected mm geometry of the canvas box, derived from its percent style. */
function previewedGeometryMm() {
    const box = within(canvas()).getByRole('button', { name: /^Feld / });
    return {
        x: (parseFloat(box.style.left) / 100) * A6_WIDTH_MM,
        y: (parseFloat(box.style.top) / 100) * A6_HEIGHT_MM,
        w: (parseFloat(box.style.width) / 100) * A6_WIDTH_MM,
        h: (parseFloat(box.style.height) / 100) * A6_HEIGHT_MM,
    };
}

describe('BadgeTemplateForm positions the canvas from the properties panel', () => {
    it('prompts for a selection before any field is picked', () => {
        renderForm();

        expect(within(propertiesPanel()).getByText('Kein Feld ausgewählt. Klicke ein Feld auf der Vorschau an.'))
            .toBeVisible();
        // The default name row exists on the canvas but is not selected yet.
        expect(within(canvas()).getByRole('button', { name: 'Feld Name' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('selects a canvas box and preloads the panel with its mm geometry', async () => {
        const user = userEvent.setup();
        renderForm();

        await user.click(within(canvas()).getByRole('button', { name: 'Feld Name' }));

        expect(within(canvas()).getByRole('button', { name: 'Feld Name' })).toHaveAttribute('aria-pressed', 'true');
        expect(mmInput('X (mm)')).toHaveValue(0);
        expect(mmInput('Y (mm)')).toHaveValue(0);
        expect(mmInput('Breite (mm)')).toHaveValue(40);
        expect(mmInput('Höhe (mm)')).toHaveValue(8);
    });

    it('repositions and resizes the previewed box from the panel inputs', async () => {
        const user = userEvent.setup();
        renderForm();
        await user.click(within(canvas()).getByRole('button', { name: 'Feld Name' }));

        await user.clear(mmInput('X (mm)'));
        await user.type(mmInput('X (mm)'), '25');
        await user.clear(mmInput('Y (mm)'));
        await user.type(mmInput('Y (mm)'), '60');
        await user.clear(mmInput('Breite (mm)'));
        await user.type(mmInput('Breite (mm)'), '50');
        await user.clear(mmInput('Höhe (mm)'));
        await user.type(mmInput('Höhe (mm)'), '20');

        const geometry = previewedGeometryMm();
        expect(geometry.x).toBeCloseTo(25, 6);
        expect(geometry.y).toBeCloseTo(60, 6);
        expect(geometry.w).toBeCloseTo(50, 6);
        expect(geometry.h).toBeCloseTo(20, 6);
    });

    it('applies the typography and alignment selects to the preview', async () => {
        const user = userEvent.setup();
        renderForm();
        await user.click(within(canvas()).getByRole('button', { name: 'Feld Name' }));

        await user.selectOptions(within(propertiesPanel()).getByLabelText('Ausrichtung'), 'center');
        await user.clear(mmInput('Schriftgröße (pt)'));
        await user.type(mmInput('Schriftgröße (pt)'), '20');

        const box = within(canvas()).getByRole('button', { name: 'Feld Name' });
        const content = box.querySelector('span')!;
        expect(content.style.textAlign).toBe('center');
        // badgeCanvasFontSizeCss renders the authored size capped by the
        // wrap-aware auto-fit caps, both in `min()`.
        expect(content.style.fontSize).toMatch(/^max\(min\(20px,/);
    });
});

/**
 * The panel-driven model must not have kept a second, POINTER-driven writer:
 * a drag across the box may only ever produce the selection. The keyboard
 * nudge is the one deliberate exception — it writes the same form state (see
 * the nudge block below).
 */
describe('BadgeTemplateForm has no pointer drag interaction left', () => {
    it('does not change the panel values when the canvas box is dragged', async () => {
        const user = userEvent.setup();
        renderForm();
        const box = within(canvas()).getByRole('button', { name: 'Feld Name' });
        await user.click(box);
        await user.clear(mmInput('X (mm)'));
        await user.type(mmInput('X (mm)'), '30');

        const before = {
            x: mmInput('X (mm)').value,
            y: mmInput('Y (mm)').value,
            w: mmInput('Breite (mm)').value,
            h: mmInput('Höhe (mm)').value,
        };

        // Realistic press → move → release sweep across the box.
        await user.pointer([
            { keys: '[MouseLeft>]', target: box, coords: { x: 10, y: 10 } },
            { target: box, coords: { x: 120, y: 90 } },
            { target: box, coords: { x: 260, y: 200 } },
            { keys: '[/MouseLeft]', target: box },
        ]);

        expect({
            x: mmInput('X (mm)').value,
            y: mmInput('Y (mm)').value,
            w: mmInput('Breite (mm)').value,
            h: mmInput('Höhe (mm)').value,
        }).toEqual(before);
        expect(previewedGeometryMm().x).toBeCloseTo(30, 6);
    });

    it('renders no resize handles and no alignment guides anywhere in the dialog', async () => {
        const user = userEvent.setup();
        const { container } = renderForm();
        await user.click(within(canvas()).getByRole('button', { name: 'Feld Name' }));

        expect(container.querySelectorAll('[data-resize-handle]')).toHaveLength(0);
        expect(container.querySelectorAll('[data-badge-guide]')).toHaveLength(0);
    });
});

/**
 * The arrow-key nudge (restored 2026-09-27) is the canvas' one write path: it
 * goes through the same react-hook-form state the panel inputs are registered
 * on, so panel numbers, canvas preview and the saved layout cannot disagree.
 * This is the test that would catch a nudge wired to a second source of truth.
 */
describe('BadgeTemplateForm nudges the selected field with the arrow keys', () => {
    it('moves the field 1 mm per arrow key, Shift moves one 5 mm raster step', async () => {
        const user = userEvent.setup();
        renderForm();
        const box = within(canvas()).getByRole('button', { name: 'Feld Name' });
        await user.click(box);
        await user.clear(mmInput('X (mm)'));
        await user.type(mmInput('X (mm)'), '30');
        await user.clear(mmInput('Y (mm)'));
        await user.type(mmInput('Y (mm)'), '50');
        box.focus();

        await user.keyboard('{ArrowRight}');
        expect(mmInput('X (mm)')).toHaveValue(31);
        expect(mmInput('Y (mm)')).toHaveValue(50);

        await user.keyboard('{Shift>}{ArrowDown}{/Shift}');
        expect(mmInput('Y (mm)')).toHaveValue(55);

        await user.keyboard('{ArrowUp}{ArrowLeft}');
        expect(mmInput('X (mm)')).toHaveValue(30);
        expect(mmInput('Y (mm)')).toHaveValue(54);

        // Same numbers in the preview — one source of truth, not two.
        const geometry = previewedGeometryMm();
        expect(geometry.x).toBeCloseTo(30, 6);
        expect(geometry.y).toBeCloseTo(54, 6);
        // The nudge moves position only; the size is the panel's business.
        expect(geometry.w).toBeCloseTo(40, 6);
        expect(geometry.h).toBeCloseTo(8, 6);
    });

    it('stops at the A6 bounds instead of writing an out-of-bounds position', async () => {
        const user = userEvent.setup();
        renderForm();
        const box = within(canvas()).getByRole('button', { name: 'Feld Name' });
        await user.click(box);
        await user.clear(mmInput('X (mm)'));
        await user.type(mmInput('X (mm)'), '100');
        box.focus();

        // x + w = 140 > 105: the nudge must clamp to 105 − 40 = 65 mm instead
        // of writing an even more out-of-bounds value.
        await user.keyboard('{ArrowRight}');
        expect(mmInput('X (mm)')).toHaveValue(65);
        expect(previewedGeometryMm().x).toBeCloseTo(65, 6);
    });

    it('nudges the box the keyboard reached, even when nothing is selected yet', async () => {
        const user = userEvent.setup();
        renderForm();
        const box = within(canvas()).getByRole('button', { name: 'Feld Name' });
        expect(box).toHaveAttribute('aria-pressed', 'false');

        // Focus without a click (pure keyboard path) and nudge.
        box.focus();
        await user.keyboard('{ArrowDown}');

        expect(box).toHaveAttribute('aria-pressed', 'true');
        expect(mmInput('X (mm)')).toHaveValue(0);
        expect(mmInput('Y (mm)')).toHaveValue(1);
    });
});
