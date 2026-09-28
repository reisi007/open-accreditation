import { screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { badgePhotoPlaceholderUrl } from '../../api/client';
import { renderWithProviders } from '../../test-setup';
import { BadgeCanvas } from './BadgeCanvas';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    buildBadgeTemplatePayload,
    createDefaultBadgeRow,
    type BadgeRowValues,
} from './badgeTemplateFormUtils';

/**
 * **Editor preview ↔ PDF renderer: same `fit`, same decision.**
 *
 * The `fit` contract (features/badge-template-editor.md, "Die `fit`-Geometrie
 * rechnet der Renderer selbst") has two very different consumers:
 *
 * - the **preview** hands the problem to the browser — `object-contain` /
 *   `object-cover` on an `<img>` that fills the box, and the browser does the
 *   arithmetic;
 * - the **PDF** cannot: dompdf implements no `object-fit`, so
 *   `BadgeRenderService` computes the drawn rectangle in millimetres.
 *
 * They agree only if the two sides are given the SAME PROBLEM. That is what
 * this file pins — deliberately NOT by re-implementing the mm arithmetic in
 * TypeScript (a second geometry implementation is exactly the drift the
 * backend refactor removed) and not by re-testing the browser:
 *
 * 1. the preview delegates the fit to the browser instead of hard-coding
 *    millimetres (so there is no second geometry to drift);
 * 2. the previewed box has exactly the same PROPORTION as the printed box —
 *    this is the load-bearing invariant, and it is what makes a browser
 *    `object-contain` in the preview equal an mm `contain` on paper;
 * 3. the value the renderer keys on is carried to the wire format unchanged
 *    for an `image` entry, and is absent for a `photo` entry so the renderer's
 *    branch default is what runs;
 * 4. the square-on-square case — where `contain` and `cover` are the same
 *    decision, so the preview cannot disagree with the print — is the shape the
 *    editor's own default photo box has.
 *
 * The numeric counterpart lives in `backend/tests/Feature/BadgeImageFitGeometryTest.php`
 * (the renderer's measured rectangles). The two files are meant to be read
 * together; the cross-references name the exact numbers.
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

function renderCanvas(rows: BadgeRowValues[]) {
    renderWithProviders(
        <BadgeCanvas
            rows={rows}
            selectedIndex={null}
            overlapIndices={new Set()}
            onSelect={vi.fn()}
            onMove={vi.fn()}
        />,
    );
}

/** The canvas card — the element that carries the A6 (105 × 148 mm) proportion. */
function canvasCard(): HTMLElement {
    return screen.getByRole('group', { name: 'Ausweis-Vorschau' }).querySelector('.badge-canvas-container')!;
}

/** `12.5%` → `12.5` */
function percentOf(value: string): number {
    return Number.parseFloat(value.replace('%', ''));
}

describe('preview delegates the fit to the browser', () => {
    it('renders the placeholder with object-contain instead of computed millimetres', () => {
        renderCanvas([row({ field: 'photo', x: 5, y: 25, w: 30, h: 30 })]);

        const icon = within(screen.getByRole('button', { name: 'Feld Foto' })).getByRole('presentation');

        expect(icon).toHaveAttribute('src', badgePhotoPlaceholderUrl);
        // The browser does the fit. A `style` carrying mm here would mean a
        // second geometry implementation had crept into the preview.
        expect(icon).toHaveClass('object-contain');
        expect(icon.getAttribute('style') ?? '').not.toMatch(/mm/);
    });

    it('fills its box, so the image is fitted INSIDE a box of the box’s size', () => {
        // `h-full w-full` is what makes the browser's contain/cover meaningful:
        // the replaced element occupies the whole box, and `object-fit` decides
        // how the bitmap sits inside it. Without the full-size box the same
        // keyword would draw something else entirely.
        renderCanvas([row({ field: 'photo', x: 5, y: 25, w: 30, h: 30 })]);

        const icon = within(screen.getByRole('button', { name: 'Feld Foto' })).getByRole('presentation');
        expect(icon).toHaveClass('h-full', 'w-full');
    });
});

