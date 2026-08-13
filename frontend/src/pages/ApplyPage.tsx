import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import useSWR from 'swr';
import { ApiError, applyAccreditation, getAccreditation } from '../api/client';
import type { Accreditation } from '../api/types';
import { accreditationScopeLabel, availabilityLabel } from '../logic/accreditationLabels';
import { formatDate } from '../logic/formatDate';

export function ApplyPage() {
    const { i18n } = useLingui();
    const { accreditationId } = useParams();
    const id = Number(accreditationId);
    const validId = Number.isInteger(id) && id > 0 ? id : null;

    const { data: accreditation, error, isLoading } = useSWR<Accreditation>(
        validId === null ? null : ['/api/accreditations/detail', validId],
        validId === null ? null : () => getAccreditation(validId),
    );

    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess] = useState(false);
    const [submitError, setSubmitError] = useState<string | null>(null);

    const handleApply = async () => {
        if (!accreditation) return;
        setSubmitting(true);
        setSubmitError(null);
        try {
            await applyAccreditation(accreditation.id);
            setSuccess(true);
        } catch (err) {
            setSubmitError(
                err instanceof ApiError ? err.message : i18n._(t`Antrag konnte nicht gesendet werden.`),
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <section className="flex flex-col gap-6">
            <Link to="/akkreditierungen" className="btn btn-ghost btn-sm justify-start">
                <span className="iconify mdi--arrow-left text-xl"></span>
                {i18n._(t`Zurück zu Akkreditierungen`)}
            </Link>

            <h1 className="text-3xl font-bold">{i18n._(t`Akkreditierung beantragen`)}</h1>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Akkreditierung konnte nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {accreditation && !isLoading && !error ? (
                success ? (
                    <div className="flex flex-col gap-4">
                        <div role="alert" className="alert alert-success">
                            <span>{i18n._(t`Antrag erfolgreich gesendet.`)}</span>
                        </div>
                        <p>
                            <Link to="/meine-akkreditierungen" className="link link-primary">
                                {i18n._(t`Meine Akkreditierungen`)}
                            </Link>
                        </p>
                    </div>
                ) : (
                    <>
                        <article className="card border border-base-300 bg-base-100 p-6">
                            <h2 className="text-lg font-semibold">{accreditation.category?.name ?? ''}</h2>
                            <dl className="mt-2 grid gap-2 text-sm">
                                <div className="flex gap-2">
                                    <dt className="w-28 font-medium">{i18n._(t`Geltungsbereich`)}</dt>
                                    <dd>{accreditationScopeLabel(accreditation.scope, i18n)}</dd>
                                </div>
                                {accreditation.event ? (
                                    <div className="flex gap-2">
                                        <dt className="w-28 font-medium">{i18n._(t`Event`)}</dt>
                                        <dd>{accreditation.event.title}</dd>
                                    </div>
                                ) : null}
                                <div className="flex gap-2">
                                    <dt className="w-28 font-medium">{i18n._(t`Verfügbarkeit`)}</dt>
                                    <dd>{availabilityLabel(accreditation.available, i18n)}</dd>
                                </div>
                                {accreditation.deadline_end ? (
                                    <div className="flex gap-2">
                                        <dt className="w-28 font-medium">{i18n._(t`Frist`)}</dt>
                                        <dd>{formatDate(accreditation.deadline_end, i18n.locale)}</dd>
                                    </div>
                                ) : null}
                            </dl>
                        </article>

                        {submitError ? (
                            <div role="alert" className="alert alert-error">
                                <span>{submitError}</span>
                            </div>
                        ) : null}

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                className="btn btn-primary"
                                onClick={() => void handleApply()}
                                disabled={submitting}
                            >
                                {submitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                                {i18n._(t`Akkreditierung beantragen`)}
                            </button>
                        </div>
                    </>
                )
            ) : null}
        </section>
    );
}
