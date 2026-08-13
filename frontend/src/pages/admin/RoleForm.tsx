import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type ChangeEvent } from 'react';
import { useForm } from 'react-hook-form';
import type { AdminUser } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { createRoleSchema, roleFormDefaults, type RoleFormValues } from './userRoleFormUtils';

interface RoleFormProps {
    user: AdminUser;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: RoleFormValues) => Promise<void>;
    onCancel: () => void;
}

interface RoleFlags {
    mandant_admin: boolean;
    team_admin: boolean;
    user: boolean;
    verifier: boolean;
}

export function RoleForm({ user, submitLabel, submitError, onSubmit, onCancel }: RoleFormProps) {
    const { i18n } = useLingui();
    const { teams } = useAdminTeams();
    const roleSchema = createRoleSchema();

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<RoleFormValues>({
        resolver: zodResolver(roleSchema),
        defaultValues: roleFormDefaults(user),
    });

    const [roleFlags, setRoleFlags] = useState<RoleFlags>(() => {
        const defaults = roleFormDefaults(user);
        return {
            mandant_admin: defaults.mandant_admin,
            team_admin: defaults.team_admin,
            user: defaults.user,
            verifier: defaults.verifier,
        };
    });

    const handleRoleChange = (role: keyof RoleFlags) => (event: ChangeEvent<HTMLInputElement>) => {
        setRoleFlags((previous) => ({ ...previous, [role]: event.target.checked }));
    };

    const hasRole = roleFlags.mandant_admin || roleFlags.team_admin || roleFlags.user || roleFlags.verifier;
    const showTeamSelect = roleFlags.team_admin;

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

            <fieldset className="fieldset">
                <legend className="fieldset-legend">{i18n._(t`Rollen`)}</legend>
                <label className="label cursor-pointer justify-start gap-3">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        {...register('mandant_admin', { onChange: handleRoleChange('mandant_admin') })}
                    />
                    <span className="label-text">{i18n._(t`Mandant-Admin`)}</span>
                </label>
                <label className="label cursor-pointer justify-start gap-3">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        {...register('team_admin', { onChange: handleRoleChange('team_admin') })}
                    />
                    <span className="label-text">{i18n._(t`Team-Admin`)}</span>
                </label>
                <label className="label cursor-pointer justify-start gap-3">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        {...register('user', { onChange: handleRoleChange('user') })}
                    />
                    <span className="label-text">{i18n._(t`Benutzer`)}</span>
                </label>
                <label className="label cursor-pointer justify-start gap-3">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        {...register('verifier', { onChange: handleRoleChange('verifier') })}
                    />
                    <span className="label-text">{i18n._(t`Verifizierer`)}</span>
                </label>
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
