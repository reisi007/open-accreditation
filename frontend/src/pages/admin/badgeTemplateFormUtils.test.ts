import { describe, expect, it } from 'vitest';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    ALIGNMENT_SNAP_THRESHOLD_MM,
    BADGE_FIT_CHAR_WIDTH_EM,
    BADGE_FIT_LINE_HEIGHT_FACTOR,
    badgeCanvasFontSizeCss,
    badgeCanvasMultiLineCapCqh,
    badgeCanvasMultiLineSlackPx,
    badgeCanvasOneLineCapCqw,
    badgeCanvasOneLineSlackPx,
    badgeTemplateFormDefaults,
    boxesOverlap,
    buildBadgeTemplatePayload,
    CANVAS_GRID_STEP_MM,
    clampToBounds,
    createBadgeTemplateSchema,
    createDefaultBadgeRow,
    computeAlignmentSnap,
    computeDragPosition,
    computeDragResize,
    computeNudgePosition,
    findAlignedGuides,
    findDuplicateDataFieldIndices,
    findFreePosition,
    findOverlappingIndices,
    nudgeDirectionFromKey,
    snapToGrid,
    type BadgeRowValues,
    type BadgeTemplateFormValues,
    type MmRect,
} from './badgeTemplateFormUtils';

const validTextRow: BadgeRowValues = {
    field: 'name',
    x: 10,
    y: 5,
    w: 40,
    h: 8,
    size: 12,
    align: 'left',
    srcKind: 'none',
    srcRef: 'logo',
    imageId: 0,
    fit: 'contain',
};

const validValues: BadgeTemplateFormValues = {
    name: 'Standard',
    is_default: true,
    fields: [
        validTextRow,
        { ...validTextRow, field: 'category', x: 10, y: 20, w: 60, h: 10, size: 14, align: 'center' },
    ],
};

function parse(values: BadgeTemplateFormValues) {
    return createBadgeTemplateSchema().safeParse(values);
}

function lastPathSegment(result: ReturnType<typeof parse>): PropertyKey | undefined {
    if (result.success) return undefined;
    const issue = result.error.issues[0];
    return issue.path[issue.path.length - 1];
}

