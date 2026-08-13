import { describe, expect, it } from 'vitest';
import type { Category } from '../../api/types';
import { buildCategoryPayload, categoryFormDefaults, createCategorySchema, type CategoryFormValues } from './categoryFormUtils';

const baseValues: CategoryFormValues = {
    name: 'Presse',
    slug: 'presse',
    description: '',
    team_id: '',
};

function valuesWith(overrides: Partial<CategoryFormValues>): CategoryFormValues {
    return { ...baseValues, ...overrides };
}

describe('buildCategoryPayload', () => {
    it('maps the form fields onto the API payload', () => {
        const payload = buildCategoryPayload(
            valuesWith({ description: '  Pressemappe  ', team_id: '5' }),
        );

        expect(payload).toEqual({
            name: 'Presse',
            slug: 'presse',
            description: 'Pressemappe',
            team_id: 5,
        });
    });

    it('sends team_id null for the mandant level', () => {
        const payload = buildCategoryPayload(valuesWith({ team_id: '' }));

        expect(payload.team_id).toBeNull();
    });

    it('sends description null when the field is empty', () => {
        const payload = buildCategoryPayload(valuesWith({ description: '   ' }));

        expect(payload.description).toBeNull();
    });
});

describe('categoryFormDefaults', () => {
    it('returns empty values for a new category', () => {
        expect(categoryFormDefaults(null)).toEqual({
            name: '',
            slug: '',
            description: '',
            team_id: '',
        });
    });

    it('maps a stored category onto the form fields', () => {
        const category: Category = {
            id: 3,
            mandant_id: 1,
            team_id: 9,
            name: 'Presse',
            slug: 'presse',
            description: 'Für Medienvertreter',
            is_team_override: true,
            team: { id: 9, name: 'Musterverein' },
        };

        const defaults = categoryFormDefaults(category);

        expect(defaults.name).toBe('Presse');
        expect(defaults.team_id).toBe('9');
    });
});

describe('createCategorySchema', () => {
    it('rejects an invalid slug', () => {
        const schema = createCategorySchema();

        const result = schema.safeParse(valuesWith({ slug: 'Presse!' }));

        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues[0]?.path).toEqual(['slug']);
        }
    });
});
