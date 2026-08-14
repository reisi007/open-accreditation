import { describe, expect, it } from 'vitest';
import {
    addBadgeFieldRow,
    badgeTemplateFormDefaults,
    buildBadgeTemplatePayload,
    createBadgeTemplateSchema,
    createEmptyBadgeFieldRow,
    removeBadgeFieldRow,
    type BadgeTemplateFormValues,
} from './badgeTemplateFormUtils';

const validValues: BadgeTemplateFormValues = {
    name: 'Standard',
    is_default: true,
    fields: [
        { field: 'name', x: 10, y: 5, w: 40, h: 8, size: 12, align: 'left' },
        { field: 'category', x: 10, y: 20, w: 60, h: 10, size: 14, align: 'center' },
    ],
};

function parse(values: BadgeTemplateFormValues) {
    return createBadgeTemplateSchema().safeParse(values);
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
            fields: [{ ...validValues.fields[0], field: 'email' as never }],
        });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[issue.path.length - 1] === 'field')).toBe(true);
        }
    });

    it('rejects an align value outside the whitelist', () => {
        const result = parse({
            ...validValues,
            fields: [{ ...validValues.fields[0], align: 'top' as never }],
        });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[issue.path.length - 1] === 'align')).toBe(true);
        }
    });

    it('rejects negative x/y/w/h', () => {
        const negative = parse({
            ...validValues,
            fields: [{ ...validValues.fields[0], x: -1, y: -2, w: -3, h: -4 }],
        });
        expect(negative.success).toBe(false);
    });

    it('accepts zero coordinates', () => {
        expect(
            parse({ ...validValues, fields: [{ ...validValues.fields[0], x: 0, y: 0, w: 0, h: 0 }] }).success,
        ).toBe(true);
    });

    it('rejects a font size below 1', () => {
        expect(parse({ ...validValues, fields: [{ ...validValues.fields[0], size: 0 }] }).success).toBe(false);
    });

    it('rejects a non-finite coordinate (empty number input)', () => {
        expect(parse({ ...validValues, fields: [{ ...validValues.fields[0], x: Number.NaN }] }).success).toBe(false);
    });
});

describe('badgeTemplateFormDefaults', () => {
    it('starts a new template with one empty field row', () => {
        const defaults = badgeTemplateFormDefaults(null);
        expect(defaults.name).toBe('');
        expect(defaults.is_default).toBe(false);
        expect(defaults.fields).toHaveLength(1);
        expect(defaults.fields[0].field).toBe('name');
    });

    it('maps an existing template layout to form values', () => {
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
        expect(defaults.is_default).toBe(true);
        expect(defaults.fields).toHaveLength(2);
        expect(defaults.fields[0]).toEqual({ field: 'name', x: 10, y: 20, w: 30, h: 5, size: 11, align: 'right' });
    });
});

describe('badge field rows', () => {
    it('adds a new empty row', () => {
        const rows = addBadgeFieldRow([createEmptyBadgeFieldRow()]);
        expect(rows).toHaveLength(2);
        expect(rows[1]).toEqual(createEmptyBadgeFieldRow());
    });

    it('removes the row at the given index', () => {
        const rows: BadgeTemplateFormValues['fields'] = [
            { field: 'name', x: 1, y: 2, w: 3, h: 4, size: 5, align: 'left' },
            { field: 'photo', x: 6, y: 7, w: 8, h: 9, size: 10, align: 'center' },
        ];
        expect(removeBadgeFieldRow(rows, 0)).toHaveLength(1);
        expect(removeBadgeFieldRow(rows, 0)[0].field).toBe('photo');
        expect(removeBadgeFieldRow(rows, 1)).toHaveLength(1);
        expect(removeBadgeFieldRow(rows, 1)[0].field).toBe('name');
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
});
