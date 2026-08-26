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
 * Editor raster for the canvas grid AND the drag snap step (mm). FE4 adds the
 * fine-positioning toggle; see features/badge-template-editor.md.
 */
export const CANVAS_GRID_STEP_MM = 5;

/** Axis-aligned rectangle in millimetres on the A6 card. */
export interface MmRect {
    x: number;
    y: number;
    w: number;
    h: number;
}

/**
 * Rounds a coordinate onto the editor grid (nearest multiple of the step).
 * Non-finite input snaps to 0 so an in-progress edit can never produce NaN
 * positions.
 */
export function snapToGrid(value: number, step: number = CANVAS_GRID_STEP_MM): number {
    if (!Number.isFinite(value) || !Number.isFinite(step) || step <= 0) {
        return 0;
    }
    return Math.round(value / step) * step;
}

/**
 * Hard-clamps a rectangle into the A6 card (`x/y ≥ 0`, `x+w ≤ width`,
 * `y+h ≤ height`). An oversized rectangle anchors at the origin — dragging
 * cannot fix its size; the zod mirror flags it instead.
 */
export function clampToBounds(rect: MmRect): MmRect {
    const w = Number.isFinite(rect.w) && rect.w > 0 ? rect.w : 0;
    const h = Number.isFinite(rect.h) && rect.h > 0 ? rect.h : 0;
    return {
        x: Math.min(Math.max(Number.isFinite(rect.x) ? rect.x : 0, 0), Math.max(A6_WIDTH_MM - w, 0)),
        y: Math.min(Math.max(Number.isFinite(rect.y) ? rect.y : 0, 0), Math.max(A6_HEIGHT_MM - h, 0)),
        w,
        h,
    };
}

/**
 * Resulting position of a dragged box: origin + pointer delta (in mm),
 * snapped onto the grid, then hard-clamped into the A6 bounds (bounds win
 * over snapping).
 */
export function computeDragPosition(
    origin: { x: number; y: number },
    deltaMm: { x: number; y: number },
    size: { w: number; h: number },
): { x: number; y: number } {
    const clamped = clampToBounds({
        x: snapToGrid(origin.x + deltaMm.x),
        y: snapToGrid(origin.y + deltaMm.y),
        w: size.w,
        h: size.h,
    });
    return { x: clamped.x, y: clamped.y };
}

/** Corner of a box acting as a resize handle (FE4). */
export type ResizeCorner = 'nw' | 'ne' | 'sw' | 'se';

/**
 * Resulting rectangle of a corner-resize drag (FE4): the two edges OPPOSITE
 * the grabbed corner stay fixed, the grabbed edges follow the pointer delta
 * (snapped onto the grid), then every moved edge is hard-clamped into the A6
 * bounds while keeping at least the given minimum size between the fixed and
 * the moved edge (bounds win over snapping).
 */
export function computeDragResize(
    origin: MmRect,
    corner: ResizeCorner,
    deltaMm: { x: number; y: number },
    minW: number,
    minH: number,
): MmRect {
    const startLeft = Number.isFinite(origin.x) ? origin.x : 0;
    const startTop = Number.isFinite(origin.y) ? origin.y : 0;
    const startRight = startLeft + (Number.isFinite(origin.w) ? origin.w : 0);
    const startBottom = startTop + (Number.isFinite(origin.h) ? origin.h : 0);

    let left = startLeft;
    let right = startRight;
    let top = startTop;
    let bottom = startBottom;

    if (corner === 'ne' || corner === 'se') {
        right = snapToGrid(startRight + deltaMm.x);
    }
    if (corner === 'nw' || corner === 'sw') {
        left = snapToGrid(startLeft + deltaMm.x);
    }
    if (corner === 'sw' || corner === 'se') {
        bottom = snapToGrid(startBottom + deltaMm.y);
    }
    if (corner === 'nw' || corner === 'ne') {
        top = snapToGrid(startTop + deltaMm.y);
    }

    // Degenerate origins (fixed edge already violates the minimum or the card)
    // collapse the allowed range to the card edge — validation flags those
    // states, resizing must simply stay inside the card.
    left = Math.min(Math.max(left, 0), Math.max(startRight - minW, 0));
    right = Math.min(Math.max(right, Math.min(startLeft + minW, A6_WIDTH_MM)), A6_WIDTH_MM);
    top = Math.min(Math.max(top, 0), Math.max(startBottom - minH, 0));
    bottom = Math.min(Math.max(bottom, Math.min(startTop + minH, A6_HEIGHT_MM)), A6_HEIGHT_MM);

    return { x: left, y: top, w: right - left, h: bottom - top };
}