describe('createBadgeTemplateSchema', () => {
    it('accepts a complete template', () => {
        expect(parse(validValues).success).toBe(true);
    });

    it('rejects an empty name', () => {
        const result = parse({ ...validValues, name: '   ' });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[0] === 'name')).toBe(true);
        }
    });

    it('rejects an empty field list', () => {
        expect(parse({ ...validValues, fields: [] }).success).toBe(false);
    });

    it('rejects a field value outside the whitelist', () => {
        const result = parse({
            ...validValues,
            fields: [{ ...validTextRow, field: 'email' as never }],
        });
        expect(result.success).toBe(false);
        expect(lastPathSegment(result)).toBe('field');
    });

    it('rejects an align value outside the whitelist', () => {
        const result = parse({
            ...validValues,
            fields: [{ ...validTextRow, align: 'top' as never }],
        });
        expect(lastPathSegment(result)).toBe('align');
    });

    it('rejects negative coordinates', () => {
        const negative = parse({
            ...validValues,
            fields: [{ ...validTextRow, x: -1, y: -2, w: -3, h: -4 }],
        });
        expect(negative.success).toBe(false);
    });

    it('accepts exact minimum sizes and exact card boundaries', () => {
        // Text minimum 5 × 3 mm; box exactly on both card edges.
        const rows: BadgeRowValues[] = [
            { ...validTextRow, x: 0, y: 0, w: 5, h: 3 },
            {
                ...validTextRow,
                field: 'photo',
                x: A6_WIDTH_MM - 10,
                y: A6_HEIGHT_MM - 10,
                w: 10,
                h: 10,
                size: 8,
            },
        ];
        expect(parse({ ...validValues, fields: rows }).success).toBe(true);
    });

    it('rejects a text field below its minimum size', () => {
        const tooNarrow = parse({ ...validValues, fields: [{ ...validTextRow, w: 4.9 }] });
        expect(tooNarrow.success).toBe(false);
        expect(lastPathSegment(tooNarrow)).toBe('w');

        const tooFlat = parse({ ...validValues, fields: [{ ...validTextRow, h: 2.9 }] });
        expect(lastPathSegment(tooFlat)).toBe('h');
    });

    it('rejects box entries (photo/qr/image) below 10 × 10 mm', () => {
        const photo = parse({ ...validValues, fields: [{ ...validTextRow, field: 'photo', w: 9.9, h: 30 }] });
        expect(photo.success).toBe(false);
        expect(lastPathSegment(photo)).toBe('w');

        const qr = parse({
            ...validValues,
            fields: [{ ...validTextRow, field: 'qr', x: 70, y: 100, w: 20, h: 9 }],
        });
        expect(qr.success).toBe(false);
        expect(lastPathSegment(qr)).toBe('h');

        const narrowImage = parse({
            ...validValues,
            fields: [
                {
                    ...validTextRow,
                    field: 'image',
                    w: 9,
                    h: 12,
                    srcKind: 'brand',
                    srcRef: 'logo',
                },
            ],
        });
        expect(narrowImage.success).toBe(false);
        expect(lastPathSegment(narrowImage)).toBe('w');
    });

    it('rejects entries beyond the A6 bounds', () => {
        const right = parse({ ...validValues, fields: [{ ...validTextRow, x: 26, w: 80 }] });
        expect(right.success).toBe(false);
        expect(lastPathSegment(right)).toBe('w');

        const bottom = parse({ ...validValues, fields: [{ ...validTextRow, y: 140, h: 9 }] });
        expect(bottom.success).toBe(false);
        expect(lastPathSegment(bottom)).toBe('h');
    });

    it('rejects a font size outside 1–72 or non-integral', () => {
        expect(parse({ ...validValues, fields: [{ ...validTextRow, size: 0 }] }).success).toBe(false);
        expect(parse({ ...validValues, fields: [{ ...validTextRow, size: 73 }] }).success).toBe(false);
        const fractional = parse({ ...validValues, fields: [{ ...validTextRow, size: 12.5 }] });
        expect(fractional.success).toBe(false);
    });

    it('rejects a non-finite coordinate (empty number input)', () => {
        expect(parse({ ...validValues, fields: [{ ...validTextRow, x: Number.NaN }] }).success).toBe(false);
    });

    it('accepts team and vest_number rows (schema v2 whitelist)', () => {
        const rows: BadgeRowValues[] = [
            { ...validTextRow, field: 'team', y: 5 },
            { ...validTextRow, field: 'vest_number', y: 14 },
        ];
        expect(parse({ ...validValues, fields: rows }).success).toBe(true);
    });

    it('accepts at most one qr row and rejects duplicates', () => {
        const single: BadgeRowValues[] = [
            validTextRow,
            { ...validTextRow, field: 'qr', x: 80, y: 123, w: 20, h: 20 },
        ];
        expect(parse({ ...validValues, fields: single }).success).toBe(true);

        const duplicated: BadgeRowValues[] = [
            ...single,
            { ...single[1], x: 0, y: 0 },
        ];
        const result = parse({ ...validValues, fields: duplicated });
        expect(result.success).toBe(false);
        if (!result.success) {
            const issue = result.error.issues[0];
            expect(issue.path).toEqual(['fields', 2, 'field']);
        }
    });

    describe('image rows', () => {
        const brandImage: BadgeRowValues = {
            ...validTextRow,
            field: 'image',
            x: 5,
            y: 130,
            w: 20,
            h: 12,
            srcKind: 'brand',
            srcRef: 'logo',
        };

        it('accepts a brand source without upload id', () => {
            expect(
                parse({ ...validValues, fields: [brandImage] }).success,
            ).toBe(true);
        });

        it('accepts an upload source with a positive integer id and any fit', () => {
            const upload: BadgeRowValues = { ...brandImage, x: 40, srcKind: 'upload', imageId: 17, fit: 'cover' };
            expect(parse({ ...validValues, fields: [upload] }).success).toBe(true);
        });

        it('rejects an image without a chosen source', () => {
            const missing: BadgeRowValues = { ...brandImage, srcKind: 'none' };
            const result = parse({ ...validValues, fields: [missing] });
            expect(result.success).toBe(false);
            expect(lastPathSegment(result)).toBe('srcKind');
        });

        it('rejects an upload source without a positive integer id', () => {
            const zero: BadgeRowValues = { ...brandImage, srcKind: 'upload', imageId: 0 };
            const zeroResult = parse({ ...validValues, fields: [zero] });
            expect(zeroResult.success).toBe(false);
            expect(lastPathSegment(zeroResult)).toBe('imageId');

            const fractional: BadgeRowValues = { ...brandImage, srcKind: 'upload', imageId: 1.5 };
            expect(parse({ ...validValues, fields: [fractional] }).success).toBe(false);
        });

        it('allows multiple image rows (co-branding)', () => {
            const rows: BadgeRowValues[] = [
                brandImage,
                { ...brandImage, x: 40, srcRef: 'header' },
                { ...brandImage, y: 100, srcKind: 'upload', imageId: 3 },
            ];
            expect(parse({ ...validValues, fields: rows }).success).toBe(true);
        });
    });
});

