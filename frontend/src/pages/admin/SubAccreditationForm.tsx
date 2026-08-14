import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useForm } from 'react-hook-form';
import type { SubAccreditation } from '../../api/types';
import {
    createSubAccreditationSchema,
    subAccreditationFormDefaults,
    type SubAccreditationFormInput,
    type SubAccreditationFormValues,
} from './accreditationSubFormUtils';

interface SubAccreditationFormProps {
    initial: SubAccreditation | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: SubAccreditationFormValues) => Promise<void>;
    onCancel: () => void;
}

export function SubAccreditationForm({ initial, submitLabel, submitError, onSubmit, onCancel }: SubAccreditationFormProps) {
    const { i18n } = useLingui();
    const schema = createSubAccreditationSchema();

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<SubAccreditationFormInput, unknown, SubAccreditationFormValues>({
        resolver: zodResolver(schema),
        defaultValues: subAccreditationFormDefaults(initial),
    });

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
                <label className="label" htmlFor="sub-accreditation-type">
                    <span className="label-text">{i18n._(t`Typ`)}</span>
                </label>
                <select
                    id="sub-accreditation-type"
                    className={`select ${errors.type ? 'select-error' : ''}`}
                    {...register('type')}
                    required
                >
                    <option value="park">{i18n._(t`Parkkarte`)}</option>
                    <option value="seat">{i18n._(t`Sitzkarte`)}</option>
                </select>
                {errors.type ? (
                    <span className="label-text-alt mt-1 text-error">{errors.type.message}</span>
                ) : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="sub-accreditation-quota">
                    <span className="label-text">{i18n._(t`Quota`)}</span>
                </label>
                <input
                    id="sub-accreditation-quota"
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
                    <label className="label" htmlFor="sub-accreditation-deadline-start">
                        <span className="label-text">{i18n._(t`Frist Beginn`)}</span>
                    </label>
                    <input
                        id="sub-accreditation-deadline-start"
                        type="date"
                        className={`input ${errors.deadline_start ? 'input-error' : ''}`}
                        {...register('deadline_start')}
                    />
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="sub-accreditation-deadline-end">
                        <span className="label-text">{i18n._(t`Frist Ende`)}</span>
                    </label>
                    <input
                        id="sub-accreditation-deadline-end"
                        type="date"
                        className={`input ${errors.deadline_end ? 'input-error' : ''}`}
                        {...register('deadline_end')}
                    />
                </div>
            </div>
            {errors.deadline_end ? (
                <span className="label-text-alt mt-1 text-error">{errors.deadline_end.message}</span>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="sub-accreditation-auto-approve">
                    <span className="label-text">{i18n._(t`Automatische Freigabe`)}</span>
                </label>
                <input
                    id="sub-accreditation-auto-approve"
                    type="checkbox"
                    className="toggle"
                    {...register('auto_approve')}
                />
            </div>

            <div className="form-control">
                <label className="label" htmlFor="sub-accreditation-active">
                    <span className="label-text">{i18n._(t`Aktiv`)}</span>
                </label>
                <input id="sub-accreditation-active" type="checkbox" className="toggle" {...register('active')} />
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
