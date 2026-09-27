import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Controller, useForm } from 'react-hook-form';
import type { Team } from '../../api/types';
import { VenueCombobox } from '../../components/VenueCombobox';
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
        control,
        formState: { errors, isSubmitting },
    } = useForm<TeamFormValues>({
        resolver: zodResolver(teamSchema),
        defaultValues: teamFormDefaults(initial),
    });

    return (
        <form
            className="mt-4 flex flex-col gap-4"
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
                {/*
                  The combobox owns its own `.form-control` + label so the
                  dropdown can overlay the grid row below without fighting the
                  layout, and so the create affordance sits right where the
                  admin sets the home venue.
                */}
                <Controller
                    control={control}
                    name="venue_id"
                    render={({ field }) => (
                        <VenueCombobox
                            label={i18n._(t`Heimstätte`)}
                            inputId="team-home-venue"
                            value={field.value}
                            onChange={field.onChange}
                            valueLabel={initial?.venue?.name ?? null}
                            disabled={isSubmitting}
                        />
                    )}
                />
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