describe('badgeTemplateFormDefaults', () => {
    it('starts a new template with one default name row', () => {
        const defaults = badgeTemplateFormDefaults(null);
        expect(defaults.name).toBe('');
        expect(defaults.is_default).toBe(false);
        expect(defaults.fields).toHaveLength(1);
        expect(defaults.fields[0].field).toBe('name');
    });

    it('maps an existing legacy layout to form values', () => {
        const defaults = badgeTemplateFormDefaults({
            id: 3,
            name: 'Presse',
            layout: [
                { field: 'name', x: 10, y: 20, w: 30, h: 5, size: 11, align: 'right' },
                { field: 'photo', x: 0, y: 0, w: 50, h: 60, size: 1, align: 'center' },
            ],
            is_default: true,
            updated_at: '2026-08-14T00:00:00+00:00',
        });
        expect(defaults.name).toBe('Presse');
        expect(defaults.fields).toHaveLength(2);
        expect(defaults.fields[0]).toMatchObject({ field: 'name', x: 10, y: 20, w: 30, h: 5, size: 11, align: 'right' });
    });

    it('maps schema v2 qr and image entries to flat form rows', () => {
        const defaults = badgeTemplateFormDefaults({
            id: 7,
            name: 'v2',
            layout: [
                { field: 'qr', x: 78, y: 121, w: 22, h: 22 },
                { field: 'image', x: 5, y: 130, w: 20, h: 12, src: { kind: 'brand', ref: 'header' } },
                { field: 'image', x: 40, y: 130, w: 15, h: 12, src: { kind: 'upload', image_id: 17 }, fit: 'cover' },
            ],
            is_default: false,
            updated_at: null,
        });

        expect(defaults.fields[0]).toMatchObject({ field: 'qr', x: 78, y: 121, w: 22, h: 22 });
        expect(defaults.fields[1]).toMatchObject({
            field: 'image',
            srcKind: 'brand',
            srcRef: 'header',
            fit: 'contain',
        });
        expect(defaults.fields[2]).toMatchObject({
            field: 'image',
            srcKind: 'upload',
            imageId: 17,
            fit: 'cover',
        });
    });
});

describe('createDefaultBadgeRow', () => {
    it('creates data-field defaults at a free position', () => {
        const row = createDefaultBadgeRow('category', []);
        expect(row).toMatchObject({ field: 'category', w: 40, h: 8, size: 12, align: 'left', x: 0, y: 0 });
    });

    it('places the qr row at the historical fixed fallback spot', () => {
        const row = createDefaultBadgeRow('qr', []);
        expect(row).toMatchObject({ field: 'qr', x: 80, y: 123, w: 20, h: 20 });
    });

    it('places a photo row as a portrait box above the 10 × 10 minimum (regression)', () => {
        // Regression: photo used to inherit the text defaults (40 × 8) and was
        // instantly invalid — box entries must never start below 10 × 10 mm.
        const row = createDefaultBadgeRow('photo', []);
        expect(row).toMatchObject({ field: 'photo', w: 30, h: 30 });
        expect(row.w).toBeGreaterThanOrEqual(10);
        expect(row.h).toBeGreaterThanOrEqual(10);
    });

    it('places an image row without a source (save stays blocked until chosen)', () => {
        const row = createDefaultBadgeRow('image', []);
        expect(row).toMatchObject({ field: 'image', w: 30, h: 20, srcKind: 'none', fit: 'contain' });
    });
});

describe('findFreePosition', () => {
    it('returns the origin on an empty card', () => {
        expect(findFreePosition([], 40, 8)).toEqual({ x: 0, y: 0 });
    });

    it('scans below occupied space on the coarse grid', () => {
        const position = findFreePosition([{ x: 0, y: 0, w: 105, h: 8 }], 40, 8);
        expect(position.y).toBeGreaterThanOrEqual(8);
        expect(position.x + 40).toBeLessThanOrEqual(A6_WIDTH_MM);
    });

    it('falls back to the origin when nothing fits', () => {
        const full: Array<{ x: number; y: number; w: number; h: number }> = [];
        for (let y = 0; y < A6_HEIGHT_MM; y += 10) {
            full.push({ x: 0, y, w: A6_WIDTH_MM, h: 10 });
        }
        expect(findFreePosition(full, 20, 20)).toEqual({ x: 0, y: 0 });
    });

    it('ignores rows with non-finite values (in-progress edits)', () => {
        expect(findFreePosition([{ x: Number.NaN, y: 0, w: 105, h: 148 }], 40, 8)).toEqual({ x: 0, y: 0 });
    });
});

