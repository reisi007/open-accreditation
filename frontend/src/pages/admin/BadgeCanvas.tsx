import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent } from 'react';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    type AlignmentGuides,
    badgeFieldLabel,
    CANVAS_GRID_STEP_MM,
    clampToBounds,
    computeAlignmentSnap,
    computeDragPosition,
    computeDragResize,
    computeNudgePosition,
    findAlignedGuides,
    badgeCanvasFontSizeCss,
    isBoxEntry,
    MIN_BOX_H_MM,
    MIN_BOX_W_MM,
    MIN_TEXT_H_MM,
    MIN_TEXT_W_MM,
    NO_ALIGNMENT_GUIDES,
    nudgeDirectionFromKey,
    type BadgeRowValues,
    type MmRect,
    type NudgeDirection,
    type ResizeCorner,
} from './badgeTemplateFormUtils';

/**
 * Interactive A6 canvas of the badge template editor (FE3 drag + FE4 polish,
 * features/badge-template-editor.md): every layout row renders as a box
 * positioned in percent of the REAL A6 sheet (105 × 148 mm), so preview and
 * print share one coordinate system (WYSIWYG in mm).
 *
 * Interactions (all writing straight into react-hook-form — single source of
 * truth):
 * - MOVE via pointer drag: delta px → mm against the live card size, snapped
 *   onto the editor grid, MAGNETICALLY aligned onto neighbour edges/centres +
 *   card centre (guide lines show while the alignment holds), hard-clamped.
 * - RESIZE via four corner handles (selected box only): opposite edges stay
 *   fixed, grabbed edges follow snapped+clamped per-type minimum sizes.
 *   Resizing shows guides but does not displace magnetically (the fixed edges
 *   must not move).
 * - NUDGE via arrow keys: 1 mm per press, Shift = one grid step (5 mm).
 *
 * Clicking a box selects it, clicking the card background clears selection.
 */
interface BadgeCanvasProps {
    rows: BadgeRowValues[];
    selectedIndex: number | null;
    /** Row indices overlapping another row (soft warning marker, non-blocking). */
    overlapIndices: ReadonlySet<number>;
    onSelect: (index: number | null) => void;
    /** Live move update — receives the snapped/aligned/clamped mm position. */
    onMove: (index: number, x: number, y: number) => void;
    /** Live corner-resize update — receives the full snapped/clamped rectangle. */
    onResize: (index: number, rect: MmRect) => void;
}

/** Bookkeeping of an active pointer drag (px start point + mm origin). */
interface DragState {
    index: number;
    pointerId: number;
    startClientX: number;
    startClientY: number;
    originX: number;
    originY: number;
}

/** Bookkeeping of an active corner resize (px start point + mm origin rect). */
interface ResizeState {
    index: number;
    pointerId: number;
    startClientX: number;
    startClientY: number;
    corner: ResizeCorner;
    origin: MmRect;
}

const finiteOrZero = (value: number): number => (Number.isFinite(value) ? value : 0);

/** Per-type minimum sizes mirrored from the zod schema/server rules. */
function minSizeFor(field: BadgeRowValues['field']): { w: number; h: number } {
    return isBoxEntry(field)
        ? { w: MIN_BOX_W_MM, h: MIN_BOX_H_MM }
        : { w: MIN_TEXT_W_MM, h: MIN_TEXT_H_MM };
}

/** All other rows with fully finite geometry — the alignment reference set. */
function alignmentOthers(rows: BadgeRowValues[], index: number): MmRect[] {
    return rows
        .filter((_, rowIndex) => rowIndex !== index)
        .filter((row) => [row.x, row.y, row.w, row.h].every(Number.isFinite))
        .map((row) => ({ x: row.x, y: row.y, w: row.w, h: row.h }));
}

const RESIZE_CORNERS: readonly ResizeCorner[] = ['nw', 'ne', 'sw', 'se'];