/**
 * Magnetic alignment distance (mm, FE4): a moving edge within this distance
 * of an alignment target snaps flush onto it and raises a guide line.
 */
export const ALIGNMENT_SNAP_THRESHOLD_MM = 2;

/** Correction that makes an alignment exact (mm, 0 = nothing aligns). */
export interface AlignmentSnapResult {
    offsetX: number;
    offsetY: number;
}

/** Guide line positions (mm) currently flush with the edited rect. */
export interface AlignmentGuides {
    vertical: number[];
    horizontal: number[];
}

export const NO_ALIGNMENT_GUIDES: AlignmentGuides = { vertical: [], horizontal: [] };

/** Coincidence tolerance for rendered guide lines (mm). */
const GUIDE_EPSILON_MM = 0.01;

/** Leading edge, centre and trailing edge of a rect on one axis. */
function rectEdges(rect: MmRect, axis: 'x' | 'y'): [number, number, number] {
    return axis === 'x'
        ? [rect.x, rect.x + rect.w / 2, rect.x + rect.w]
        : [rect.y, rect.y + rect.h / 2, rect.y + rect.h];
}

/**
 * Alignment targets on one axis: every other rect's start/centre/end plus the
 * card centre. Non-finite geometry (in-progress edits) yields no targets.
 */
function axisTargets(others: ReadonlyArray<MmRect>, axis: 'x' | 'y'): number[] {
    const cardSize = axis === 'x' ? A6_WIDTH_MM : A6_HEIGHT_MM;
    const targets = [cardSize / 2];
    for (const other of others) {
        const start = axis === 'x' ? other.x : other.y;
        const size = axis === 'x' ? other.w : other.h;
        if (!Number.isFinite(start) || !Number.isFinite(size)) {
            continue;
        }
        targets.push(start, start + size / 2, start + size);
    }
    return targets;
}

/** Closest edge/target pair within the threshold → correction making it exact. */
function snapAxisDelta(edges: readonly number[], targets: readonly number[]): number {
    let bestDistance = ALIGNMENT_SNAP_THRESHOLD_MM;
    let bestOffset = 0;
    for (const edge of edges) {
        for (const target of targets) {
            const distance = Math.abs(target - edge);
            if (distance <= bestDistance) {
                bestDistance = distance;
                bestOffset = target - edge;
            }
        }
    }
    return bestOffset;
}

/**
 * Magnetic alignment (FE4): compares the moving rect's leading/centre/trailing
 * edges against the other rows' edges/centres AND the card centre; the closest
 * match within {@link ALIGNMENT_SNAP_THRESHOLD_MM} wins and is returned as a
 * position correction that makes the alignment exact. NaN-safe: non-finite
 * rects never snap (in-progress edits stay untouched).
 */
export function computeAlignmentSnap(rect: MmRect, others: ReadonlyArray<MmRect>): AlignmentSnapResult {
    if (![rect.x, rect.y, rect.w, rect.h].every(Number.isFinite)) {
        return { offsetX: 0, offsetY: 0 };
    }
    return {
        offsetX: snapAxisDelta(rectEdges(rect, 'x'), axisTargets(others, 'x')),
        offsetY: snapAxisDelta(rectEdges(rect, 'y'), axisTargets(others, 'y')),
    };
}

/**
 * Guide lines for RENDERING (FE4): every target position exactly flush
 * (± {@link GUIDE_EPSILON_MM}) with one of the rect's edges/centre —
 * deduplicated, sorted. Unlike {@link computeAlignmentSnap} this is
 * threshold-free, so guides never lie about the final geometry.
 */
