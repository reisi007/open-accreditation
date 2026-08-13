import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import type { ChangeEvent } from 'react';
import { useForm } from 'react-hook-form';
import type { Event } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { createEventSchema, eventFormDefaults, type EventFormValues } from './eventFormUtils';

interface EventFormProps {
    initial: Event | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: EventFormValues) => Promise<void>;
    onCancel: () => void;
}

export function EventForm({ initial, submitLabel, submitError, onSubmit, onCancel }: EventFormProps) {
    const { i18n } = useLingui();
    const { teams } = useAdminTeams();
    const eventSchema = createEventSchema();

    const {
        register,
        handleSubmit,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<EventFormValues>({
        resolver: zodResolver(eventSchema),
        defaultValues: eventFormDefaults(initial),
    });

    const showTeamSelect = teams !== undefined;

    const handleTeamChange = (event: ChangeEvent<HTMLSelectElement>) => {
        const teamId = Number(event.target.value);
        const team = (teams ?? []).find((candidate) => candidate.id === teamId);
        if (team?.home_venue) {
            setValue('venue', team.home_venue);
        }
    };

    const teamSelectRegister = register('team_id', { onChange: handleTeamChange });

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

            <div className="form-control">
                <label className="label" htmlFor="event-title">
                    <span className="label-text">{i18n._(t`Titel`)}</span>
                </label>
                <input
                    id="event-title"
                    type="text"
                    className={`input ${errors.title ? 'input-error' : ''}`}
                    {...register('title')}
                    required
                />
                {errors.title ? <span className="label-text-alt mt-1 text-error">{errors.title.message}</span> : null}
            </div>

            {showTeamSelect ? (
                <div className="form-control">
                    <label className="label" htmlFor="event-team">
                        <span className="label-text">{i18n._(t`Team`)}</span>
                    </label>
                    <select id="event-team" className="select" {...teamSelectRegister}>
                        <option value="">{i18n._(t`Verbandsebene (Mandant)`)}</option>
                        {(teams ?? []).map((team) => (
                            <option key={team.id} value={String(team.id)}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            <div className="grid gap-4 md:grid-cols-2">
                <div className="form-control">
                    <label className="label" htmlFor="event-date">
                        <span className="label-text">{i18n._(t`Datum`)}</span>
                    </label>
                    <input id="event-date" type="date" className="input" {...register('date')} />
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="event-venue">
                        <span className="label-text">{i18n._(t`Spielort`)}</span>
                    </label>
                    <input id="event-venue" type="text" className="input" {...register('venue')} />
                </div>
            </div>

            <div className="form-control">
                <label className="label" htmlFor="event-competition">
                    <span className="label-text">{i18n._(t`Wettbewerb`)}</span>
                </label>
                <input id="event-competition" type="text" className="input" {...register('competition')} />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="form-control">
                    <label className="label" htmlFor="event-deadline-start">
                        <span className="label-text">{i18n._(t`Frist Beginn`)}</span>
                    </label>
                    <input
                        id="event-deadline-start"
                        type="date"
                        className={`input ${errors.deadline_start ? 'input-error' : ''}`}
                        {...register('deadline_start')}
                    />
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="event-deadline-end">
                        <span className="label-text">{i18n._(t`Frist Ende`)}</span>
                    </label>
                    <input
                        id="event-deadline-end"
                        type="date"
                        className={`input ${errors.deadline_end ? 'input-error' : ''}`}
                        {...register('deadline_end')}
                    />
                </div>
            </div>
            {errors.deadline_end ? (
                <span className="label-text-alt text-error">{errors.deadline_end.message}</span>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="event-active">
                    <span className="label-text">{i18n._(t`Aktiv`)}</span>
                </label>
                <input id="event-active" type="checkbox" className="toggle" {...register('active')} />
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