/** Static per-corner placement classes (Tailwind JIT policy: no concatenation). */
const RESIZE_HANDLE_CLASS: Record<ResizeCorner, string> = {
    nw: 'top-0.5 left-0.5 cursor-nwse-resize',
    ne: 'top-0.5 right-0.5 cursor-nesw-resize',
    sw: 'bottom-0.5 left-0.5 cursor-nesw-resize',
    se: 'bottom-0.5 right-0.5 cursor-nwse-resize',
};

/** Deterministic sample content per data field (rough print approximation). */
const SAMPLE_TEXT: Record<BadgeRowValues['field'], string> = {
    name: 'Max Mustermann',
    category: 'Presse',
    event: 'FC Beispiel',
    date: '14.08.2026',
    status: 'Akkreditiert',
    team: 'SV Beispiel',
    vest_number: '42',
    photo: '',
    qr: '',
    image: '',
};

function SampleContent({ field }: { field: BadgeRowValues['field'] }) {
    switch (field) {
        case 'name':
            return <span className="font-semibold text-neutral-900">{SAMPLE_TEXT.name}</span>;
        case 'category':
            return <span className="text-neutral-900">{SAMPLE_TEXT.category}</span>;
        case 'event':
            return <span className="text-neutral-900">{SAMPLE_TEXT.event}</span>;
        case 'date':
            return <span className="text-neutral-900">{SAMPLE_TEXT.date}</span>;
        case 'status':
            return <span className="text-neutral-900">{SAMPLE_TEXT.status}</span>;
        case 'team':
            return <span className="text-neutral-900">{SAMPLE_TEXT.team}</span>;
        case 'vest_number':
            return <span className="text-neutral-900">{SAMPLE_TEXT.vest_number}</span>;
        case 'photo':
            return (
                <span className="flex h-full w-full items-center justify-center rounded bg-neutral-200">
                    <span className="iconify mdi--account text-3xl text-neutral-500"></span>
                </span>
            );
        case 'qr':
            return (
                <span className="flex h-full w-full items-center justify-center rounded bg-neutral-900">
                    <span className="iconify mdi--qrcode text-neutral-100"></span>
                </span>
            );
        case 'image':
            return (
                <span className="flex h-full w-full items-center justify-center rounded bg-neutral-100">
                    <span className="iconify mdi--image-outline text-2xl text-neutral-500"></span>
                </span>
            );
    }
}

