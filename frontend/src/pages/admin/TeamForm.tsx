import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useForm } from 'react-hook-form';
import type { Team } from '../../api/types';
import { createTeamSchema, teamFormDefaults, type TeamFormValues } from './teamFormUtils';

interface TeamFormProps {
    initial: Team | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: TeamFormValues) => Promise<void>;
    onCancel: () => void;
}

export function TeamForm({ initial, submitLabel, submitError, onSubmit, onCancel }: TeamFormProps) {
    const { i18n } = useLingui();
    const teamSchema = createTeamSchema();

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<TeamFormValues>({
        resolver: zodResolver(teamSchema),
        defaultValues: teamFormDefaults(initial),
    });

    return (
        <form
            className="mt-4 flex flex-col gap-4 rounded-box bg-base-100 p-4"
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

            <div className="grid gap-4 md:grid-cols-3">
                <div className="form-control">
                    <label className="label" htmlFor="team-name">
                        <span className="label-text">{i18n._(t`Team-Name`)}</span>
                    </label>
                    <input
                        id="team-name"
                        type="text"
                        className={`input ${errors.name ? 'input-error' : ''}`}
                        {...register('name')}
                        required
                    />
                    {errors.name ? <span className="label-text-alt mt-1 text-error">{errors.name.message}</span> : null}
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="team-slug">
                        <span className="label-text">{i18n._(t`Team-Slug`)}</span>
                    </label>
                    <input
                        id="team-slug"
                        type="text"
                        className={`input ${errors.slug ? 'input-error' : ''}`}
                        {...register('slug')}
                        required
                    />
                    {errors.slug ? <span className="label-text-alt mt-1 text-error">{errors.slug.message}</span> : null}
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="team-home-venue">
                        <span className="label-text">{i18n._(t`Heimstätte`)}</span>
                    </label>
                    <input id="team-home-venue" type="text" className="input" {...register('home_venue')} />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
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