export function findAlignedGuides(rect: MmRect, others: ReadonlyArray<MmRect>): AlignmentGuides {
    if (![rect.x, rect.y, rect.w, rect.h].every(Number.isFinite)) {
        return NO_ALIGNMENT_GUIDES;
    }
    const collect = (edges: readonly number[], targets: readonly number[]): number[] => {
        const rounded = new Set<number>();
        for (const edge of edges) {
            for (const target of targets) {
                if (Math.abs(target - edge) < GUIDE_EPSILON_MM) {
                    rounded.add(Math.round(target * 100) / 100);
                }
            }
        }
        return [...rounded].sort((a, b) => a - b);
    };
    return {
        vertical: collect(rectEdges(rect, 'x'), axisTargets(others, 'x')),
        horizontal: collect(rectEdges(rect, 'y'), axisTargets(others, 'y')),
    };
}

/** Direction of a keyboard nudge (arrow keys). */
export type NudgeDirection = 'left' | 'right' | 'up' | 'down';

/** Maps an arrow-key name to its nudge direction (null for any other key). */
export function nudgeDirectionFromKey(key: string): NudgeDirection | null {
    switch (key) {
        case 'ArrowLeft':
            return 'left';
        case 'ArrowRight':
            return 'right';
        case 'ArrowUp':
            return 'up';
        case 'ArrowDown':
            return 'down';
        default:
            return null;
    }
}

/**
 * Position after nudging the box by `stepMm` in the given direction (FE4:
 * arrow keys move 1 mm, Shift moves the 5 mm grid step — the step itself is a
 * caller concern). Hard-clamped into the A6 bounds: a box pushed against the
 * edge stays put instead of leaving the card. NaN-safe like the drag path.
 */
export function computeNudgePosition(
    current: { x: number; y: number },
    direction: NudgeDirection,
    stepMm: number,
    size: { w: number; h: number },
): { x: number; y: number } {
    const dx = direction === 'left' ? -stepMm : direction === 'right' ? stepMm : 0;
    const dy = direction === 'up' ? -stepMm : direction === 'down' ? stepMm : 0;
    const clamped = clampToBounds({
        x: (Number.isFinite(current.x) ? current.x : 0) + dx,
        y: (Number.isFinite(current.y) ? current.y : 0) + dy,
        w: Number.isFinite(size.w) ? size.w : 0,
        h: Number.isFinite(size.h) ? size.h : 0,
    });
    return { x: clamped.x, y: clamped.y };
}

/**
 * Two rectangles strictly intersect (shared edges do NOT count as overlap).
 * NaN-safe: any non-finite coordinate yields false, so in-progress edits
 * never warn.
 */
export function boxesOverlap(a: MmRect, b: MmRect): boolean {
    return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
}

/**
 * Indices of all rows overlapping ANY other row — soft warning marker only
 * (the server stays authoritative, saving remains allowed).
 */
export function findOverlappingIndices(rows: ReadonlyArray<MmRect>): Set<number> {
    const overlapping = new Set<number>();
    for (let i = 0; i < rows.length; i += 1) {
        for (let j = i + 1; j < rows.length; j += 1) {
            if (boxesOverlap(rows[i], rows[j])) {
                overlapping.add(i);
                overlapping.add(j);
            }
        }
    }
    return overlapping;
}

/**
 * Indices of rows whose DATA field type appears more than once (soft warning,
 * FE2-F1: repeated data fields are surfaced consistently instead of only `qr`
 * being special-cased). Only `qr`/`image` entries are exempt — qr duplicates
 * are hard-blocked by validation and repeated `image` entries are legitimate
 * co-branding (features/badge-template-editor.md).
 */
