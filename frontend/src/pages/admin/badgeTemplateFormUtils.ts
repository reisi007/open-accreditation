import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { BadgeTemplatePayload } from '../../api/client';
import type { BadgeAlign, BadgeField, BadgeFieldKey, BadgeTemplate } from '../../api/types';

/**
 * Badge template form (P4). The layout contract: `x/y/w/h` in mm (≥ 0),
 * `size` in pt (> 0), `field`/`align` whitelisted, at least one field. Number
 * inputs register with `valueAsNumber`, so the form values are numbers
 * throughout (an empty input is NaN and fails the `min` validation).
 */
export const createBadgeTemplateSchema = () =>
    z.object({
        name: z.string().trim().min(1, t`Name ist erforderlich.`),
        is_default: z.boolean(),
        fields: z
            .array(
                z.object({
                    field: z.enum(['name', 'category', 'event', 'date', 'photo', 'status']),
                    x: z.number().min(0, t`X muss 0 oder größer sein.`),
                    y: z.number().min(0, t`Y muss 0 oder größer sein.`),
                    w: z.number().min(0, t`Breite muss 0 oder größer sein.`),
                    h: z.number().min(0, t`Höhe muss 0 oder größer sein.`),
                    size: z.number().min(1, t`Schriftgröße muss mindestens 1 sein.`),
                    align: z.enum(['left', 'center', 'right']),
                }),
            )
            .min(1, t`Mindestens ein Feld ist erforderlich.`),
    });

export type BadgeTemplateFormValues = z.infer<ReturnType<typeof createBadgeTemplateSchema>>;

export type BadgeFieldFormValues = BadgeTemplateFormValues['fields'][number];

export const BADGE_FIELD_KEYS: readonly BadgeFieldKey[] = ['name', 'category', 'event', 'date', 'photo', 'status'];

export const BADGE_ALIGNS: readonly BadgeAlign[] = ['left', 'center', 'right'];

export function createEmptyBadgeFieldRow(): BadgeFieldFormValues {
    return { field: 'name', x: 0, y: 0, w: 40, h: 8, size: 12, align: 'left' };
}

export function addBadgeFieldRow(fields: readonly BadgeFieldFormValues[]): BadgeFieldFormValues[] {
    return [...fields, createEmptyBadgeFieldRow()];
}

export function removeBadgeFieldRow(fields: readonly BadgeFieldFormValues[], index: number): BadgeFieldFormValues[] {
    return fields.filter((_, rowIndex) => rowIndex !== index);
}

export function badgeTemplateFormDefaults(initial: BadgeTemplate | null): BadgeTemplateFormValues {
    const rows: BadgeFieldFormValues[] =
        initial && initial.layout.length > 0
            ? initial.layout.map((field) => ({
                  field: field.field,
                  x: field.x,
                  y: field.y,
                  w: field.w,
                  h: field.h,
                  size: field.size,
                  align: field.align,
              }))
            : [createEmptyBadgeFieldRow()];

    return {
        name: initial?.name ?? '',
        is_default: initial?.is_default ?? false,
        fields: rows,
    };
}

export function buildBadgeTemplatePayload(values: BadgeTemplateFormValues): BadgeTemplatePayload {
    return {
        name: values.name,
        layout: values.fields.map(
            (field): BadgeField => ({
                field: field.field,
                x: field.x,
                y: field.y,
                w: field.w,
                h: field.h,
                size: field.size,
                align: field.align,
            }),
        ),
        is_default: values.is_default,
    };
}

export function badgeFieldLabel(field: BadgeFieldKey, i18n: I18n): string {
    switch (field) {
        case 'name':
            return i18n._(t`Name`);
        case 'category':
            return i18n._(t`Kategorie`);
        case 'event':
            return i18n._(t`Event`);
        case 'date':
            return i18n._(t`Datum`);
        case 'photo':
            return i18n._(t`Foto`);
        case 'status':
            return i18n._(t`Status`);
    }
}

export function badgeAlignLabel(align: BadgeAlign, i18n: I18n): string {
    switch (align) {
        case 'left':
            return i18n._(t`Links`);
        case 'center':
            return i18n._(t`Zentriert`);
        case 'right':
            return i18n._(t`Rechts`);
    }
}
