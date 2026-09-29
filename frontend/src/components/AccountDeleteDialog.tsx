import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Modal } from './Modal';
import {
    pendingApplicationsLabel,
    pendingMediaLabel,
    pendingSubApplicationsLabel,
} from '../logic/accountDeletion';

/**
 * The mandatory confirmation for an account termination (DSGVO), shared by
 * both surfaces that can trigger one — the own-account area and the admin user
 * list.
 *
 * ## What the dialog MUST show, and why it is one component
 *
 * The decision this dialog confirms is irreversible (hard delete, no
 * anonymisation), and it was decided that the confirmation has to NAME the
 * account and the NUMBER OF APPLICATIONS. Two independently written dialogs
 * would satisfy that on the day they were written and drift afterwards, so
 * the rule has exactly one implementation here and both callers pass their
 * numbers into it. The copy is the requirement; the layout is not.
 *
 * `applicationsCount` is a NUMBER, not a sentence: a caller that has no
 * reliable count must not render a confirmation that quietly omits one.
 */
export interface AccountDeleteDialogProps {
    /** Heading of the dialog. */
    title: string;
    /** Account display name — the first thing the user has to recognise. */
    accountName: string;
    accountEmail: string;
    applicationsCount: number;
    subApplicationsCount: number;
    /**
     * Uploaded files that go with the account. `undefined` when the caller has
     * no count (the admin list does not carry one) — the row is then omitted
     * rather than rendered as a made-up `0`.
     */
    mediaCount?: number;
    busy: boolean;
    error: string | null;
    onConfirm: () => void;
    onCancel: () => void;
}

export function AccountDeleteDialog({
    title,
    accountName,
    accountEmail,
    applicationsCount,
    subApplicationsCount,
    mediaCount,
    busy,
    error,
    onConfirm,
    onCancel,
}: AccountDeleteDialogProps) {
    const { i18n } = useLingui();

    return (
        <Modal onClose={onCancel}>
            <h3 className="text-lg font-bold">{title}</h3>

            {error ? (
                <div role="alert" className="alert alert-error mt-4">
                    <span>{error}</span>
                </div>
            ) : null}

            <p className="mt-4 text-base-content/80">
                {i18n._(t`Die Löschung ist endgültig und kann nicht rückgängig gemacht werden.`)}
            </p>

            <dl className="mt-4 flex flex-col gap-2 rounded-box border border-base-300 p-4">
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <dt className="font-medium">{i18n._(t`Konto`)}</dt>
                    <dd className="break-all">
                        {accountName} ({accountEmail})
                    </dd>
                </div>
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <dt className="font-medium">{i18n._(t`Anträge`)}</dt>
                    <dd data-testid="confirm-applications">{pendingApplicationsLabel(applicationsCount, i18n)}</dd>
                </div>
                <div className="flex flex-wrap items-baseline gap-x-2">
                    <dt className="font-medium">{i18n._(t`Sub-Anträge`)}</dt>
                    <dd>{pendingSubApplicationsLabel(subApplicationsCount, i18n)}</dd>
                </div>
                {mediaCount === undefined ? null : (
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <dt className="font-medium">{i18n._(t`Dateien`)}</dt>
                        <dd>{pendingMediaLabel(mediaCount, i18n)}</dd>
                    </div>
                )}
            </dl>

            <div className="modal-action">
                <button type="button" className="btn btn-ghost" onClick={onCancel} disabled={busy}>
                    {i18n._(t`Abbrechen`)}
                </button>
                <button type="button" className="btn btn-error" onClick={onConfirm} disabled={busy}>
                    {busy ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {i18n._(t`Endgültig löschen`)}
                </button>
            </div>
        </Modal>
    );
}