function CanvasBox({
    row,
    index,
    selected,
    overlaps,
    overlapWarning,
    label,
    onSelect,
    onDragStart,
    onDragMove,
    onDragEnd,
    onResizeStart,
    onResizeMove,
    onResizeEnd,
    onKeyDown,
}: {
    row: BadgeRowValues;
    index: number;
    selected: boolean;
    overlaps: boolean;
    overlapWarning: string;
    label: string;
    onSelect: (index: number | null) => void;
    onDragStart: (event: ReactPointerEvent<HTMLButtonElement>, index: number) => void;
    onDragMove: (event: ReactPointerEvent<HTMLButtonElement>, index: number) => void;
    onDragEnd: (event: ReactPointerEvent<HTMLButtonElement>, index: number) => void;
    onResizeStart: (event: ReactPointerEvent<HTMLSpanElement>, index: number, corner: ResizeCorner) => void;
    onResizeMove: (event: ReactPointerEvent<HTMLSpanElement>, index: number) => void;
    onResizeEnd: (event: ReactPointerEvent<HTMLSpanElement>, index: number) => void;
    onKeyDown: (event: ReactKeyboardEvent<HTMLButtonElement>, index: number) => void;
}) {
    // Tailwind-Only-Policy exception: position/size/font values are runtime
    // numbers typed by the author (mm / pt) projected onto the card — Tailwind
    // JIT cannot emit classes for arbitrary dynamic values, so they must be
    // inline styles (same exception as the previous read-only preview).
    const x = finiteOrZero(row.x);
    const y = finiteOrZero(row.y);
    const h = finiteOrZero(row.h);
    const size = Number.isFinite(row.size) ? row.size : 12;

    // FE4-F1 wrap-aware auto-fit: the sample text must fit its box whether it
    // renders as a single line or wraps in a narrow box. The font is capped
    // by BOTH a one-line width cap (cqw) and a two-line-safe height cap (cqh)
    // resolving against THIS box (`badge-canvas-box` = container-type: size);
    // `min()` takes whichever binds, so large boxes keep the authored size
    // while narrow/short boxes shrink instead of clipping vertically.
    // The font-size lives on the INNER span on purpose: a query container
    // cannot resolve container units against itself, so declaring them on the
    // button would silently fall back to the card container.
    const style = {
        left: `${(x / A6_WIDTH_MM) * 100}%`,
        top: `${(y / A6_HEIGHT_MM) * 100}%`,
        width: `${(finiteOrZero(row.w) / A6_WIDTH_MM) * 100}%`,
        height: `${(h / A6_HEIGHT_MM) * 100}%`,
    };
    const typographyStyle = {
        fontSize: badgeCanvasFontSizeCss(row.field, SAMPLE_TEXT[row.field].length, size),
        textAlign: row.align,
    };

    // Static class branches only (Tailwind JIT policy): selected wins over the
    // overlap warning; an unselected overlapping box floats above its peers so
    // the error ring stays visible.
    const stateClass = selected
        ? 'z-10 border-solid border-primary bg-primary/10 ring-2 ring-primary'
        : overlaps
          ? 'z-20 border-solid border-error bg-error/10 ring-2 ring-error'
          : 'border-base-content/30 bg-base-100/60 hover:border-primary hover:bg-primary/5';

    return (
        <button
            type="button"
            aria-label={label}
            aria-pressed={selected}
            title={overlaps ? overlapWarning : undefined}
            className={`badge-canvas-box absolute flex cursor-grab touch-none select-none items-center overflow-hidden border border-dashed p-0.5 transition-colors ${stateClass}`}
            style={style}
            onClick={(event) => {
                event.stopPropagation();
                onSelect(index);
            }}
            onPointerDown={(event) => onDragStart(event, index)}
            onPointerMove={(event) => onDragMove(event, index)}
            onPointerUp={(event) => onDragEnd(event, index)}
            onPointerCancel={(event) => onDragEnd(event, index)}
            onKeyDown={(event) => onKeyDown(event, index)}
        >
            <span
                className={`block w-full leading-tight ${isBoxEntry(row.field) ? 'h-full' : ''}`}
                style={typographyStyle}
            >
                <SampleContent field={row.field} />
            </span>
            {selected
                ? RESIZE_CORNERS.map((corner) => (
                      // Decorative mouse-only affordances: resizing stays
                      // keyboard-accessible through the W/H panel inputs, so
                      // the handles are intentionally aria-hidden spans (no
                      // interactive element may nest inside a <button>).
                      <span
                          key={corner}
                          aria-hidden="true"
                          data-resize-handle={corner}
                          className={`absolute z-20 h-2 w-2 rounded-full border border-base-100 bg-primary touch-none ${RESIZE_HANDLE_CLASS[corner]}`}
                          onPointerDown={(event) => onResizeStart(event, index, corner)}
                          onPointerMove={(event) => onResizeMove(event, index)}
                          onPointerUp={(event) => onResizeEnd(event, index)}
                          onPointerCancel={(event) => onResizeEnd(event, index)}
                      ></span>
                  ))
                : null}
        </button>
    );
}

