import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import type { Mandant } from '../../api/types';
import {
    createMandantSchema,
    mandantFormDefaults,
    type MandantFormValues,
} from './mandantFormUtils';

interface MandantFormProps {
    initial: Mandant | null;
    isEdit: boolean;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: MandantFormValues, smtpCleared: boolean) => Promise<void>;
    onCancel?: () => void;
}

export function MandantForm({ initial, isEdit, submitLabel, submitError, onSubmit, onCancel }: MandantFormProps) {
    const { i18n } = useLingui();
    const mandantSchema = createMandantSchema();
    const [smtpCleared, setSmtpCleared] = useState(false);

    const {
        register,
        handleSubmit,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<MandantFormValues>({
        resolver: zodResolver(mandantSchema),
        defaultValues: mandantFormDefaults(initial),
    });

    const handleClearSmtp = () => {
        if (!window.confirm(i18n._(t`SMTP-Konfiguration wirklich löschen?`))) return;

        setValue('smtp_host', '');
        setValue('smtp_port', '');
        setValue('smtp_username', '');
        setValue('smtp_password', '');
        setValue('smtp_encryption', '');
        setSmtpCleared(true);
    };

    const smtpRegister = (name: keyof MandantFormValues) =>
        register(name, { onChange: () => setSmtpCleared(false) });

    return (
        <form
            className="flex flex-col gap-4"
            noValidate
            onSubmit={handleSubmit(async (values) => {
                await onSubmit(values, smtpCleared);
                setSmtpCleared(false);
            })}
        >
            {submitError ? (
                <div role="alert" className="alert alert-error">
                    <span>{submitError}</span>
                </div>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="mandant-name">
                    <span className="label-text">{i18n._(t`Name`)}</span>
                </label>
                <input
                    id="mandant-name"
                    type="text"
                    className={`input ${errors.name ? 'input-error' : ''}`}
                    {...register('name')}
                    required
                />
                {errors.name ? <span className="label-text-alt mt-1 text-error">{errors.name.message}</span> : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="mandant-slug">
                    <span className="label-text">{i18n._(t`Slug`)}</span>
                </label>
                <input
                    id="mandant-slug"
                    type="text"
                    className={`input ${errors.slug ? 'input-error' : ''}`}
                    {...register('slug')}
                    required
                />
                <span className="label-text-alt mt-1 text-base-content/70">
                    {i18n._(t`Erlaubt sind Kleinbuchstaben, Zahlen und Bindestriche.`)}
                </span>
                {errors.slug ? <span className="label-text-alt text-error">{errors.slug.message}</span> : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="mandant-teams-enabled">
                    <span className="label-text">{i18n._(t`Teams aktivieren`)}</span>
                </label>
                <input id="mandant-teams-enabled" type="checkbox" className="toggle" {...register('teams_enabled')} />
                <span className="label-text-alt mt-1 text-base-content/70">
                    {i18n._(t`Erlaubt die Verwaltung von Vereinen für diesen Mandanten.`)}
                </span>
            </div>

            {isEdit ? (
                <div className="form-control">
                    <label className="label" htmlFor="mandant-is-active">
                        <span className="label-text">{i18n._(t`Aktiv`)}</span>
                    </label>
                    <input id="mandant-is-active" type="checkbox" className="toggle" {...register('is_active')} />
                </div>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="mandant-impressum">
                    <span className="label-text">{i18n._(t`Impressum`)}</span>
                </label>
                <textarea
                    id="mandant-impressum"
                    className={`textarea ${errors.impressum_text ? 'textarea-error' : ''}`}
                    rows={4}
                    {...register('impressum_text')}
                />
            </div>

            <div className="form-control">
                <label className="label" htmlFor="mandant-privacy">
                    <span className="label-text">{i18n._(t`Datenschutzerklärung`)}</span>
                </label>
                <textarea
                    id="mandant-privacy"
                    className={`textarea ${errors.privacy_text ? 'textarea-error' : ''}`}
                    rows={4}
                    {...register('privacy_text')}
                />
            </div>

            <fieldset className="rounded-box bg-base-100 p-4">
                <legend className="px-2 font-semibold">{i18n._(t`SMTP`)}</legend>
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="form-control">
                        <label className="label" htmlFor="mandant-smtp-host">
                            <span className="label-text">{i18n._(t`SMTP-Host`)}</span>
                        </label>
                        <input id="mandant-smtp-host" type="text" className="input" {...smtpRegister('smtp_host')} />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="mandant-smtp-port">
                            <span className="label-text">{i18n._(t`SMTP-Port`)}</span>
                        </label>
                        <input
                            id="mandant-smtp-port"
                            type="text"
                            inputMode="numeric"
                            className={`input ${errors.smtp_port ? 'input-error' : ''}`}
                            {...smtpRegister('smtp_port')}
                        />
                        {errors.smtp_port ? (
                            <span className="label-text-alt mt-1 text-error">{errors.smtp_port.message}</span>
                        ) : null}
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="mandant-smtp-username">
                            <span className="label-text">{i18n._(t`SMTP-Benutzername`)}</span>
                        </label>
                        <input
                            id="mandant-smtp-username"
                            type="text"
                            className="input"
                            {...smtpRegister('smtp_username')}
                        />
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="mandant-smtp-encryption">
                            <span className="label-text">{i18n._(t`Verschlüsselung`)}</span>
                        </label>
                        <select id="mandant-smtp-encryption" className="select" {...smtpRegister('smtp_encryption')}>
                            <option value="">{i18n._(t`Keine`)}</option>
                            <option value="ssl">SSL</option>
                            <option value="tls">TLS</option>
                        </select>
                    </div>
                    <div className="form-control">
                        <label className="label" htmlFor="mandant-smtp-password">
                            <span className="label-text">{i18n._(t`SMTP-Passwort`)}</span>
                        </label>
                        <input
                            id="mandant-smtp-password"
                            type="password"
                            autoComplete="new-password"
                            placeholder="••••••"
                            className="input"
                            {...smtpRegister('smtp_password')}
                        />
                        {isEdit ? (
                            <span className="label-text-alt mt-1 text-base-content/70">
                                {i18n._(t`Leer = unverändert`)}
                            </span>
                        ) : null}
                    </div>
                    {isEdit ? (
                        <div className="flex items-end">
                            <button type="button" className="btn btn-outline btn-error btn-sm" onClick={handleClearSmtp}>
                                {i18n._(t`SMTP löschen`)}
                            </button>
                        </div>
                    ) : null}
                </div>
            </fieldset>

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {submitLabel}
                </button>
                {onCancel ? (
                    <button type="button" className="btn" onClick={onCancel}>
                        {i18n._(t`Abbrechen`)}
                    </button>
                ) : null}
            </div>
        </form>
    );
}