describe('snapToGrid', () => {
    it('rounds to the nearest grid multiple', () => {
        expect(snapToGrid(12)).toBe(10);
        expect(snapToGrid(13)).toBe(15);
        expect(snapToGrid(0)).toBe(0);
        expect(snapToGrid(-3)).toBe(-5);
    });

    it('keeps already snapped values stable', () => {
        for (let value = 0; value <= 105; value += CANVAS_GRID_STEP_MM) {
            expect(snapToGrid(value)).toBe(value);
        }
    });

    it('honours a custom step', () => {
        expect(snapToGrid(7, 10)).toBe(10);
        expect(snapToGrid(4, 10)).toBe(0);
    });

    it('snaps non-finite values and invalid steps to 0 (NaN defense)', () => {
        expect(snapToGrid(Number.NaN)).toBe(0);
        expect(snapToGrid(Number.POSITIVE_INFINITY)).toBe(0);
        expect(snapToGrid(12, Number.NaN)).toBe(0);
        expect(snapToGrid(12, 0)).toBe(0);
        expect(snapToGrid(12, -5)).toBe(0);
    });
});

describe('clampToBounds', () => {
    it('accepts a rectangle inside the A6 card unchanged', () => {
        expect(clampToBounds({ x: 10, y: 20, w: 40, h: 8 })).toEqual({ x: 10, y: 20, w: 40, h: 8 });
    });

    it('pulls a rectangle beyond the right/bottom edge back into the bounds', () => {
        // x + w = 110 > 105 → x = 65; y + h = 150 > 148 → y = 130.
        expect(clampToBounds({ x: 70, y: 140, w: 40, h: 10 })).toEqual({
            x: A6_WIDTH_MM - 40,
            y: A6_HEIGHT_MM - 10,
            w: 40,
            h: 10,
        });
    });

    it('clamps negative coordinates to the top/left edge', () => {
        const clamped = clampToBounds({ x: -7, y: -1, w: 30, h: 20 });
        expect(clamped.x).toBe(0);
        expect(clamped.y).toBe(0);
    });

    it('anchors an oversized rectangle at the origin (size is flagged by validation)', () => {
        expect(clampToBounds({ x: 50, y: 50, w: A6_WIDTH_MM + 10, h: A6_HEIGHT_MM + 10 })).toEqual({
            x: 0,
            y: 0,
            w: A6_WIDTH_MM + 10,
            h: A6_HEIGHT_MM + 10,
        });
    });

    it('treats non-finite coordinates as 0 (NaN defense)', () => {
        expect(clampToBounds({ x: Number.NaN, y: Number.NaN, w: 40, h: 8 })).toEqual({ x: 0, y: 0, w: 40, h: 8 });
    });
});

describe('computeDragPosition', () => {
    it('adds the pointer delta, snaps onto the grid and stays in bounds', () => {
        // 23.4 mm → snap 25; -1 mm → snap 0.
        expect(computeDragPosition({ x: 3, y: 2 }, { x: 20.4, y: -1 }, { w: 40, h: 8 })).toEqual({ x: 25, y: 0 });
    });

    it('hard-clamps a drag past the right/bottom edge (bounds win over snapping)', () => {
        const position = computeDragPosition({ x: 60, y: 135 }, { x: 100, y: 100 }, { w: 40, h: 8 });
        expect(position.x).toBe(A6_WIDTH_MM - 40);
        expect(position.y).toBe(A6_HEIGHT_MM - 8);
    });

    it('never leaves the grid when starting from a snapped origin', () => {
        const position = computeDragPosition({ x: 15, y: 45 }, { x: 13, y: -27 }, { w: 30, h: 20 });
        expect(position.x % CANVAS_GRID_STEP_MM).toBe(0);
        expect(position.y % CANVAS_GRID_STEP_MM).toBe(0);
    });
});

