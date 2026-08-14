import type { BadgeFieldKey } from '../../api/types';
import type { BadgeFieldFormValues } from './badgeTemplateFormUtils';

// The preview card stands for a real A6 sheet (105×148 mm). Field coordinates
// (mm) are projected onto the virtual 85×121 mm card, so `left/top/width/
// height` map linearly to percentages of the card.
const CARD_WIDTH_MM = 85;
const CARD_HEIGHT_MM = 121;

interface BadgePreviewFieldProps {
    row: BadgeFieldFormValues;
}

function BadgePreviewFieldContent({ field }: { field: BadgeFieldKey }) {
    switch (field) {
        case 'name':
            return <span className="font-semibold text-neutral-900">Max Mustermann</span>;
        case 'category':
            return <span className="text-neutral-900">Presse</span>;
        case 'event':
            return <span className="text-neutral-900">FC Beispiel</span>;
        case 'date':
            return <span className="text-neutral-900">2026-08-14</span>;
        case 'status':
            return <span className="text-neutral-900">Akkreditiert</span>;
        case 'photo':
            return (
                <span className="flex h-full w-full items-center justify-center rounded bg-neutral-200">
                    <span className="iconify mdi--account text-3xl text-neutral-500"></span>
                </span>
            );
    }
}

function BadgePreviewField({ row }: BadgePreviewFieldProps) {
    // Tailwind-Only-Policy exception: the position/size/font values are
    // runtime numbers typed by the user (mm / pt). Tailwind JIT cannot emit
    // classes for arbitrary dynamic values, so they must be inline styles.
    const x = Number.isFinite(row.x) ? row.x : 0;
    const y = Number.isFinite(row.y) ? row.y : 0;
    const w = Number.isFinite(row.w) ? row.w : 0;
    const h = Number.isFinite(row.h) ? row.h : 0;
    const size = Number.isFinite(row.size) ? row.size : 12;

    const style = {
        left: `${(x / CARD_WIDTH_MM) * 100}%`,
        top: `${(y / CARD_HEIGHT_MM) * 100}%`,
        width: `${(w / CARD_WIDTH_MM) * 100}%`,
        height: `${(h / CARD_HEIGHT_MM) * 100}%`,
        fontSize: `${size}px`,
        textAlign: row.align,
    };

    return (
        <div className="absolute flex items-center overflow-hidden" style={style}>
            <div className="w-full">
                <BadgePreviewFieldContent field={row.field} />
            </div>
        </div>
    );
}

interface BadgePreviewProps {
    fields: BadgeFieldFormValues[];
}

export function BadgePreview({ fields }: BadgePreviewProps) {
    return (
        <div className="aspect-a6 w-full max-w-xs rounded bg-white p-1 shadow" aria-hidden="true">
            <div className="relative h-full w-full overflow-hidden rounded">
                {fields.map((row, index) => (
                    <BadgePreviewField key={index} row={row} />
                ))}
            </div>
        </div>
    );
}