describe('the previewed box is the printed box', () => {
    // The renderer draws into a box of `w × h` mm on a 105 × 148 mm sheet. The
    // canvas projects the same mm onto a card that is `aspect-a6`, i.e. the same
    // 105 : 148 sheet. The previewed box therefore has the box's own proportion —
    // which is exactly the condition under which a browser `object-contain`
    // yields the same rectangle as the renderer's mm `contain`.
    //
    // If the mm → % projection ever drifts (a swapped denominator, a rounding
    // shortcut), the preview box and the print box stop being the same shape
    // and the two `fit` implementations silently disagree. This is the test
    // that catches that.
    it.each([
        { w: 25, h: 30 },
        { w: 30, h: 30 },
        { w: 20, h: 12 },
        { w: 105, h: 20 },
        { w: 40, h: 8 },
    ])('keeps the $w × $h mm box at the same proportion on both sides', ({ w, h }) => {
        renderCanvas([row({ field: 'photo', x: 5, y: 25, w, h })]);

        const box = screen.getByRole('button', { name: 'Feld Foto' });

        // Printed proportion, straight from the layout row.
        const printAspect = w / h;
        // Previewed proportion. The row is projected as `w/105` of the card
        // width and `h/148` of its height, and the card is itself 105:148, so
        // the sheet's own proportion has to be multiplied back in — it cancels
        // the two denominators and leaves exactly w:h.
        const previewAspect = (percentOf(box.style.width) / percentOf(box.style.height)) * (A6_WIDTH_MM / A6_HEIGHT_MM);

        expect(previewAspect).toBeCloseTo(printAspect, 10);
    });

    it('uses the real A6 sheet as the card, not a square approximation', () => {
        // The cancellation in the test above only holds because the card is the
        // A6 sheet. A square card would rescale both axes by the same factor and
        // the aspect check would keep passing — so the sheet is pinned too.
        renderCanvas([row({ field: 'photo', x: 5, y: 25, w: 30, h: 30 })]);

        const card = screen.getByRole('group', { name: 'Ausweis-Vorschau' });
        expect(card.className).toContain('aspect-a6');
        // The positioning container fills that sheet — the boxes are % of it.
        expect(canvasCard().className).toContain('h-full');
        expect(canvasCard().className).toContain('w-full');
        expect(A6_WIDTH_MM / A6_HEIGHT_MM).not.toBeCloseTo(1, 3);
    });
});

describe('the value the renderer keys on survives the round trip', () => {
    it.each(['contain', 'cover'] as const)('carries fit: %s unchanged into the wire format', (fit) => {
        const payload = buildBadgeTemplatePayload({
            name: 'Presseausweis',
            is_default: false,
            fields: [row({ field: 'image', x: 5, y: 130, w: 20, h: 12, srcKind: 'brand', srcRef: 'logo', fit })],
        });

        expect(payload.layout).toHaveLength(1);
        expect(payload.layout[0]).toMatchObject({ field: 'image', fit });
    });

    it('omits fit on a photo entry, so the renderer’s branch default is what runs', () => {
        // A photo entry is `cover` by default in the renderer. The editor never
        // offers a `fit` for it, so it must not invent one: a stray `fit:
        // 'contain'` would silently switch a re-saved portrait box from
        // fill-and-crop to letterboxed, and no UI would show why.
        const payload = buildBadgeTemplatePayload({
            name: 'Presseausweis',
            is_default: false,
            fields: [row({ field: 'photo', x: 5, y: 25, w: 30, h: 30, fit: 'contain' })],
        });

        expect(payload.layout[0]).not.toHaveProperty('fit');
        expect(payload.layout[0]).toMatchObject({ field: 'photo', w: 30, h: 30 });
    });
});

describe('the square-on-square case, where preview and print cannot disagree', () => {
    it('defaults a photo box to a square 30 × 30 mm', () => {
        // The bundled placeholder is a 512 × 512 SQUARE bitmap, and the editor's
        // default photo box is square. For a square source in a square box
        // `contain` and `cover` are the same decision, so the browser's
        // `object-contain` and the renderer's mm geometry necessarily agree —
        // the renderer measures 30.00 × 30.00 mm at 0.00 / 0.00 for exactly this
        // input (BadgeImageFitGeometryTest, "the placeholder uses the same rule").
        //
        // That is what makes the placeholder the trustworthy preview of a
        // portrait-less badge — and it is the reason the placeholder is the one
        // picture the editor renders for real.
        const photo = createDefaultBadgeRow('photo', []);

        expect(photo.w).toBe(photo.h);
        expect(photo.w).toBe(30);
    });

    it('starts an image box landscape, so the two fits are visibly different on screen', () => {
        // An `image` entry defaults to 30 × 20 mm — NOT square. So the fit the
        // author picks is immediately visible in the editor instead of being
        // hidden by the square degeneracy. The editor itself does not raster the
        // uploaded image yet (it shows a placeholder glyph), so this pins the
        // intent of the default rather than a rendered result.
        const image = createDefaultBadgeRow('image', []);

        expect(image.w).toBe(30);
        expect(image.h).toBe(20);
        expect(image.fit).toBe('contain');
    });
});