describe('computeDragResize', () => {
    const origin: MmRect = { x: 10, y: 10, w: 40, h: 20 };

    it('grows w/h from the south-east corner (snapped onto the grid)', () => {
        // right 50 + 13.4 → snap 65 → w = 55; bottom 30 − 2.8 → snap 25 → h = 15.
        expect(computeDragResize(origin, 'se', { x: 13.4, y: -2.8 }, 5, 3)).toEqual({
            x: 10,
            y: 10,
            w: 55,
            h: 15,
        });
    });

    it('moves the left/top edges from the north-west corner and keeps the fixed edges', () => {
        // left 10 + 6.2 → snap 15; top 10 − 3.1 → snap 5.
        expect(computeDragResize(origin, 'nw', { x: 6.2, y: -3.1 }, 5, 3)).toEqual({
            x: 15,
            y: 5,
            w: 35,
            h: 25,
        });
    });

    it('keeps the left/bottom edges fixed from the north-east corner', () => {
        // right 50 − 12.9 = 37.1 → snap 35; top 10 − 7.4 = 2.6 → snap 5.
        const resized = computeDragResize(origin, 'ne', { x: -12.9, y: -7.4 }, 5, 3);
        expect(resized).toEqual({ x: 10, y: 5, w: 25, h: 25 });
    });

    it('keeps the right/top edges fixed from the south-west corner', () => {
        // left 10 − 3 = 7 → snap 5; bottom 30 + 9 = 39 → snap 40.
        const resized = computeDragResize(origin, 'sw', { x: -3, y: 9 }, 5, 3);
        expect(resized).toEqual({ x: 5, y: 10, w: 45, h: 30 });
    });

    it('hard-clamps a resize past the card edges (bounds win over snapping)', () => {
        // Text minimum 5 × 3 mm; box already in the bottom-right corner.
        const cornerBox: MmRect = { x: 95, y: 145, w: 5, h: 3 };
        const resized = computeDragResize(cornerBox, 'se', { x: 20, y: 20 }, 5, 3);
        expect(resized).toEqual({ x: 95, y: 145, w: A6_WIDTH_MM - 95, h: A6_HEIGHT_MM - 145 });
    });

    it('never shrinks below the per-type minimum sizes', () => {
        const smallText: MmRect = { x: 0, y: 0, w: 8, h: 8 };
        const shrunk = computeDragResize(smallText, 'se', { x: -10, y: -10 }, 5, 3);
        expect(shrunk.w).toBe(5);
        expect(shrunk.h).toBe(3);
    });

    it('keeps the minimum when the north-west corner is dragged far outside', () => {
        const resized = computeDragResize(origin, 'nw', { x: -30, y: -30 }, 10, 10);
        expect(resized.x).toBe(0);
        expect(resized.y).toBe(0);
        expect(resized.w).toBe(50); // fixed right edge (50) minus clamped left
        expect(resized.h).toBe(30);
    });

    it('treats non-finite origins as 0 (NaN defense)', () => {
        const resized = computeDragResize({ x: Number.NaN, y: 10, w: 40, h: 20 }, 'nw', { x: -5, y: -5 }, 5, 3);
        expect(resized.x).toBe(0);
        expect(resized.y).toBe(5);
    });

    it('keeps snapped origins on the grid', () => {
        const resized = computeDragResize(origin, 'se', { x: 21.3, y: 13.7 }, 5, 3);
        expect(resized.w % CANVAS_GRID_STEP_MM).toBe(0);
        expect(resized.h % CANVAS_GRID_STEP_MM).toBe(0);
    });
});

describe('computeAlignmentSnap / findAlignedGuides', () => {
    it('snaps an edge flush onto a neighbouring edge within the threshold', () => {
        const moving: MmRect = { x: 41, y: 0, w: 20, h: 10 };
        const others: MmRect[] = [{ x: 0, y: 0, w: 40, h: 8 }];
        // Moving left edge (41) is 1 mm from the neighbour's right edge (40).
        expect(computeAlignmentSnap(moving, others)).toEqual({ offsetX: -1, offsetY: 0 });
    });

    it('prefers the closest match when several targets are in range', () => {
        const moving: MmRect = { x: 38.6, y: 0, w: 20, h: 10 };
        const others: MmRect[] = [
            { x: 0, y: 0, w: 40, h: 8 }, // right edge 40 → distance 1.4
            { x: 36, y: 20, w: 10, h: 8 }, // left edge 36 → distance 2.6 (out of range)
            { x: 37, y: 40, w: 10, h: 8 }, // right edge 47, centre 42 …
        ];
        const snap = computeAlignmentSnap(moving, others);
        expect(snap.offsetX).toBeCloseTo(1.4, 6); // → left edge exactly 40
    });

    it('attracts the rect centre to another centre and an edge to the card centre', () => {
        // Centre of the moving rect (52.6) is 0.1 mm from the card centre (52.5).
        const snap = computeAlignmentSnap({ x: 42.6, y: 72.5, w: 20, h: 20 }, []);
        expect(snap.offsetX).toBeCloseTo(-0.1, 6);
        // Top edge (72.5) is pulled up onto the horizontal card centre (74).
        expect(snap.offsetY).toBeCloseTo(1.5, 6);
    });

    it('returns zero offsets beyond the threshold or without others', () => {
        const far: MmRect = { x: 60, y: 60, w: 20, h: 10 };
        expect(computeAlignmentSnap(far, [])).toEqual({ offsetX: 0, offsetY: 0 });
        expect(computeAlignmentSnap(far, [{ x: 0, y: 0, w: 20, h: 10 }]).offsetX).toBe(0);
        expect(ALIGNMENT_SNAP_THRESHOLD_MM).toBe(2);
    });

    it('is NaN-safe for in-progress edits', () => {
        expect(computeAlignmentSnap({ x: Number.NaN, y: 0, w: 20, h: 10 }, [])).toEqual({
            offsetX: 0,
            offsetY: 0,
        });
        expect(findAlignedGuides({ x: 0, y: 0, w: Number.NaN, h: 10 }, []).vertical).toEqual([]);
    });

    it('lists exactly flush edges as guide lines (deduplicated, sorted)', () => {
        const moving: MmRect = { x: 40, y: 0, w: 20, h: 8 };
        const others: MmRect[] = [
            { x: 0, y: 0, w: 40, h: 8 }, // right edge 40 == moving left; top edges shared
            { x: 40, y: 60, w: 20, h: 8 }, // left/centre/end 40/50/60 all flush → deduped
        ];
        const guides = findAlignedGuides(moving, others);
        expect(guides.vertical).toEqual([40, 50, 60]);
        expect(guides.horizontal).toEqual([0, 4, 8]);
    });

    it('includes the card centre as a vertical/horizontal guide target', () => {
        const guides = findAlignedGuides(
            { x: 42.5, y: 64, w: 20, h: 20 },
            [],
        );
        expect(guides.vertical).toEqual([A6_WIDTH_MM / 2]);
        expect(guides.horizontal).toEqual([A6_HEIGHT_MM / 2]);
    });

    it('returns no guides for near-but-not-flush geometry (guides never lie)', () => {
        const guides = findAlignedGuides({ x: 40.5, y: 0, w: 20, h: 8 }, [{ x: 0, y: 0, w: 40, h: 8 }]);
        expect(guides.vertical).toEqual([]);
    });
});

