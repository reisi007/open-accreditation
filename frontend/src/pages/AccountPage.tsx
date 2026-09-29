import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import useSWR from 'swr';
import { ApiError, deleteOwnAccount, getAccount, type AccountDeletionResult } from '../api/client';
import { AccountDeleteDialog } from '../components/AccountDeleteDialog';
import {
    pendingApplicationsLabel,
    pendingMediaLabel,
    pendingSubApplicationsLabel,
} from '../logic/accountDeletion';
import { accountDeletedState } from '../logic/accountDeletedNotice';
import { useAuth } from '../logic/useAuth';

/**
 * The self-service account area: identity, the counts a deletion would take,
 * and the deletion itself.
 *
 * ## Why this page exists at all
 *
 * "A user may delete their own account" was decided, and the backend shipped
 * `GET`/`DELETE /api/user/account` for it — but the frontend had NO surface
 * that ever called it. There is no profile page; the only authenticated
 * surfaces are the application lists. The decision is therefore real only
 * once this page exists, and this page is deliberately the smallest honest
 * one: it READS the account and it DELETES it. It is not a profile editor.
 *
 * The accreditation profile (`PUT /api/user/profile` — title, birth date,
 * company, vest number …) is a different feature that also has no UI; folding
 * it in here would have been a second, undecided decision. Hence the name:
 * *Konto* is the account, not the accreditation profile.
 *
 * ## Where the confirmation data comes from
 *
 * From `GET /api/user/account` and nowhere else. The counts cannot be derived
 * from the application list: that list is paginated client-side per surface and
 * the backend's `withCount` is measured inside the deletion transaction, so
 * the dialog names what the server says, not what a client-side tally guesses.
 */
export function AccountPage() {
    const { i18n } = useLingui();
    const navigate = useNavigate();
    const { logout } = useAuth();
    const { data: account, error, isLoading } = useSWR('/api/user/account', () => getAccount());

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const openConfirm = () => {
        setDeleteError(null);
        setConfirmOpen(true);
    };

    const closeConfirm = () => {
        if (busy) {
            return;
        }
        setDeleteError(null);
        setConfirmOpen(false);
    };

    const handleDelete = async () => {
        setDeleteError(null);
        setBusy(true);
        let result: AccountDeletionResult;
        try {
            result = await deleteOwnAccount();
        } catch (err) {
            setDeleteError(
                err instanceof ApiError ? err.message : i18n._(t`Konto konnte nicht gelöscht werden.`),
            );
            setBusy(false);
            return;
        }

        // The account is gone, so the session is over: tear the cache down
        // through the existing `logout()` (which never rejects and whose
        // 401-on-a-dead-account is swallowed) and leave the authenticated
        // area. `navigate` runs after the teardown so no guard can observe a
        // half-dismantled session and bounce the user back to /login.
        await logout();
        navigate('/', { state: accountDeletedState(result), replace: true });
    };

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Mein Konto`)}</h1>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Konto konnte nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {account && !isLoading && !error ? (
                <>
                    <div className="card border border-base-300 bg-base-100">
                        <div className="card-body">
                            <h2 className="card-title">{i18n._(t`Konto`)}</h2>
                            <dl className="flex flex-col gap-2">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`Name`)}</dt>
                                    <dd>{account.name}</dd>
                                </div>
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`E-Mail`)}</dt>
                                    <dd className="break-all">{account.email}</dd>
                                </div>
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`Verband`)}</dt>
                                    <dd>{account.mandant_name ?? i18n._(t`Kein Verband`)}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <div className="card border border-base-300 bg-base-100">
                        <div className="card-body">
                            <h2 className="card-title">{i18n._(t`Meine Anträge`)}</h2>
                            <dl className="flex flex-col gap-2">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`Anträge`)}</dt>
                                    <dd>{pendingApplicationsLabel(account.applications_count, i18n)}</dd>
                                </div>
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`Sub-Anträge`)}</dt>
                                    <dd>{pendingSubApplicationsLabel(account.sub_applications_count, i18n)}</dd>
                                </div>
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="font-medium">{i18n._(t`Dateien`)}</dt>
                                    <dd>{pendingMediaLabel(account.media_count, i18n)}</dd>
                                </div>
                            </dl>
                            <div className="card-actions">
                                <Link to="/meine-akkreditierungen" className="btn btn-outline btn-sm">
                                    {i18n._(t`Zu meinen Akkreditierungen`)}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div className="card border border-error bg-base-100">
                        <div className="card-body">
                            <h2 className="card-title">{i18n._(t`Konto löschen`)}</h2>
                            <p className="text-base-content/80">
                                {i18n._(
                                    t`Dein Konto, deine Anträge, deine Sub-Anträge und deine hochgeladenen Dateien werden unwiderruflich gelöscht.`,
                                )}
                            </p>
                            <div className="card-actions">
                                <button type="button" className="btn btn-error btn-sm" onClick={openConfirm}>
                                    {i18n._(t`Konto löschen`)}
                                </button>
                            </div>
                        </div>
                    </div>
                </>
            ) : null}

            {confirmOpen && account ? (
                <AccountDeleteDialog
                    title={i18n._(t`Konto löschen`)}
                    accountName={account.name}
                    accountEmail={account.email}
                    applicationsCount={account.applications_count}
                    subApplicationsCount={account.sub_applications_count}
                    mediaCount={account.media_count}
                    busy={busy}
                    error={deleteError}
                    onConfirm={() => void handleDelete()}
                    onCancel={closeConfirm}
                />
            ) : null}
        </section>
    );
}
