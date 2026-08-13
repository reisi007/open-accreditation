import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type ChangeEvent } from 'react';
import { useForm } from 'react-hook-form';
import useSWR from 'swr';
import { listCategories, listEvents } from '../../api/client';
import type { Accreditation, AccreditationScope } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { accreditationFormDefaults, createAccreditationSchema, type AccreditationFormInput, type AccreditationFormValues } from './accreditationFormUtils';

interface AccreditationFormProps {
    initial: Accreditation | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: AccreditationFormValues) => Promise<void>;
    onCancel: () => void;
}

export function AccreditationForm({ initial, submitLabel, submitError, onSubmit, onCancel }: AccreditationFormProps) {
    const { i18n } = useLingui();
    const { teams, currentTeamIds } = useAdminTeams();
    const schema = createAccreditationSchema();
    const { data: categories } = useSWR('/api/admin/categories', () => listCategories());
    const { data: events } = useSWR('/api/admin/events', () => listEvents());

    const teamAdminTeamId = currentTeamIds.length > 0 ? currentTeamIds[0] : null;
    const [scope, setScope] = useState<AccreditationScope>(() => initial?.scope ?? 'event');

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<AccreditationFormInput, unknown, AccreditationFormValues>({
        resolver: zodResolver(schema),
        defaultValues: accreditationFormDefaults(initial, teamAdminTeamId),
    });

    const handleScopeChange = (event: ChangeEvent<HTMLSelectElement>) => {
        setScope(event.target.value as AccreditationScope);
    };

    const scopeRegister = register('scope', { onChange: handleScopeChange });
    const showTeamSelect = teams !== undefined && teamAdminTeamId === null;

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
                <label className="label" htmlFor="accreditation-category">
                    <span className="label-text">{i18n._(t`Kategorie`)}</span>
                </label>
                <select
                    id="accreditation-category"
                    className={`select ${errors.category_id ? 'select-error' : ''}`}
                    {...register('category_id')}
                    required
                >
                    <option value="">{i18n._(t`Bitte auswählen`)}</option>
                    {(categories ?? []).map((category) => (
                        <option key={category.id} value={String(category.id)}>
                            {category.name}
                        </option>
                    ))}
                </select>
                {errors.category_id ? (
                    <span className="label-text-alt mt-1 text-error">{errors.category_id.message}</span>
                ) : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="accreditation-scope">
                    <span className="label-text">{i18n._(t`Geltungsbereich`)}</span>
                </label>
                <select id="accreditation-scope" className="select" {...scopeRegister} required>
                    <option value="event">{i18n._(t`Spiel`)}</option>
                    <option value="league">{i18n._(t`Liga`)}</option>
                    <option value="season">{i18n._(t`Saison`)}</option>
                </select>
            </div>

            {scope === 'event' ? (
                <div className="form-control">
                    <label className="label" htmlFor="accreditation-event">
                        <span className="label-text">{i18n._(t`Event`)}</span>
                    </label>
                    <select
                        id="accreditation-event"
                        className={`select ${errors.event_id ? 'select-error' : ''}`}
                        {...register('event_id')}
                        required
                    >
                        <option value="">{i18n._(t`Bitte auswählen`)}</option>
                        {(events ?? []).map((event) => (
                            <option key={event.id} value={String(event.id)}>
                                {event.title}
                            </option>
                        ))}
                    </select>
                    {errors.event_id ? (
                        <span className="label-text-alt mt-1 text-error">{errors.event_id.message}</span>
                    ) : null}
                </div>
            ) : null}

            {showTeamSelect ? (
                <div className="form-control">
                    <label className="label" htmlFor="accreditation-team">
                        <span className="label-text">{i18n._(t`Team`)}</span>
                    </label>
                    <select id="accreditation-team" className="select" {...register('team_id')}>
                        <option value="">{i18n._(t`Verbandsebene (Mandant)`)}</option>
                        {(teams ?? []).map((team) => (
                            <option key={team.id} value={String(team.id)}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="accreditation-quota">
                    <span className="label-text">{i18n._(t`Quota`)}</span>
                </label>
                <input
                    id="accreditation-quota"
                    type="number"
                    min={1}
                    className={`input ${errors.quota ? 'input-error' : ''}`}
                    {...register('quota')}
                    required
                />
                {errors.quota ? <span className="label-text-alt mt-1 text-error">{errors.quota.message}</span> : null}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="form-control">
                    <label className="label" htmlFor="accreditation-deadline-start">
                        <span className="label-text">{i18n._(t`Frist Beginn`)}</span>
                    </label>
                    <input
                        id="accreditation-deadline-start"
                        type="date"
                        className={`input ${errors.deadline_start ? 'input-error' : ''}`}
                        {...register('deadline_start')}
                    />
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="accreditation-deadline-end">
                        <span className="label-text">{i18n._(t`Frist Ende`)}</span>
                    </label>
                    <input
                        id="accreditation-deadline-end"
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
                <label className="label" htmlFor="accreditation-auto-approve">
                    <span className="label-text">{i18n._(t`Automatische Freigabe`)}</span>
                </label>
                <input id="accreditation-auto-approve" type="checkbox" className="toggle" {...register('auto_approve')} />
            </div>

            <div className="form-control">
                <label className="label" htmlFor="accreditation-active">
                    <span className="label-text">{i18n._(t`Aktiv`)}</span>
                </label>
                <input id="accreditation-active" type="checkbox" className="toggle" {...register('active')} />
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
