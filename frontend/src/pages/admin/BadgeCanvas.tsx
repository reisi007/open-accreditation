import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import {
    A6_HEIGHT_MM,
    A6_WIDTH_MM,
    badgeFieldLabel,
    isBoxEntry,
    type BadgeRowValues,
} from './badgeTemplateFormUtils';

/**
 * Interactive A6 canvas of the badge template editor (FE2 basis UI — no drag
 * & drop yet, FE3). Every layout row renders as a clickable box positioned in
 * percent of the REAL A6 sheet (105 × 148 mm), so preview and print share one
 * coordinate system (WYSIWYG in mm). Clicking a box selects it for the
 * properties panel; clicking the card background clears the selection.
 */
interface BadgeCanvasProps {
    rows: BadgeRowValues[];
    selectedIndex: number | null;
    onSelect: (index: number | null) => void;
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
    label,
    onSelect,
}: {
    row: BadgeRowValues;
    index: number;
    selected: boolean;
    label: string;
    onSelect: (index: number | null) => void;
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

    return (
        <button
            type="button"
            aria-label={label}
            aria-pressed={selected}
            className={`absolute flex items-center overflow-hidden border border-dashed p-0.5 transition-colors ${
                selected
                    ? 'z-10 border-solid border-primary bg-primary/10 ring-2 ring-primary'
                    : 'border-base-content/30 bg-base-100/60 hover:border-primary hover:bg-primary/5'
            }`}
            style={style}
            onClick={(event) => {
                event.stopPropagation();
                onSelect(index);
            }}
        >
            <span className={`block w-full ${isBoxEntry(row.field) ? 'h-full' : ''}`}>
                <SampleContent field={row.field} />
            </span>
        </button>
    );
}

export function BadgeCanvas({ rows, selectedIndex, onSelect }: BadgeCanvasProps) {
    const { i18n } = useLingui();

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
            <div className="relative h-full w-full overflow-hidden rounded">
                {rows.map((row, index) => (
                    <CanvasBox
                        key={index}
                        row={row}
                        index={index}
                        selected={selectedIndex === index}
                        label={`${i18n._(t`Feld`)} ${badgeFieldLabel(row.field, i18n)}`}
                        onSelect={onSelect}
                    />
                ))}
            </div>
        </div>
    );
}
