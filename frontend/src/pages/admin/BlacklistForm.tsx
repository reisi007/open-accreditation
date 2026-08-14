import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useForm } from 'react-hook-form';
import { createBlacklistSchema, type BlacklistFormValues } from './approvalFormUtils';

interface BlacklistFormProps {
    submitError: string | null;
    onSubmit: (values: BlacklistFormValues) => Promise<void>;
}

export function BlacklistForm({ submitError, onSubmit }: BlacklistFormProps) {
    const { i18n } = useLingui();
    const schema = createBlacklistSchema();

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<BlacklistFormValues>({
        resolver: zodResolver(schema),
        defaultValues: { email: '', domain: '', note: '' },
    });

    return (
        <form
            className="flex flex-col gap-4"
            noValidate
            onSubmit={handleSubmit(async (values) => {
                await onSubmit(values);
                reset();
            })}
        >
            {submitError ? (
                <div role="alert" className="alert alert-error">
                    <span>{submitError}</span>
                </div>
            ) : null}

            <div className="grid gap-4 md:grid-cols-3">
                <div className="form-control">
                    <label className="label" htmlFor="blacklist-email">
                        <span className="label-text">{i18n._(t`E-Mail`)}</span>
                    </label>
                    <input
                        id="blacklist-email"
                        type="email"
                        className={`input ${errors.email ? 'input-error' : ''}`}
                        {...register('email')}
                        placeholder={i18n._(t`person@example.com`)}
                    />
                    {errors.email ? (
                        <span className="label-text-alt mt-1 text-error">{errors.email.message}</span>
                    ) : null}
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="blacklist-domain">
                        <span className="label-text">{i18n._(t`Domäne`)}</span>
                    </label>
                    <input
                        id="blacklist-domain"
                        className={`input ${errors.domain ? 'input-error' : ''}`}
                        {...register('domain')}
                        placeholder="example.com"
                    />
                    {errors.domain ? (
                        <span className="label-text-alt mt-1 text-error">{errors.domain.message}</span>
                    ) : null}
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="blacklist-note">
                        <span className="label-text">{i18n._(t`Notiz`)}</span>
                    </label>
                    <input id="blacklist-note" className="input" {...register('note')} />
                </div>
            </div>

            {errors.root ? (
                <p role="alert" className="text-sm text-error">
                    {errors.root.message}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {i18n._(t`Blacklist-Eintrag anlegen`)}
                </button>
            </div>
        </form>
    );
}
