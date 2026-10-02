import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import type { ReactNode } from 'react';

/**
 * Wide tables scroll horizontally by design. On mobile there is no native
 * scroll affordance, so a subtle right-edge fade (over the container) plus a
 * one-line hint shows that more columns are reachable by swiping. Desktop
 * keeps the default scrollbar.
 *
 * ## Why this is a component and not a copy per page
 *
 * It was defined inside `pages/admin/ApprovalsPage.tsx` until the dead-letter
 * page needed the same thing. Two copies of a scroll affordance are two
 * affordances that can drift — and one of them is invisible when it is missing,
 * which is how a page ends up silently cut off on a phone. One definition, used
 * by every wide admin table.
 */
function MobileTableScrollHint() {
    const { i18n } = useLingui();

    return (
        <p className="mt-2 flex items-center gap-1 text-sm text-base-content/60 lg:hidden">
            <span className="iconify mdi--gesture-swipe-horizontal text-lg"></span>
            {i18n._(t`Zum Scrollen wischen`)}
        </p>
    );
}

interface WideTableProps {
    children: ReactNode;
}

/**
 * Local wrapper for horizontally/vertically scrollable tables: renders the
 * `overflow-x-auto` container plus the mobile-only scroll affordance. The
 * right-edge fade overlay must not intercept pointer events.
 */
export function WideTable({ children }: WideTableProps) {
    return (
        <div className="flex flex-col">
            <div className="relative">
                {children}
                <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
            </div>
            <MobileTableScrollHint />
        </div>
    );
}
