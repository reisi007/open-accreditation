import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    badgeCanvasFontSizeCss,
    badgeFieldLabel,
    CANVAS_GRID_STEP_MM,
    computeNudgePosition,
    isBoxEntry,
    nudgeDirectionFromKey,
    type BadgeRowValues,
    type NudgeDirection,
} from './badgeTemplateFormUtils';

/**
 * A6 canvas of the badge template editor, decision „Raster + konfigurierbare
 * Labels" (2026-09-27, features/badge-template-editor.md): every layout row
 * renders as a box positioned in percent of the REAL A6 sheet (105 × 148 mm),
 * so preview and print share one coordinate system (WYSIWYG in mm). The
 * 5 mm editor raster is drawn as a background overlay; the exact geometry is
 * authored numerically in `BadgePropertiesPanel`.
 *
 * Two interactions live here (both writing straight into react-hook-form — a
 * single source of truth):
 * - SELECTION: clicking a box selects it, clicking the card background or
 *   pressing Escape clears the selection.
 * - NUDGE via arrow keys on the focused box: 1 mm per press, Shift = one grid
 *   step (5 mm), hard-clamped into the A6 bounds. This is the fine positioning
 *   the panel's `step="any"` inputs cannot express relative to the current
 *   value (there, "1 mm to the left" at 27,4 mm means retyping the number).
 *
 * Deliberately NOT here (superseded with the drag interaction): pointer-drag
 * move, corner resize handles and the magnetic alignment guides. The panel
 * stays the canonical writer for absolute geometry.
 */
interface BadgeCanvasProps {
    rows: BadgeRowValues[];
    selectedIndex: number | null;
    /** Row indices overlapping another row (soft warning marker, non-blocking). */
    overlapIndices: ReadonlySet<number>;
    onSelect: (index: number | null) => void;
    /** Live nudge update — receives the clamped mm position of the moved box. */
    onMove: (index: number, x: number, y: number) => void;
}

const finiteOrZero = (value: number): number => (Number.isFinite(value) ? value : 0);

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
    onKeyDown,
}: {
    row: BadgeRowValues;
    index: number;
    selected: boolean;
    overlaps: boolean;
    overlapWarning: string;
    label: string;
    onSelect: (index: number | null) => void;
    onKeyDown: (event: ReactKeyboardEvent<HTMLButtonElement>, index: number) => void;
}) {
    // Tailwind-Only-Policy exception: position/size/font values are runtime
    // numbers authored in mm/pt by the properties panel and projected onto the
    // card — Tailwind JIT cannot emit classes for arbitrary dynamic values, so
    // they must be inline styles. This is the only place the editor turns form
    // values into pixels; the raster below does the same for the grid step.
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
            className={`badge-canvas-box absolute flex cursor-pointer items-center overflow-hidden border border-dashed p-0.5 transition-colors ${stateClass}`}
            style={style}
            onClick={(event) => {
                event.stopPropagation();
                onSelect(index);
            }}
            onKeyDown={(event) => onKeyDown(event, index)}
        >
            <span
                className={`block w-full leading-tight ${isBoxEntry(row.field) ? 'h-full' : ''}`}
                style={typographyStyle}
            >
                <SampleContent field={row.field} />
            </span>
        </button>
    );
}

export function BadgeCanvas({ rows, selectedIndex, overlapIndices, onSelect, onMove }: BadgeCanvasProps) {
    const { i18n } = useLingui();

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
        // The box is a native <button>, so it is focusable without a tabIndex:
        // arrow keys reach it and only there (the card background swallows
        // them). preventDefault stops the page from scrolling underneath.
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
            <div className="badge-canvas-container relative h-full w-full select-none overflow-hidden rounded">
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
                        onKeyDown={handleKeyDown}
                    />
                ))}
            </div>
        </div>
    );
}
