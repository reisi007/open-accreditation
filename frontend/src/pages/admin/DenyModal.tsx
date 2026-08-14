import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type FormEvent } from 'react';

interface DenyModalProps {
    title: string;
    error: string | null;
    isSubmitting: boolean;
    onConfirm: (reason: string) => Promise<void>;
    onCancel: () => void;
}

export function DenyModal({ title, error, isSubmitting, onConfirm, onCancel }: DenyModalProps) {
    const { i18n } = useLingui();
    const [reason, setReason] = useState('');

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        void onConfirm(reason.trim());
    };

    return (
        <dialog className="modal modal-open">
            <div className="modal-box">
                <h3 className="text-lg font-bold">{title}</h3>
                <form className="mt-4 flex flex-col gap-4" onSubmit={handleSubmit}>
                    {error ? (
                        <div role="alert" className="alert alert-error">
                            <span>{error}</span>
                        </div>
                    ) : null}
                    <div className="form-control">
                        <label className="label" htmlFor="deny-reason">
                            <span className="label-text">{i18n._(t`Begründung`)}</span>
                        </label>
                        <textarea
                            id="deny-reason"
                            className="textarea textarea-sm"
                            required
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder={i18n._(t`Grund für die Ablehnung`)}
                        ></textarea>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="submit" className="btn btn-error" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                            {i18n._(t`Ablehnen`)}
                        </button>
                        <button type="button" className="btn" onClick={onCancel}>
                            {i18n._(t`Abbrechen`)}
                        </button>
                    </div>
                </form>
            </div>
            <form method="dialog" className="modal-backdrop">
                <button type="button" onClick={onCancel}>
                    {i18n._(t`Schließen`)}
                </button>
            </form>
        </dialog>
    );
}