export function BadgeCanvas({ rows, selectedIndex, overlapIndices, onSelect, onMove, onResize }: BadgeCanvasProps) {
    const { i18n } = useLingui();
    const cardRef = useRef<HTMLDivElement | null>(null);
    const dragRef = useRef<DragState | null>(null);
    const resizeRef = useRef<ResizeState | null>(null);
    const [guides, setGuides] = useState<AlignmentGuides>(NO_ALIGNMENT_GUIDES);

    const handleDragStart = (event: ReactPointerEvent<HTMLButtonElement>, index: number) => {
        onSelect(index);
        const row = rows[index];
        if (!event.isPrimary || !row || !cardRef.current) return;
        event.currentTarget.setPointerCapture(event.pointerId);
        dragRef.current = {
            index,
            pointerId: event.pointerId,
            startClientX: event.clientX,
            startClientY: event.clientY,
            originX: finiteOrZero(row.x),
            originY: finiteOrZero(row.y),
        };
    };

    const handleDragMove = (event: ReactPointerEvent<HTMLButtonElement>, index: number) => {
        const drag = dragRef.current;
        const card = cardRef.current;
        if (!drag || !card || drag.index !== index || drag.pointerId !== event.pointerId) return;

        const rect = card.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        const row = rows[index];
        const size = { w: finiteOrZero(row.w), h: finiteOrZero(row.h) };
        // px → mm against the LIVE rendered card size, grid-snapped …
        const moved = computeDragPosition(
            { x: drag.originX, y: drag.originY },
            {
                x: ((event.clientX - drag.startClientX) / rect.width) * A6_WIDTH_MM,
                y: ((event.clientY - drag.startClientY) / rect.height) * A6_HEIGHT_MM,
            },
            size,
        );
        // … then magnetically aligned (FE4) onto neighbour edges/centres/card
        // centre within the threshold, bounds winning again after correction.
        const others = alignmentOthers(rows, index);
        const snap = computeAlignmentSnap({ x: moved.x, y: moved.y, w: size.w, h: size.h }, others);
        const finalRect = clampToBounds({ x: moved.x + snap.offsetX, y: moved.y + snap.offsetY, w: size.w, h: size.h });
        onMove(index, finalRect.x, finalRect.y);
        setGuides(findAlignedGuides(finalRect, others));
    };

    const handleDragEnd = (_event: ReactPointerEvent<HTMLButtonElement>, index: number) => {
        if (dragRef.current?.index !== index) return;
        dragRef.current = null;
        setGuides(NO_ALIGNMENT_GUIDES);
        // Pointer capture releases implicitly on pointerup/cancel; the last
        // written position stays — it is already aligned, snapped and clamped.
    };

    const handleResizeStart = (event: ReactPointerEvent<HTMLSpanElement>, index: number, corner: ResizeCorner) => {
        onSelect(index);
        const row = rows[index];
        if (!event.isPrimary || !row || !cardRef.current) return;
        // Keep the underlying box button from starting a MOVE drag.
        event.stopPropagation();
        event.currentTarget.setPointerCapture(event.pointerId);
        resizeRef.current = {
            index,
            pointerId: event.pointerId,
            startClientX: event.clientX,
            startClientY: event.clientY,
            corner,
            origin: {
                x: finiteOrZero(row.x),
                y: finiteOrZero(row.y),
                w: finiteOrZero(row.w),
                h: finiteOrZero(row.h),
            },
        };
    };

    const handleResizeMove = (event: ReactPointerEvent<HTMLSpanElement>, index: number) => {
        const active = resizeRef.current;
        const card = cardRef.current;
        if (!active || !card || active.index !== index || active.pointerId !== event.pointerId) return;

        const rect = card.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        const mins = minSizeFor(rows[index].field);
        const resized = computeDragResize(
            active.origin,
            active.corner,
            {
                x: ((event.clientX - active.startClientX) / rect.width) * A6_WIDTH_MM,
                y: ((event.clientY - active.startClientY) / rect.height) * A6_HEIGHT_MM,
            },
            mins.w,
            mins.h,
        );
        // Deliberate asymmetry to moving: resize shows guides but never
        // displaces magnetically — the edges opposite the corner are fixed.
        onResize(index, resized);
        setGuides(findAlignedGuides(resized, alignmentOthers(rows, index)));
    };

    const handleResizeEnd = (event: ReactPointerEvent<HTMLSpanElement>, index: number) => {
        if (resizeRef.current?.index !== index || resizeRef.current.pointerId !== event.pointerId) return;
        resizeRef.current = null;
        setGuides(NO_ALIGNMENT_GUIDES);
    };

    const handleNudge = (direction: NudgeDirection, index: number, coarse: boolean, row: BadgeRowValues) => {
        const next = computeNudgePosition(
            { x: finiteOrZero(row.x), y: finiteOrZero(row.y) },
            direction,
            coarse ? CANVAS_GRID_STEP_MM : 1,
            { w: finiteOrZero(row.w), h: finiteOrZero(row.h) },
        );
        onMove(index, next.x, next.y);
    };

    const handleKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>, index: number) => {
        const direction = nudgeDirectionFromKey(event.key);
        if (!direction) return;
        event.preventDefault();
        event.stopPropagation();
        if (selectedIndex !== index) {
            onSelect(index);
        }
        handleNudge(direction, index, event.shiftKey, rows[index]);
    };

    const overlapWarning = i18n._(t`Felder überschneiden sich.`);

    return (
        <div
            className="aspect-a6 w-full cursor-default rounded bg-white p-1 shadow"
            role="group"
            aria-label={i18n._(t`Ausweis-Vorschau`)}
            onClick={() => onSelect(null)}
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    onSelect(null);
                }
            }}
        >
            <div ref={cardRef} className="badge-canvas-container relative h-full w-full select-none overflow-hidden rounded">
                {/* Editor grid raster (aria-hidden decoration; inline-style
                    exception: raster geometry derives from the mm constants). */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0"
                    style={{
                        backgroundImage:
                            'linear-gradient(to right, rgb(0 0 0 / 0.06) 1px, transparent 1px), linear-gradient(to bottom, rgb(0 0 0 / 0.06) 1px, transparent 1px)',
                        backgroundSize: `${(CANVAS_GRID_STEP_MM / A6_WIDTH_MM) * 100}% ${(CANVAS_GRID_STEP_MM / A6_HEIGHT_MM) * 100}%`,
                    }}
                ></div>
                {/* Alignment guide lines (FE4, decoration shown while an edge/
                    centre is flush with another field or the card centre). */}
                {guides.vertical.map((xMm) => (
                    <div
                        key={`v-${xMm}`}
                        aria-hidden="true"
                        data-badge-guide="vertical"
                        className="pointer-events-none absolute inset-y-0 z-30 w-px bg-primary"
                        style={{ left: `${(xMm / A6_WIDTH_MM) * 100}%` }}
                    ></div>
                ))}
                {guides.horizontal.map((yMm) => (
                    <div
                        key={`h-${yMm}`}
                        aria-hidden="true"
                        data-badge-guide="horizontal"
                        className="pointer-events-none absolute inset-x-0 z-30 h-px bg-primary"
                        style={{ top: `${(yMm / A6_HEIGHT_MM) * 100}%` }}
                    ></div>
                ))}
                {rows.map((row, index) => (
                    // key=index ok: no reordering, static list (insertion order only;
                    // rows are added at the end or removed by filter — never reordered).
                    <CanvasBox
                        key={index}
                        row={row}
                        index={index}
                        selected={selectedIndex === index}
                        overlaps={overlapIndices.has(index)}
                        overlapWarning={overlapWarning}
                        label={`${i18n._(t`Feld`)} ${badgeFieldLabel(row.field, i18n)}`}
                        onSelect={onSelect}
                        onDragStart={handleDragStart}
                        onDragMove={handleDragMove}
                        onDragEnd={handleDragEnd}
                        onResizeStart={handleResizeStart}
                        onResizeMove={handleResizeMove}
                        onResizeEnd={handleResizeEnd}
                        onKeyDown={handleKeyDown}
                    />
                ))}
            </div>
        </div>
    );
}
