import { msg, t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useState } from 'react';
import useSWR from 'swr';
import { ApiError, listUsers, updateUserRoles } from '../../api/client';
import type { AdminUser, UserRoleAssignment } from '../../api/types';
import { RoleForm } from './RoleForm';
import { buildRolePayload, type RoleFormValues } from './userRoleFormUtils';

const PAGE_SIZE = 20;

/**
 * Wide tables scroll horizontally by design. On mobile there is no native
 * scroll affordance, so a subtle right-edge fade (over the container) plus a
 * one-line hint shows that more columns are reachable by swiping. Desktop
 * keeps the default scrollbar.
 */
function MobileScrollHint() {
    const { i18n } = useLingui();

    return (
        <p className="mt-2 flex items-center gap-1 text-sm text-base-content/60 lg:hidden">
            <span className="iconify mdi--gesture-swipe-horizontal text-lg"></span>
            {i18n._(t`Zum Scrollen wischen`)}
        </p>
    );
}

export function UsersPage() {
    const { i18n } = useLingui();
    const [searchInput, setSearchInput] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [page, setPage] = useState(1);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedSearch(searchInput), 300);
        return () => window.clearTimeout(timer);
    }, [searchInput]);

    const usersKey = debouncedSearch === '' ? '/api/admin/users' : ['/api/admin/users', debouncedSearch];
    const { data: users, error, isLoading, mutate } = useSWR<AdminUser[]>(usersKey, () =>
        listUsers(debouncedSearch === '' ? undefined : { search: debouncedSearch }),
    );

    const [editUser, setEditUser] = useState<AdminUser | null>(null);
    const [formError, setFormError] = useState<string | null>(null);

    const openEdit = (user: AdminUser) => {
        setEditUser(user);
        setFormError(null);
    };

    const closeForm = () => {
        setEditUser(null);
        setFormError(null);
    };

    const handleSave = async (values: RoleFormValues) => {
        if (!editUser) return;
        setFormError(null);
        try {
            await updateUserRoles(editUser.id, buildRolePayload(values));
            await mutate();
            closeForm();
        } catch (err) {
            setFormError(err instanceof ApiError ? err.message : i18n._(t`Rollen konnten nicht gespeichert werden.`));
        }
    };

    const totalCount = users?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    const pagedUsers = (users ?? []).slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);
    const hasSearch = debouncedSearch !== '';

    const roleBadges = (assignments: UserRoleAssignment[]) =>
        assignments.map((assignment) => (
            <span
                key={`${assignment.role.slug}-${assignment.team_id ?? ''}`}
                className="badge badge-outline badge-sm"
            >
                {assignment.role.name}
                {assignment.team ? ` · ${assignment.team.name}` : ''}
            </span>
        ));

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Benutzer`)}</h1>
                <div className="form-control">
                    <label className="label" htmlFor="users-search">
                        <span className="label-text">{i18n._(t`Benutzer suchen`)}</span>
                    </label>
                    <input
                        id="users-search"
                        type="search"
                        className="input max-w-xs"
                        placeholder={i18n._(t`E-Mail oder Name`)}
                        value={searchInput}
                        onChange={(event) => {
                            setSearchInput(event.target.value);
                            setPage(1);
                        }}
                    />
                </div>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Benutzer konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {users && !isLoading && !error ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p className="text-sm text-base-content/70">
                            {i18n._({
                                ...msg`{totalCount, plural, one {# Benutzer} other {# Benutzer}}`,
                                values: { totalCount },
                            })}
                        </p>
                        {pageCount > 1 ? (
                            <div className="join" role="group" aria-label={i18n._(t`Seitennavigation`)}>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage <= 1}
                                    onClick={() => setPage((previous) => Math.max(1, previous - 1))}
                                >
                                    {i18n._(t`Zurück`)}
                                </button>
                                <span className="join-item btn btn-sm btn-disabled" aria-live="polite">
                                    {i18n._(t`Seite ${currentPage} von ${pageCount}`)}
                                </span>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage >= pageCount}
                                    onClick={() => setPage((previous) => Math.min(pageCount, previous + 1))}
                                >
                                    {i18n._(t`Weiter`)}
                                </button>
                            </div>
                        ) : null}
                    </div>
                    <div className="flex flex-col">
                        <div className="relative">
                            <div className="overflow-x-auto">
                                <table className="table">
                                        <thead>
                                            <tr>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Name`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`E-Mail`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Rollen`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100 min-w-40">{i18n._(t`Aktionen`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedUsers.map((user) => (
                                                <tr key={user.id}>
                                                    <td className="max-w-48">
                                                        <span className="block truncate font-medium" title={user.name}>
                                                            {user.name}
                                                        </span>
                                                    </td>
                                                    <td className="max-w-72">
                                                        <span className="block truncate" title={user.email}>
                                                            {user.email}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div className="flex flex-wrap gap-1">{roleBadges(user.roles)}</div>
                                                    </td>
                                                    <td className="whitespace-nowrap">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline"
                                                            onClick={() => openEdit(user)}
                                                        >
                                                            {i18n._(t`Rollen bearbeiten`)}
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                            </div>
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
                        </div>
                        <MobileScrollHint />
                    </div>
                </div>
            ) : null}

            {users && users.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--account-group-outline text-6xl text-base-content/40"></span>
                        {hasSearch ? (
                            <>
                                <h2 className="card-title">{i18n._(t`Keine Benutzer für die Suche.`)}</h2>
                                <p className="text-base-content/70">
                                    {i18n._(t`Passe den Suchbegriff an oder leere ihn, um alle Benutzer zu sehen.`)}
                                </p>
                            </>
                        ) : (
                            <>
                                <h2 className="card-title">{i18n._(t`Noch keine Benutzer vorhanden.`)}</h2>
                                <p className="text-base-content/70">
                                    {i18n._(t`Sobald sich Benutzer registrieren, erscheinen sie hier.`)}
                                </p>
                                <p className="text-base-content/70">
                                    {i18n._(t`Benutzer registrieren sich über das Portal und werden per E-Mail aktiviert.`)}
                                </p>
                            </>
                        )}
                    </div>
                </div>
            ) : null}

            {editUser ? (
                <dialog className="modal modal-open">
                    <div className="modal-box">
                        <h3 className="text-lg font-bold">{i18n._(t`Rollen bearbeiten`)}</h3>
                        <div className="mt-4">
                            <RoleForm
                                user={editUser}
                                submitLabel={i18n._(t`Speichern`)}
                                submitError={formError}
                                onSubmit={handleSave}
                                onCancel={closeForm}
                            />
                        </div>
                    </div>
                    <form method="dialog" className="modal-backdrop">
                        <button type="button" onClick={closeForm}>
                            {i18n._(t`Schließen`)}
                        </button>
                    </form>
                </dialog>
            ) : null}
        </section>
    );
}
