import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useEffect, useState } from 'react';
import useSWR from 'swr';
import { ApiError, listUsers, updateUserRoles } from '../../api/client';
import type { AdminUser, UserRoleAssignment } from '../../api/types';
import { RoleForm } from './RoleForm';
import { buildRolePayload, type RoleFormValues } from './userRoleFormUtils';

export function UsersPage() {
    const { i18n } = useLingui();
    const [searchInput, setSearchInput] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');

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
                        className="input input-sm"
                        placeholder={i18n._(t`E-Mail oder Name`)}
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
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
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Name`)}</th>
                                <th>{i18n._(t`E-Mail`)}</th>
                                <th>{i18n._(t`Rollen`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td className="font-medium">{user.name}</td>
                                    <td>{user.email}</td>
                                    <td>
                                        <div className="flex flex-wrap gap-1">{roleBadges(user.roles)}</div>
                                    </td>
                                    <td>
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
            ) : null}

            {users && users.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Keine Benutzer gefunden.`)}</p>
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
