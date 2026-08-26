import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useRef } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    badgeFieldLabel,
    CANVAS_GRID_STEP_MM,
    computeDragPosition,
    isBoxEntry,
    type BadgeRowValues,
} from './badgeTemplateFormUtils';

/**
 * Interactive A6 canvas of the badge template editor (FE3, features/badge-
 * template-editor.md): every layout row renders as a box positioned in
 * percent of the REAL A6 sheet (105 × 148 mm), so preview and print share one
 * coordinate system (WYSIWYG in mm). Boxes are draggable via POINTER events
 * (not HTML5 DnD): the drag delta is converted px → mm against the live card
 * size, snapped onto the editor grid and hard-clamped into the A6 bounds;
 * each move writes x/y back into react-hook-form (single source of truth).
 * Clicking a box selects it, clicking the card background clears selection.
 */
interface BadgeCanvasProps {
    rows: BadgeRowValues[];
    selectedIndex: number | null;
    /** Row indices overlapping another row (soft warning marker, non-blocking). */
    overlapIndices: ReadonlySet<number>;
    onSelect: (index: number | null) => void;
    /** Live drag update — receives the snapped/clamped mm position. */
    onMove: (index: number, x: number, y: number) => void;
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

/** Deterministic sample content per data field (rough print approximation). */
function SampleContent({ field }: { field: BadgeRowValues['field'] }) {
    switch (field) {
        case 'name':
            return <span className="font-semibold text-neutral-900">Max Mustermann</span>;
        case 'category':
            return <span className="text-neutral-900">Presse</span>;
        case 'event':
            return <span className="text-neutral-900">FC Beispiel</span>;
        case 'date':
            return <span className="text-neutral-900">14.08.2026</span>;
        case 'status':
            return <span className="text-neutral-900">Akkreditiert</span>;
        case 'team':
            return <span className="text-neutral-900">SV Beispiel</span>;
        case 'vest_number':
            return <span className="text-neutral-900">42</span>;
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
}) {
    // Tailwind-Only-Policy exception: position/size/font values are runtime
    // numbers typed by the author (mm / pt) projected onto the card — Tailwind
    // JIT cannot emit classes for arbitrary dynamic values, so they must be
    // inline styles (same exception as the previous read-only preview).
    const x = Number.isFinite(row.x) ? row.x : 0;
    const y = Number.isFinite(row.y) ? row.y : 0;
    const w = Number.isFinite(row.w) ? row.w : 0;
    const h = Number.isFinite(row.h) ? row.h : 0;
    const size = Number.isFinite(row.size) ? row.size : 12;

    const style = {
        left: `${(x / A6_WIDTH_MM) * 100}%`,
        top: `${(y / A6_HEIGHT_MM) * 100}%`,
        width: `${(w / A6_WIDTH_MM) * 100}%`,
        height: `${(h / A6_HEIGHT_MM) * 100}%`,
        fontSize: `${size}px`,
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
            className={`absolute flex cursor-grab touch-none select-none items-center overflow-hidden border border-dashed p-0.5 transition-colors ${stateClass}`}
            style={style}
            onClick={(event) => {
                event.stopPropagation();
                onSelect(index);
            }}
            onPointerDown={(event) => onDragStart(event, index)}
            onPointerMove={(event) => onDragMove(event, index)}
            onPointerUp={(event) => onDragEnd(event, index)}
            onPointerCancel={(event) => onDragEnd(event, index)}
        >
            <span className={`block w-full ${isBoxEntry(row.field) ? 'h-full' : ''}`}>
                <SampleContent field={row.field} />
            </span>
        </button>
    );
}

export function BadgeCanvas({ rows, selectedIndex, overlapIndices, onSelect, onMove }: BadgeCanvasProps) {
    const { i18n } = useLingui();
    const cardRef = useRef<HTMLDivElement | null>(null);
    const dragRef = useRef<DragState | null>(null);

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
            originX: Number.isFinite(row.x) ? row.x : 0,
            originY: Number.isFinite(row.y) ? row.y : 0,
        };
    };

    const handleDragMove = (event: ReactPointerEvent<HTMLButtonElement>, index: number) => {
        const drag = dragRef.current;
        const card = cardRef.current;
        if (!drag || !card || drag.index !== index || drag.pointerId !== event.pointerId) return;

        const rect = card.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        // px → mm against the LIVE rendered card size (the canvas scales with
        // its container), then snap onto the grid and clamp into the A6 bounds.
        const row = rows[index];
        const next = computeDragPosition(
            { x: drag.originX, y: drag.originY },
            {
                x: ((event.clientX - drag.startClientX) / rect.width) * A6_WIDTH_MM,
                y: ((event.clientY - drag.startClientY) / rect.height) * A6_HEIGHT_MM,
            },
            { w: Number.isFinite(row.w) ? row.w : 0, h: Number.isFinite(row.h) ? row.h : 0 },
        );
        onMove(index, next.x, next.y);
    };

    const handleDragEnd = (_event: ReactPointerEvent<HTMLButtonElement>, index: number) => {
        if (dragRef.current?.index !== index) return;
        dragRef.current = null;
        // Pointer capture releases implicitly on pointerup/cancel; the last
        // written position stays — it is already grid-snapped and clamped.
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
            <div ref={cardRef} className="relative h-full w-full select-none overflow-hidden rounded">
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
                {rows.map((row, index) => (
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
                    />
                ))}
            </div>
        </div>
    );
}
