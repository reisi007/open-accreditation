import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type ChangeEvent } from 'react';
import { useForm } from 'react-hook-form';
import type { AdminUser } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import {
    ALL_ROLE_SLUGS,
    createRoleSchema,
    roleFormDefaults,
    TEAM_SCOPED_ROLE_SLUGS,
    type AssignableRoleSlug,
    type RoleFormValues,
} from './userRoleFormUtils';

interface RoleFormProps {
    user: AdminUser;
    submitLabel: string;
    submitError: string | null;
    /**
     * Which roles THIS session may assign (F4). Defaults to all four, which is
     * the `mandant_admin` / `super_admin` case. A `team_admin` passes
     * `TEAM_SCOPED_ROLE_SLUGS`: he may write `team_admin` for his own team and
     * nothing else, so the editor shows exactly that — a checkbox the backend
     * would reject with 403 is not offered in the first place.
     */
    assignableRoles?: readonly AssignableRoleSlug[];
    onSubmit: (values: RoleFormValues) => Promise<void>;
    onCancel: () => void;
}

interface RoleFlags {
    mandant_admin: boolean;
    team_admin: boolean;
    user: boolean;
    verifier: boolean;
}

export function RoleForm({
    user,
    submitLabel,
    submitError,
    assignableRoles,
    onSubmit,
    onCancel,
}: RoleFormProps) {
    const { i18n } = useLingui();
    const { teams } = useAdminTeams();
    const roleSchema = createRoleSchema();
    const visibleRoles: readonly AssignableRoleSlug[] = assignableRoles ?? ALL_ROLE_SLUGS;
    // Compared by CONTENT, not by reference: `roles === TEAM_SCOPED_ROLE_SLUGS`
    // would be false for a caller that passes an equal array, and the hint would
    // silently disappear for a narrowing that is in force.
    const isTeamScoped =
        visibleRoles.length === TEAM_SCOPED_ROLE_SLUGS.length &&
        visibleRoles.every((slug) => TEAM_SCOPED_ROLE_SLUGS.includes(slug));

    const defaults = roleFormDefaults(user, assignableRoles);

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<RoleFormValues>({
        resolver: zodResolver(roleSchema),
        defaultValues: defaults,
    });

    const [roleFlags, setRoleFlags] = useState<RoleFlags>(() => ({
        mandant_admin: defaults.mandant_admin,
        team_admin: defaults.team_admin,
        user: defaults.user,
        verifier: defaults.verifier,
    }));

    const handleRoleChange = (role: keyof RoleFlags) => (event: ChangeEvent<HTMLInputElement>) => {
        setRoleFlags((previous) => ({ ...previous, [role]: event.target.checked }));
    };

    const hasRole = roleFlags.mandant_admin || roleFlags.team_admin || roleFlags.user || roleFlags.verifier;
    const showTeamSelect = roleFlags.team_admin;

    // Labels are read through `i18n._` at render time — the catalogue is not
    // available at module scope (frontend/AGENTS.md: no module-scope `t`), which
    // is why this is a switch and not a module-level label map.
    const labelFor = (slug: AssignableRoleSlug): string => {
        switch (slug) {
            case 'mandant_admin':
                return i18n._(t`Mandant-Admin`);
            case 'team_admin':
                return i18n._(t`Team-Admin`);
            case 'user':
                return i18n._(t`Benutzer`);
            case 'verifier':
                return i18n._(t`Verifizierer`);
        }
    };

    return (
        <form
            className="flex flex-col gap-4"
            noValidate
            onSubmit={handleSubmit(async (values) => {
                await onSubmit(values);
            })}
        >
            {submitError ? (
                <div role="alert" className="alert alert-error">
                    <span>{submitError}</span>
                </div>
            ) : null}

            <p className="text-sm text-base-content/70">
                {i18n._(t`Die Rolle Super Admin wird global über den Seeder vergeben und kann hier nicht zugewiesen werden.`)}
            </p>

            {isTeamScoped ? (
                <p className="text-sm text-base-content/70">
                    {i18n._(
                        t`Als Team-Admin vergibst du Rollen nur innerhalb deines Teams. Konten beenden darfst du nicht.`,
                    )}
                </p>
            ) : null}

            <fieldset className="fieldset">
                <legend className="fieldset-legend">{i18n._(t`Rollen`)}</legend>
                {visibleRoles.map((slug) => (
                    <label key={slug} className="label cursor-pointer justify-start gap-3">
                        <input
                            type="checkbox"
                            className="checkbox checkbox-sm"
                            {...register(slug, { onChange: handleRoleChange(slug) })}
                        />
                        <span className="label-text">{labelFor(slug)}</span>
                    </label>
                ))}
            </fieldset>

            {showTeamSelect ? (
                <div className="form-control">
                    <label className="label" htmlFor="role-team">
                        <span className="label-text">{i18n._(t`Team`)}</span>
                    </label>
                    <select
                        id="role-team"
                        className={`select ${errors.team_id ? 'select-error' : ''}`}
                        {...register('team_id')}
                        required
                    >
                        <option value="">{i18n._(t`Bitte Team auswählen`)}</option>
                        {(teams ?? []).map((team) => (
                            <option key={team.id} value={String(team.id)}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                    {errors.team_id ? <span className="label-text-alt mt-1 text-error">{errors.team_id.message}</span> : null}
                </div>
            ) : null}

            {!hasRole ? (
                <p role="alert" className="text-sm text-warning">
                    {i18n._(t`Mindestens eine Rolle muss ausgewählt bleiben.`)}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={isSubmitting || !hasRole}>
                    {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {submitLabel}
                </button>
                <button type="button" className="btn" onClick={onCancel}>
                    {i18n._(t`Abbrechen`)}
                </button>
            </div>
        </form>
    );
}