export function findDuplicateDataFieldIndices(rows: ReadonlyArray<Pick<BadgeRowValues, 'field'>>): Set<number> {
    const counts = new Map<BadgeEntryKey, number>();
    for (const row of rows) {
        if (isSpecialEntry(row.field)) continue;
        counts.set(row.field, (counts.get(row.field) ?? 0) + 1);
    }
    const duplicates = new Set<number>();
    rows.forEach((row, index) => {
        if (!isSpecialEntry(row.field) && (counts.get(row.field) ?? 0) > 1) {
            duplicates.add(index);
        }
    });
    return duplicates;
}

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

/**
 * First free slot for a new `w × h` mm box, scanning top-left to bottom-right
 * on a coarse grid so new elements don't stack invisibly on top of each
 * other. Falls back to the card origin when nothing fits (validation then
 * guides the author to a valid spot).
 */
export function findFreePosition(
    rows: ReadonlyArray<Pick<BadgeRowValues, 'x' | 'y' | 'w' | 'h'>>,
    w: number,
    h: number,
): { x: number; y: number } {
    const occupied = rows.filter((row) => [row.x, row.y, row.w, row.h].every(Number.isFinite));

    for (let y = 0; y + h <= A6_HEIGHT_MM; y += CANVAS_GRID_STEP_MM) {
        for (let x = 0; x + w <= A6_WIDTH_MM; x += CANVAS_GRID_STEP_MM) {
            const candidate = { x, y, w, h };
            if (!occupied.some((row) => boxesOverlap(candidate, row))) {
                return { x, y };
            }
        }
    }

    return { x: 0, y: 0 };
}

/**
 * Default values of a newly added palette element: data fields get the
 * historical text defaults (`40 × 8`, 12 pt, left) at the next free position,
 * `photo` starts as a 30 × 30 portrait box (box entries need ≥ 10 × 10 mm),
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

    if (key === 'photo') {
        // Box entry: the text defaults (40 × 8) would violate the 10 × 10
        // minimum immediately — start with a sane portrait box instead.
        const position = findFreePosition(existingRows, 30, 30);
        return { ...base, w: 30, h: 30, ...position };
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

/*
 * ---------------------------------------------------------------------------
 * FE4-F1 wrap-aware canvas auto-fit (consumed by BadgeCanvas)
 * ---------------------------------------------------------------------------
 * The editor canvas must never clip its sample text vertically — including
 * when a narrow box FORCES the sample onto multiple lines (the reported bug:
 * „Max Mustermann" wrapped in a 40 × 8 mm box and the second line crossed the
 * selection frame). Exact text measurement is impossible during render, so
 * the font is bounded by TWO independent container-relative constraints and
 * CSS `min()` picks whichever binds first:
 *
 * 1. One-line WIDTH cap (cqw against the box itself):
 *      chars × CHAR_WIDTH_EM × font ≤ usable box width
 *    → the sample fits on a single line whenever the model says so.
 * 2. Multi-line HEIGHT cap (cqh against the box itself):
 *      MAX_LINES × LINE_HEIGHT_FACTOR × font ≤ safe box height
 *    → even if real browser metrics are wider than the model and the text
 *      wraps anyway, the wrapped block still fits the box VERTICALLY.
 *
 * Together this realizes "fits as a single line OR as an N-line wrap with
 * N × lineHeight ≤ boxHeight" per render size: large boxes keep the authored
 * `size` (both caps exceed it), narrow/short boxes shrink instead of
 * clipping. Both caps resolve against the BOX (`.badge-canvas-box` sets
 * `container-type: size`), so they adapt to the live editor zoom level.
 */

/** Tailwind `leading-tight` line-height factor used inside canvas boxes. */
export const BADGE_FIT_LINE_HEIGHT_FACTOR = 1.25;

/** Line count the height cap reserves wrap room for (conservative bound). */
export const BADGE_FIT_MAX_LINES = 2;

/**
 * Sub-pixel rounding guard subtracted from the caps (px). Container units
 * resolve against the box's CONTENT box, so its padding/border are already
 * excluded — no further chrome compensation needed beyond hairline safety.
 */
export const BADGE_FIT_SLACK_PX = 0.5;

/**
 * Conservative average glyph advance (em) for the mixed-case Latin samples
 * ("Max Mustermann" ≈ 0.55 em/char in semibold system fonts; 0.58 errs
 * toward detecting wrap pressure earlier rather than later).
 */