describe('computeNudgePosition / nudgeDirectionFromKey', () => {
    it('maps only the arrow keys to nudge directions', () => {
        expect(nudgeDirectionFromKey('ArrowLeft')).toBe('left');
        expect(nudgeDirectionFromKey('ArrowRight')).toBe('right');
        expect(nudgeDirectionFromKey('ArrowUp')).toBe('up');
        expect(nudgeDirectionFromKey('ArrowDown')).toBe('down');
        expect(nudgeDirectionFromKey('a')).toBe(null);
        expect(nudgeDirectionFromKey('Escape')).toBe(null);
    });

    it('moves by exactly one step in each direction', () => {
        const current = { x: 20, y: 30 };
        expect(computeNudgePosition(current, 'left', 1, { w: 40, h: 8 })).toEqual({ x: 19, y: 30 });
        expect(computeNudgePosition(current, 'right', 1, { w: 40, h: 8 })).toEqual({ x: 21, y: 30 });
        expect(computeNudgePosition(current, 'up', 1, { w: 40, h: 8 })).toEqual({ x: 20, y: 29 });
        expect(computeNudgePosition(current, 'down', 1, { w: 40, h: 8 })).toEqual({ x: 20, y: 31 });
    });

    it('supports the coarse Shift step (grid size) via the step parameter', () => {
        expect(computeNudgePosition({ x: 20, y: 30 }, 'down', CANVAS_GRID_STEP_MM, { w: 40, h: 8 })).toEqual({
            x: 20,
            y: 35,
        });
    });

    it('stops at the card edges without leaving the bounds', () => {
        expect(computeNudgePosition({ x: 0, y: 0 }, 'left', 1, { w: 40, h: 8 })).toEqual({ x: 0, y: 0 });
        expect(computeNudgePosition({ x: A6_WIDTH_MM - 40, y: 0 }, 'right', 5, { w: 40, h: 8 })).toEqual({
            x: A6_WIDTH_MM - 40,
            y: 0,
        });
        expect(computeNudgePosition({ x: 50, y: A6_HEIGHT_MM - 8 }, 'down', 1, { w: 40, h: 8 }).y).toBe(
            A6_HEIGHT_MM - 8,
        );
    });

    it('is NaN-safe for in-progress edits', () => {
        expect(computeNudgePosition({ x: Number.NaN, y: 10 }, 'right', 1, { w: 40, h: 8 })).toEqual({ x: 1, y: 10 });
    });
});

describe('findDuplicateDataFieldIndices', () => {
    it('collects every row sharing a duplicated data field type', () => {
        const indices = findDuplicateDataFieldIndices([
            { field: 'name' },
            { field: 'photo' },
            { field: 'name' },
            { field: 'category' },
            { field: 'photo' },
        ]);
        expect([...indices].sort()).toEqual([0, 1, 2, 4]);
    });

    it('ignores unique fields and repeated qr/image entries', () => {
        const indices = findDuplicateDataFieldIndices([
            { field: 'name' },
            { field: 'image' },
            { field: 'image' },
            { field: 'qr' },
        ]);
        expect(indices.size).toBe(0);
    });
});

