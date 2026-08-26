import { describe, expect, it } from 'vitest';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    badgeTemplateFormDefaults,
    boxesOverlap,
    buildBadgeTemplatePayload,
    CANVAS_GRID_STEP_MM,
    clampToBounds,
    createBadgeTemplateSchema,
    createDefaultBadgeRow,
    computeDragPosition,
    findFreePosition,
    findOverlappingIndices,
    snapToGrid,
    type BadgeRowValues,
    type BadgeTemplateFormValues,
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
