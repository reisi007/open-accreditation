import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { BadgeTemplatePayload } from '../../api/client';
import type {
    BadgeAlign,
    BadgeField,
    BadgeFieldKey,
    BadgeImageFit,
    BadgeImageRef,
    BadgeLayoutEntry,
    BadgeTemplate,
} from '../../api/types';

/**
 * Badge template form — client-side mirror of layout schema v2 (features/
 * badge-template-editor.md). The SERVER stays authoritative; this schema only
 * mirrors its rules so authors get immediate feedback:
 *
 * - every entry: `field` whitelisted, absolute coordinates `x/y/w/h` in mm on
 *   the A6 card (`x/y ≥ 0`, `x + w ≤ width`, `y + h ≤ height`),
 * - minimum sizes: text fields 5 × 3 mm, photo/qr/image boxes 10 × 10 mm,
 * - data fields carry `size` (pt, integer 1–72) + `align`; the dedicated
 *   `qr`/`image` entries may omit both (meaningless, renderer ignores them),
 * - at most one `qr` entry (without one the PDF keeps the historical fixed
 *   bottom-right QR position),
 * - `image` entries require a source union (`brand` + logo/header ref, or
 *   `upload` + positive integer id) plus an optional `fit` (contain/cover).
 *
 * Bounds/min sizes mirror `BadgeRenderService` (`A6_WIDTH_MM`/`A6_HEIGHT_MM`)
 * and the `BadgeTemplateController` constants — no duplicated magic numbers
 * beyond the documented cross-language mirror.
 */
export const A6_WIDTH_MM = 105;

export const A6_HEIGHT_MM = 148;

export const MIN_TEXT_W_MM = 5;

export const MIN_TEXT_H_MM = 3;

export const MIN_BOX_W_MM = 10;

export const MIN_BOX_H_MM = 10;

export const MIN_FONT_SIZE_PT = 1;

export const MAX_FONT_SIZE_PT = 72;

/** Historical QR fallback spot (bottom-right, 5 mm margin, 20 × 20 mm). */
export const QR_FALLBACK_MARGIN_MM = 5;

export const QR_FALLBACK_SIZE_MM = 20;

/**
 * Flat form model of one editor row. Data fields use `field/x/y/w/h/size/
 * align`; `qr` ignores `size`/`align`; `image` additionally uses the source
 * columns (`srcKind`/`srcRef`/`imageId`) and `fit`. Keeping the row flat maps
 * 1:1 onto number inputs (react-hook-form `valueAsNumber`) instead of juggling
 * nested discriminated unions in the form state — the wire-format union is
 * produced by `buildBadgeTemplatePayload`.
 */
export interface BadgeRowValues {
    field: BadgeEntryKey;
    x: number;
    y: number;
    w: number;
    h: number;
    size: number;
    align: BadgeAlign;
    /** Image source kind — `none` blocks saving until a source is picked. */
    srcKind: 'none' | 'brand' | 'upload';
    /** Brand ref (used when `srcKind === 'brand'`). */
    srcRef: BadgeImageRef;
    /** Uploaded badge image id (used when `srcKind === 'upload'`). */
    imageId: number;
    fit: BadgeImageFit;
}

/** Every element type the editor palette offers (data fields + qr + image). */
export type BadgeEntryKey = BadgeFieldKey | 'qr' | 'image';

const createRowSchema = () =>
    z.object({
        field: z.enum(['name', 'category', 'event', 'date', 'photo', 'status', 'team', 'vest_number', 'qr', 'image']),
        x: z.number(t`X muss eine Zahl sein.`).min(0, t`X muss 0 oder größer sein.`),
        y: z.number(t`Y muss eine Zahl sein.`).min(0, t`Y muss 0 oder größer sein.`),
        w: z.number(t`Breite muss eine Zahl sein.`).min(0, t`Breite muss 0 oder größer sein.`),
        h: z.number(t`Höhe muss eine Zahl sein.`).min(0, t`Höhe muss 0 oder größer sein.`),
        // Mirrors the server's scalar layer: integer 1–72 for EVERY entry
        // (the qr/image renderer ignores it, the API still range-checks it).
        size: z
            .number(t`Schriftgröße muss eine Zahl sein.`)
            .int(t`Schriftgröße muss eine ganze Zahl sein.`)
            .min(MIN_FONT_SIZE_PT, t`Schriftgröße muss mindestens 1 sein.`)
            .max(MAX_FONT_SIZE_PT, t`Schriftgröße darf höchstens 72 sein.`),
        align: z.enum(['left', 'center', 'right']),
        srcKind: z.enum(['none', 'brand', 'upload']),
        srcRef: z.enum(['logo', 'header']),
        imageId: z.number(t`Bild-ID muss eine Zahl sein.`),
        fit: z.enum(['contain', 'cover']),
    });

type RowValues = z.infer<ReturnType<typeof createRowSchema>>;