describe('boxesOverlap / findOverlappingIndices', () => {
    it('detects intersecting rectangles but not shared edges', () => {
        expect(boxesOverlap({ x: 0, y: 0, w: 40, h: 8 }, { x: 20, y: 4, w: 40, h: 8 })).toBe(true);
        // Touching right edge (x = 40) does NOT overlap.
        expect(boxesOverlap({ x: 0, y: 0, w: 40, h: 8 }, { x: 40, y: 0, w: 40, h: 8 })).toBe(false);
        // Touching bottom edge (y = 8) does NOT overlap.
        expect(boxesOverlap({ x: 0, y: 0, w: 40, h: 8 }, { x: 0, y: 8, w: 40, h: 8 })).toBe(false);
    });

    it('is NaN-safe (in-progress edits never warn)', () => {
        expect(boxesOverlap({ x: Number.NaN, y: 0, w: 40, h: 8 }, { x: 0, y: 0, w: 40, h: 8 })).toBe(false);
    });

    it('collects every index of mutually overlapping rows', () => {
        const base = { x: 0, y: 0, w: 40, h: 8 };
        const indices = findOverlappingIndices([base, { ...base, x: 10 }, { ...base, y: 50 }]);
        expect(indices.has(0)).toBe(true);
        expect(indices.has(1)).toBe(true);
        expect(indices.has(2)).toBe(false);
    });

    it('returns an empty set without overlaps or for non-finite rows', () => {
        const base = { x: 0, y: 0, w: 40, h: 8 };
        expect(findOverlappingIndices([base, { ...base, x: 50 }]).size).toBe(0);
        expect(findOverlappingIndices([{ ...base, x: Number.NaN }, base]).size).toBe(0);
        expect(findOverlappingIndices([]).size).toBe(0);
    });

    it('flags overlapping qr entries like any other field', () => {
        // A qr entry that overlaps a data field must be flagged — the soft
        // overlap warning applies to qr exactly like to text/photo/image.
        const rows: BadgeRowValues[] = [
            { ...validTextRow, field: 'qr', x: 80, y: 123, w: 20, h: 20 },
            { ...validTextRow, field: 'photo', x: 70, y: 115, w: 30, h: 30 },
        ];
        const indices = findOverlappingIndices(rows);
        expect(indices.has(0)).toBe(true);
        expect(indices.has(1)).toBe(true);
    });
});

describe('buildBadgeTemplatePayload', () => {
    it('maps the form values to the API layout', () => {
        const payload = buildBadgeTemplatePayload(validValues);
        expect(payload.name).toBe('Standard');
        expect(payload.is_default).toBe(true);
        expect(payload.layout).toEqual([
            { field: 'name', x: 10, y: 5, w: 40, h: 8, size: 12, align: 'left' },
            { field: 'category', x: 10, y: 20, w: 60, h: 10, size: 14, align: 'center' },
        ]);
    });

    it('always sends the is_default flag', () => {
        const payload = buildBadgeTemplatePayload({ ...validValues, is_default: false });
        expect(payload.is_default).toBe(false);
    });

    it('omits the meaningless size/align for qr rows and keeps coordinates only', () => {
        const values: BadgeTemplateFormValues = {
            ...validValues,
            fields: [{ ...validTextRow, field: 'qr', x: 80, y: 123, w: 20, h: 20 }],
        };
        expect(buildBadgeTemplatePayload(values).layout).toEqual([{ field: 'qr', x: 80, y: 123, w: 20, h: 20 }]);
    });

    it('maps image rows to the src union with fit', () => {
        const values: BadgeTemplateFormValues = {
            ...validValues,
            fields: [
                { ...validTextRow, field: 'image', x: 5, y: 130, w: 20, h: 12, srcKind: 'brand', srcRef: 'logo' },
                {
                    ...validTextRow,
                    field: 'image',
                    x: 40,
                    y: 130,
                    w: 15,
                    h: 12,
                    srcKind: 'upload',
                    imageId: 17,
                    fit: 'cover',
                },
            ],
        };

        expect(buildBadgeTemplatePayload(values).layout).toEqual([
            { field: 'image', x: 5, y: 130, w: 20, h: 12, src: { kind: 'brand', ref: 'logo' }, fit: 'contain' },
            { field: 'image', x: 40, y: 130, w: 15, h: 12, src: { kind: 'upload', image_id: 17 }, fit: 'cover' },
        ]);
    });

    it('roundtrips stored layout through defaults and payload unchanged', () => {
        const payload = buildBadgeTemplatePayload(
            badgeTemplateFormDefaults({
                id: 9,
                name: 'Roundtrip',
                layout: [
                    { field: 'name', x: 10, y: 10, w: 80, h: 10, size: 18, align: 'center' },
                    { field: 'qr', x: 78, y: 121, w: 22, h: 22 },
                    { field: 'image', x: 5, y: 130, w: 20, h: 12, src: { kind: 'brand', ref: 'logo' }, fit: 'contain' },
                ],
                is_default: false,
                updated_at: null,
            }),
        );

        expect(payload.layout).toEqual([
            { field: 'name', x: 10, y: 10, w: 80, h: 10, size: 18, align: 'center' },
            { field: 'qr', x: 78, y: 121, w: 22, h: 22 },
            { field: 'image', x: 5, y: 130, w: 20, h: 12, src: { kind: 'brand', ref: 'logo' }, fit: 'contain' },
        ]);
        expect(payload.name).toBe('Roundtrip');
    });
});

