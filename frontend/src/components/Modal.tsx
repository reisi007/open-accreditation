import { useEffect, useLayoutEffect, useRef, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react';

/**
 * Elements that can receive focus inside a dialog. Used to move focus in on
 * open (the trigger stays focused otherwise, so a keyboard user would keep
 * tabbing through the page behind the overlay).
 */
const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

interface ModalProps {
    children: ReactNode;
    /** Extra classes for the inner `.modal-box` (e.g. `max-w-5xl`). */
    boxClassName?: string;
    /**
     * `false` renders the dialog without daisyUI's dark overlay. Used for the
     * lower dialog of a stacked pair, whose overlay would otherwise dim the
     * page a second time on top of the topmost dialog's.
     */
    dimmed?: boolean;
    onClose: () => void;
}

/**
 * Accessible daisyUI modal built on real `<dialog>` semantics.
 *
 * Why not the previous `<dialog className="modal modal-open">` without the
 * `open` attribute: in the installed daisyUI 5.7.38 CSS `.modal.modal-open` is
 * a **pure CSS state** (`pointer-events:auto; visibility:visible; opacity:1;
 * background-color:oklch(0 0 0/.4)`) and `.modal::backdrop{display:none}`. So
 * there was no top layer, no native `cancel`/Escape, no focus containment and
 * no background inertness — a keyboard user stayed on the trigger and could
 * tab through the whole page. daisyUI styles `.modal[open]` identically, so
 * opting into the `open` state keeps the opt-in/opt-out look.
 *
 * `showModal()` is used when the environment provides it (all shipping
 * browsers): that adds the top layer, native Escape via `cancel` and the UA's
 * "everything else is inert" behaviour, which is also what fixes the stacked
 * modals in `admin/AccreditationsPage` (the lower dialog becomes inert while
 * the form dialog is on top). Environments without it (jsdom) fall back to the
 * `open` content attribute, where the explicit keydown/Escape handler below and
 * the manual focus management keep the contract intact.
 *
 * NOTE: this component has been verified in jsdom (Escape closes, focus moves
 * in and returns to the trigger, `aria-modal`/`aria-expanded` semantics). The
 * native top-layer behaviour itself is browser-provided and still needs a real
 * screen-reader pass.
 */
export function Modal({ children, boxClassName, dimmed = true, onClose }: ModalProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const openerRef = useRef<HTMLElement | null>(null);

    useLayoutEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }

        // Remember the opener so focus can be handed back on close instead of
        // dropping the keyboard user at the top of the document. Captured only
        // on the FIRST run: React StrictMode double-invokes layout effects, and
        // by the second run `document.activeElement` is the field inside this
        // dialog — re-capturing would restore focus to a detached node.
        if (openerRef.current === null) {
            openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }

        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) {
                dialog.showModal();
            }
        } else if (!dialog.open) {
            dialog.setAttribute('open', '');
        }

        if (dialog.open) {
            const firstFocusable = dialog.querySelector<HTMLElement>(FOCUSABLE_SELECTOR);
            (firstFocusable ?? dialog).focus();
        }
    }, []);

    // Focus restore runs in a PASSIVE effect on purpose: React runs the layout
    // cleanups of a deleted tree before it detaches the DOM nodes, and while a
    // `showModal()` dialog is still in the top layer everything outside it is
    // inert — `opener.focus()` would be swallowed and the UA would then drop
    // focus to `<body>` when the node is finally removed. The passive phase
    // runs after the removal, so the focus actually lands on the opener.
    useEffect(() => {
        return () => {
            const opener = openerRef.current;
            if (opener !== null && opener.isConnected) {
                opener.focus();
            }
        };
    }, []);

    // Escape. `preventDefault` suppresses the UA's own close request so the
    // close always runs through the React state (one code path, identical for
    // the `showModal()` and the `open`-attribute path).
    const handleKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        if (event.key !== 'Escape') {
            return;
        }

        event.preventDefault();
        onClose();
    };

    // The backdrop is a mouse-only convenience: every dialog also ships a
    // visible cancel/close button and the Escape handler above, so it must not
    // add a focusable (and invisible) control to the tab order.
    const handleBackdropClick = (event: MouseEvent<HTMLDivElement>) => {
        if (event.target === event.currentTarget) {
            onClose();
        }
    };

    const dialogClass = dimmed ? 'modal' : 'modal bg-transparent!';

    return (
        <dialog ref={dialogRef} className={dialogClass} aria-modal="true" onKeyDown={handleKeyDown}>
            <div className={boxClassName === undefined ? 'modal-box' : `modal-box ${boxClassName}`}>{children}</div>
            <div className="modal-backdrop" aria-hidden="true" onClick={handleBackdropClick}></div>
        </dialog>
    );
}