/** True for entries rendered as picture boxes (10 × 10 mm minimum). */
export function isBoxEntry(key: BadgeEntryKey): boolean {
    return key === 'photo' || key === 'qr' || key === 'image';
}

/**
 * True when the properties panel shows font size + alignment (all data
 * fields — the API requires both there); false for the qr/image entries whose
 * renderer output ignores them.
 */
export function showsTypography(key: BadgeEntryKey): boolean {
    return !isSpecialEntry(key);
}

/** True for the dedicated non-data entry types (`qr`, `image`). */
export function isSpecialEntry(key: BadgeEntryKey): boolean {
    return key === 'qr' || key === 'image';
}

/**
 * Per-row mirror of the cross-field geometry rules (bounds, minimum sizes,
 * required image source). Runs on every parse; issues land on exact leaf keys
 * so the properties panel can highlight the offending input.
 */
function refineGeometryAndSource(row: RowValues, ctx: z.RefinementCtx): void {
    if (row.x + row.w > A6_WIDTH_MM) {
        ctx.addIssue({ code: 'custom', path: ['w'], message: t`Das Feld ragt über den rechten Rand hinaus.` });
    }

    if (row.y + row.h > A6_HEIGHT_MM) {
        ctx.addIssue({ code: 'custom', path: ['h'], message: t`Das Feld ragt über den unteren Rand hinaus.` });
    }

    if (isBoxEntry(row.field)) {
        if (row.w < MIN_BOX_W_MM) {
            ctx.addIssue({ code: 'custom', path: ['w'], message: t`Die Mindestbreite beträgt 10 mm.` });
        }

        if (row.h < MIN_BOX_H_MM) {
            ctx.addIssue({ code: 'custom', path: ['h'], message: t`Die Mindesthöhe beträgt 10 mm.` });
        }
    } else {
        if (row.w < MIN_TEXT_W_MM) {
            ctx.addIssue({ code: 'custom', path: ['w'], message: t`Die Mindestbreite beträgt 5 mm.` });
        }

        if (row.h < MIN_TEXT_H_MM) {
            ctx.addIssue({ code: 'custom', path: ['h'], message: t`Die Mindesthöhe beträgt 3 mm.` });
        }
    }

    if (row.field === 'image') {
        if (row.srcKind === 'none') {
            ctx.addIssue({ code: 'custom', path: ['srcKind'], message: t`Für das Bild muss eine Quelle gewählt werden.` });
        } else if (row.srcKind === 'upload' && (!Number.isInteger(row.imageId) || row.imageId < 1)) {
            ctx.addIssue({ code: 'custom', path: ['imageId'], message: t`Bitte ein hochgeladenes Bild auswählen.` });
        }
    }
}

export const createBadgeTemplateSchema = () =>
    z.object({
        name: z.string().trim().min(1, t`Name ist erforderlich.`),
        is_default: z.boolean(),
        fields: z
            .array(createRowSchema().superRefine(refineGeometryAndSource))
            .min(1, t`Mindestens ein Feld ist erforderlich.`)
            .superRefine((rows, ctx) => {
                let qrSeen = false;
                rows.forEach((row, index) => {
                    if (row.field !== 'qr') {
                        return;
                    }
                    if (qrSeen) {
                        ctx.addIssue({
                            code: 'custom',
                            path: [index, 'field'],
                            message: t`Es darf nur einen QR-Code geben.`,
                        });
                    }
                    qrSeen = true;
                });
            }),
    });

export type BadgeTemplateFormValues = z.infer<ReturnType<typeof createBadgeTemplateSchema>>;

export const BADGE_FIELD_KEYS: readonly BadgeFieldKey[] = [
    'name',
    'category',
    'event',
    'date',
    'photo',
    'status',
    'team',
    'vest_number',
];

export const BADGE_ENTRY_KEYS: readonly BadgeEntryKey[] = [...BADGE_FIELD_KEYS, 'qr', 'image'];

export const BADGE_ALIGNS: readonly BadgeAlign[] = ['left', 'center', 'right'];

export const BADGE_IMAGE_REFS: readonly BadgeImageRef[] = ['logo', 'header'];

export const BADGE_IMAGE_FITS: readonly BadgeImageFit[] = ['contain', 'cover'];

/** Two-column overlap test of the mm rectangles (NaN-safe: non-finite rows never block). */
function rectanglesOverlap(
    a: { x: number; y: number; w: number; h: number },
    b: { x: number; y: number; w: number; h: number },
): boolean {
    return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
}

/**
 * First free slot for a new `w × h` mm box, scanning top-left to bottom-right
 * on a coarse 5 mm grid so new elements don't stack invisibly on top of each
 * other. Falls back to the card origin when nothing fits (validation then
 * guides the author to a valid spot).
 */