export const BADGE_FIT_CHAR_WIDTH_EM = 0.58;

/**
 * Height-cap percentage for BOX entries (`qr` / `photo` / `image`), resolved
 * against the box itself. Introduced WITH the wrap-aware auto-fit: before it,
 * box entries rendered their authored size verbatim, so this is NEW behavior,
 * not a ported legacy rule. Per entry:
 * - `qr` carries no own glyph size class and therefore now scales DOWN with
 *   its box instead of overflowing a short box — intended.
 * - `photo` / `image` glyphs carry fixed `text-*` size classes, so they stay
 *   visually unchanged; the capped font-size only affects what they inherit.
 */
const BOX_ENTRY_HEIGHT_CAP_CQH = 80;

/**
 * Height-cap coefficient in cqh (of the BOX) reserving wrap room for
 * {@link BADGE_FIT_MAX_LINES} lines: `100 / (lines × lineHeight)` as a
 * percentage. Pure ratio — deliberately independent of any mm geometry,
 * because the box itself is the query container.
 */
export function badgeCanvasMultiLineCapCqh(): number {
    return 100 / (BADGE_FIT_MAX_LINES * BADGE_FIT_LINE_HEIGHT_FACTOR);
}

const formatLength = (value: number, unit: string): string => `${value.toFixed(3)}${unit}`;

/**
 * Width cap coefficient in cqw for a single-line sample, or null when there
 * is nothing textual to fit (picture boxes / empty samples).
 */
export function badgeCanvasOneLineCapCqw(sampleChars: number): number | null {
    if (!Number.isFinite(sampleChars) || sampleChars <= 0) {
        return null;
    }
    return 100 / (sampleChars * BADGE_FIT_CHAR_WIDTH_EM);
}

/** Padding share of the width cap in px (mirrors {@link badgeCanvasOneLineCapCqw}). */
export function badgeCanvasOneLineSlackPx(sampleChars: number): number | null {
    if (!Number.isFinite(sampleChars) || sampleChars <= 0) {
        return null;
    }
    return BADGE_FIT_SLACK_PX;
}

/** Sub-pixel guard of the height cap in px. */
export function badgeCanvasMultiLineSlackPx(): number {
    return BADGE_FIT_SLACK_PX;
}

/**
 * Complete `font-size` declaration for one canvas box (Tailwind-Only-Policy
 * exception: every input is a runtime author value). Text fields get the
 * one-line width cap plus the wrap-safe height cap; box entries (`qr`,
 * `photo`, `image`) contain no sample text and get only the box-height cap
 * ({@link BOX_ENTRY_HEIGHT_CAP_CQH}) — new with this auto-fit, so the `qr`
 * glyph scales with its box, while photo/image glyphs keep their fixed
 * `text-*` sizes regardless of this property.
 */
export function badgeCanvasFontSizeCss(
    field: BadgeEntryKey,
    sampleChars: number,
    desiredSizePx: number,
): string {
    if (isBoxEntry(field)) {
        return `min(${desiredSizePx}px, max(calc(${formatLength(BOX_ENTRY_HEIGHT_CAP_CQH, 'cqh')} - ${formatLength(BADGE_FIT_SLACK_PX, 'px')}), 2px))`;
    }

    const heightTerm =
        `calc(${formatLength(badgeCanvasMultiLineCapCqh(), 'cqh')} - ${formatLength(badgeCanvasMultiLineSlackPx(), 'px')})`;
    const capCqw = badgeCanvasOneLineCapCqw(sampleChars);
    const widthTerm =
        capCqw === null
            ? null
            : `calc(${formatLength(capCqw, 'cqw')} - ${formatLength(badgeCanvasOneLineSlackPx(sampleChars) ?? 0, 'px')})`;
    const inner = widthTerm === null
        ? `min(${desiredSizePx}px, ${heightTerm})`
        : `min(${desiredSizePx}px, ${widthTerm}, ${heightTerm})`;
    return `max(${inner}, 2px)`;
}
