import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useForm } from 'react-hook-form';
import type { Venue } from '../../api/types';
import { createVenueSchema, venueFormDefaults, type VenueFormValues } from './venueFormUtils';

interface VenueFormProps {
    initial: Venue | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: VenueFormValues) => Promise<void>;
    onCancel: () => void;
}

export function VenueForm({ initial, submitLabel, submitError, onSubmit, onCancel }: VenueFormProps) {
    const { i18n } = useLingui();
    const venueSchema = createVenueSchema();

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<VenueFormValues>({
        resolver: zodResolver(venueSchema),
        defaultValues: venueFormDefaults(initial),
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
                <label className="label" htmlFor="venue-name">
                    <span className="label-text">{i18n._(t`Name`)}</span>
                </label>
                <input
                    id="venue-name"
                    type="text"
                    className={errors.name ? 'input input-error' : 'input'}
                    {...register('name')}
                    required
                />
                {errors.name ? <span className="label-text-alt mt-1 text-error">{errors.name.message}</span> : null}
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