export function findFreePosition(
    rows: ReadonlyArray<Pick<BadgeRowValues, 'x' | 'y' | 'w' | 'h'>>,
    w: number,
    h: number,
): { x: number; y: number } {
    const GRID_STEP_MM = 5;
    const occupied = rows.filter((row) => [row.x, row.y, row.w, row.h].every(Number.isFinite));

    for (let y = 0; y + h <= A6_HEIGHT_MM; y += GRID_STEP_MM) {
        for (let x = 0; x + w <= A6_WIDTH_MM; x += GRID_STEP_MM) {
            const candidate = { x, y, w, h };
            if (!occupied.some((row) => rectanglesOverlap(candidate, row))) {
                return { x, y };
            }
        }
    }

    return { x: 0, y: 0 };
}

/**
 * Default values of a newly added palette element: data fields get the
 * historical text defaults (`40 × 8`, 12 pt, left) at the next free position,
 * `qr` starts at the historical fixed fallback spot and `image` starts as a
 * 30 × 20 box WITHOUT a source (saving stays blocked until one is chosen).
 */
export function createDefaultBadgeRow(key: BadgeEntryKey, existingRows: readonly BadgeRowValues[]): BadgeRowValues {
    const base: BadgeRowValues = {
        field: key,
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
    };

    if (key === 'qr') {
        return {
            ...base,
            w: QR_FALLBACK_SIZE_MM,
            h: QR_FALLBACK_SIZE_MM,
            x: A6_WIDTH_MM - QR_FALLBACK_MARGIN_MM - QR_FALLBACK_SIZE_MM,
            y: A6_HEIGHT_MM - QR_FALLBACK_MARGIN_MM - QR_FALLBACK_SIZE_MM,
        };
    }

    if (key === 'image') {
        const position = findFreePosition(existingRows, 30, 20);
        return { ...base, w: 30, h: 20, ...position };
    }

    const position = findFreePosition(existingRows, base.w, base.h);
    return { ...base, ...position };
}

/** Stored layout entry (schema v2 union) → flat form row. */
function rowFromLayoutEntry(entry: BadgeLayoutEntry): BadgeRowValues {
    const base: BadgeRowValues = {
        field: entry.field,
        x: entry.x,
        y: entry.y,
        w: entry.w,
        h: entry.h,
        size: 12,
        align: 'left',
        srcKind: 'none',
        srcRef: 'logo',
        imageId: 0,
        fit: 'contain',
    };

    if (entry.field === 'image') {
        return {
            ...base,
            srcKind: entry.src.kind === 'brand' ? 'brand' : 'upload',
            srcRef: entry.src.kind === 'brand' ? entry.src.ref : 'logo',
            imageId: entry.src.kind === 'upload' ? entry.src.image_id : 0,
            fit: entry.fit ?? 'contain',
        };
    }

    if (entry.field === 'qr') {
        return base;
    }

    return { ...base, size: entry.size, align: entry.align };
}

/** Flat form row → stored layout entry (wire format, schema v2 union). */
function layoutEntryFromRow(row: BadgeRowValues): BadgeLayoutEntry {
    const geometry = { x: row.x, y: row.y, w: row.w, h: row.h };

    if (row.field === 'qr') {
        // Spec example shape: qr/image entries carry coordinates only — the
        // meaningless size/align are omitted.
        return { field: 'qr', ...geometry };
    }

    if (row.field === 'image') {
        const imageSrc: Extract<BadgeLayoutEntry, { field: 'image' }>['src'] =
            row.srcKind === 'brand' ? { kind: 'brand', ref: row.srcRef } : { kind: 'upload', image_id: row.imageId };

        return { field: 'image', ...geometry, src: imageSrc, fit: row.fit };
    }

    const field: BadgeField = { field: row.field, ...geometry, size: row.size, align: row.align };
    return field;
}

export function badgeTemplateFormDefaults(initial: BadgeTemplate | null): BadgeTemplateFormValues {
    const rows: BadgeRowValues[] =
        initial && initial.layout.length > 0 ? initial.layout.map(rowFromLayoutEntry) : [createDefaultBadgeRow('name', [])];

    return {
        name: initial?.name ?? '',
        is_default: initial?.is_default ?? false,
        fields: rows,
    };
}

export function buildBadgeTemplatePayload(values: BadgeTemplateFormValues): BadgeTemplatePayload {
    return {
        name: values.name,
        layout: values.fields.map(layoutEntryFromRow),
        is_default: values.is_default,
    };
}

export function badgeFieldLabel(field: BadgeEntryKey, i18n: I18n): string {
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
        case 'team':
            return i18n._(t`Team`);
        case 'vest_number':
            return i18n._(t`Westennummer`);
        case 'qr':
            return i18n._(t`QR-Code`);
        case 'image':
            return i18n._(t`Bild`);
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

export function badgeImageRefLabel(ref: BadgeImageRef, i18n: I18n): string {
    switch (ref) {
        case 'logo':
            return i18n._(t`Mandanten-Logo`);
        case 'header':
            return i18n._(t`Kopfbild`);
    }
}

export function badgeFitLabel(fit: BadgeImageFit, i18n: I18n): string {
    switch (fit) {
        case 'contain':
            return i18n._(t`Einpassen`);
        case 'cover':
            return i18n._(t`Füllen`);
    }
}