describe('badge canvas wrap-aware auto-fit', () => {
    /** Reference render: editor canvas with a 500 px tall A6 card. */
    const REF_CARD_HEIGHT_PX = 500;
    /**
     * Vertical/horizontal chrome of a canvas box (border `1px` × 2 +
     * padding `p-0.5` = 4px) — container units resolve against the CONTENT
     * box, so caps act on the remainder.
     */
    const BOX_CHROME_PX = 6;
    const boxWidthPx = (wMm: number): number => (wMm / A6_WIDTH_MM) * REF_CARD_HEIGHT_PX * (A6_WIDTH_MM / A6_HEIGHT_MM);
    const boxHeightPx = (hMm: number): number => (hMm / A6_HEIGHT_MM) * REF_CARD_HEIGHT_PX;

    it('bounds the seed name font so even a wrapped sample fits vertically (FE4-F1 regression)', () => {
        // Reported bug: „Max Mustermann" in a 40 × 8 mm box @ 14 pt wrapped and
        // the second line crossed the selection frame.
        const chars = 'Max Mustermann'.length;
        const contentHeightPx = boxHeightPx(8) - BOX_CHROME_PX;
        const contentWidthPx = boxWidthPx(40) - BOX_CHROME_PX;
        const heightCap =
            (badgeCanvasMultiLineCapCqh() / 100) * contentHeightPx - badgeCanvasMultiLineSlackPx();
        const widthCap =
            ((badgeCanvasOneLineCapCqw(chars) ?? 0) / 100) * contentWidthPx -
            (badgeCanvasOneLineSlackPx(chars) ?? 0);
        const effectiveFont = Math.min(14, Math.max(heightCap, 2), Math.max(widthCap, 2));

        expect(effectiveFont).toBe(heightCap); // height binds for the narrow seed box
        expect(effectiveFont).toBeGreaterThan(2);
        // Worst case: the sample really wraps to BADGE_FIT_MAX_LINES lines —
        // the block must stay inside the usable (content-box) height.
        expect(2 * BADGE_FIT_LINE_HEIGHT_FACTOR * effectiveFont).toBeLessThanOrEqual(contentHeightPx);
        // Model single line must also fit the usable box width.
        expect(chars * BADGE_FIT_CHAR_WIDTH_EM * effectiveFont).toBeLessThanOrEqual(contentWidthPx);
    });

    it('keeps the authored size for boxes without wrap pressure', () => {
        // 40 × 14 mm at 16 pt on a taller reference render (600 px card):
        // neither cap may bind below the desired size.
        const refCardHeightPx = 600;
        const contentHeightPx = (14 / A6_HEIGHT_MM) * refCardHeightPx - BOX_CHROME_PX;
        const heightCap = (badgeCanvasMultiLineCapCqh() / 100) * contentHeightPx - badgeCanvasMultiLineSlackPx();
        expect(heightCap).toBeGreaterThanOrEqual(16);
    });

    it('emits one-line width cap, two-line height cap and 2px floor for text fields', () => {
        expect(badgeCanvasFontSizeCss('name', 'Max Mustermann'.length, 14)).toBe(
            'max(min(14px, calc(12.315cqw - 0.500px), calc(40.000cqh - 0.500px)), 2px)',
        );
        expect(badgeCanvasFontSizeCss('vest_number', '42'.length, 12)).toBe(
            'max(min(12px, calc(86.207cqw - 0.500px), calc(40.000cqh - 0.500px)), 2px)',
        );
    });

    it('omits the width cap when there is no sample text', () => {
        expect(badgeCanvasOneLineCapCqw(0)).toBeNull();
        expect(badgeCanvasOneLineCapCqw(Number.NaN)).toBeNull();
        expect(badgeCanvasOneLineSlackPx(0)).toBeNull();
        expect(badgeCanvasFontSizeCss('name', 0, 14)).toBe(
            'max(min(14px, calc(40.000cqh - 0.500px)), 2px)',
        );
    });

    it('keeps an equivalent single-line cap for picture boxes', () => {
        expect(badgeCanvasFontSizeCss('qr', 0, 12)).toBe('min(12px, max(calc(80.000cqh - 0.500px), 2px))');
        expect(badgeCanvasFontSizeCss('photo', 0, 18)).toBe('min(18px, max(calc(80.000cqh - 0.500px), 2px))');
        expect(badgeCanvasFontSizeCss('image', 0, 12)).toBe('min(12px, max(calc(80.000cqh - 0.500px), 2px))');
    });
});
