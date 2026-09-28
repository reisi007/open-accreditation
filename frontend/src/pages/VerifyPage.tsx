import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type FormEvent } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import useSWR from 'swr';
import { ApiError, verifyToken } from '../api/client';
import type { ApplicationStatus, VerifyResult } from '../api/types';
import { formatDate } from '../logic/formatDate';

function verifyStatusLabel(status: ApplicationStatus, i18n: I18n): string {
    switch (status) {
        case 'approved':
            return i18n._(t`Akkreditiert`);
        case 'requested':
            return i18n._(t`Beantragt`);
        case 'denied':
            return i18n._(t`Abgelehnt`);
        case 'blacklisted':
            return i18n._(t`Gesperrt`);
    }
}

function verifyStatusBadgeClass(status: ApplicationStatus): string {
    switch (status) {
        case 'approved':
            return 'badge-success';
        case 'requested':
            return 'badge-warning';
        case 'denied':
            return 'badge-error';
        case 'blacklisted':
            return 'badge-error';
    }
}

/**
 * Public QR verification (P4). The token arrives either as a URL path segment
 * (the `qr_url` of an approved application, i.e. `/verify/<token>`) or as the
 * `?token=` query parameter. `verify` and `verify/:token` are the same
 * component, so a SPA back/forward between two scanned QR links keeps the same
 * mounted instance — the URL token is therefore re-synced during render (React's
 * documented "adjust state when a prop changes" pattern), and the manual
 * "Prüfen" button triggers a re-verification for a newly typed token.
 */
export function VerifyPage() {
    const { i18n } = useLingui();
    const { token: pathToken } = useParams();
    const [searchParams] = useSearchParams();
    const urlToken = (pathToken ?? searchParams.get('token') ?? '').trim();

    const [input, setInput] = useState(urlToken);
    const [submittedToken, setSubmittedToken] = useState(urlToken);
    const [syncedUrlToken, setSyncedUrlToken] = useState(urlToken);

    // Re-sync when the URL token changes underneath the mounted component
    // (browser back/forward between two scanned links). Without this the
    // previous person's result stays on screen under the new URL.
    if (syncedUrlToken !== urlToken) {
        setSyncedUrlToken(urlToken);
        setInput(urlToken);
        setSubmittedToken(urlToken);
    }

    const { data, error, isLoading } = useSWR<VerifyResult>(
        submittedToken !== '' ? ['/api/verify', submittedToken] : null,
        () => verifyToken(submittedToken),
        { shouldRetryOnError: false },
    );

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setSubmittedToken(input.trim());
    };

    const errorMessage = error
        ? error instanceof ApiError && error.status === 404
            ? i18n._(t`Ungültiger Code.`)
            : i18n._(t`Prüfung fehlgeschlagen.`)
        : null;

    const photoSrc = data?.photo_url ? new URL(data.photo_url, window.location.origin).toString() : null;

    return (
        <section className="mx-auto flex w-full max-w-md flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Verifizieren`)}</h1>

            <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
                <div className="form-control">
                    <label className="label" htmlFor="verify-token">
                        <span className="label-text">{i18n._(t`Code`)}</span>
                    </label>
                    <input
                        id="verify-token"
                        type="text"
                        autoComplete="off"
                        className="input"
                        value={input}
                        onChange={(event) => setInput(event.target.value)}
                        required
                        aria-describedby="verify-token-hint"
                    />
                    {/*
                      P13. `/verify` is normally reached by SCANNING the code on a
                      badge, so in that flow the empty field is self-explanatory.
                      The page is also opened directly — the camera is broken, the
                      code was copied by hand, a link was truncated in a chat — and
                      then a bare mandatory input says nothing about where the code
                      is supposed to come from. One sentence, tied to the input via
                      `aria-describedby` so it is announced WITH the field instead
                      of merely printed near it.
                    */}
                    <p id="verify-token-hint" className="mt-1 text-sm text-base-content/70">
                        {i18n._(
                            t`Der Code steht als QR-Code auf dem Ausweis. Alternativ kannst du den Code aus der Adresse des Ausweis-Links übernehmen.`,
                        )}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <button type="submit" className="btn btn-primary" disabled={isLoading}>
                        {isLoading ? <span className="loading loading-spinner loading-xs"></span> : null}
                        {i18n._(t`Prüfen`)}
                    </button>
                </div>
            </form>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {errorMessage ? (
                <div role="alert" className="alert alert-error">
                    <span>{errorMessage}</span>
                </div>
            ) : null}

            {data && !isLoading && !error ? (
                <article className="card bg-base-200">
                    <div className="card-body items-center gap-4">
                        <span className={`badge badge-lg ${verifyStatusBadgeClass(data.status)}`}>
                            {verifyStatusLabel(data.status, i18n)}
                        </span>
                        {data.status === 'approved' ? (
                            <>
                                <dl className="grid w-full gap-2 text-sm">
                                    {data.name ? (
                                        <div className="flex gap-2">
                                            <dt className="w-28 font-medium">{i18n._(t`Name`)}</dt>
                                            <dd>{data.name}</dd>
                                        </div>
                                    ) : null}
                                    {data.category ? (
                                        <div className="flex gap-2">
                                            <dt className="w-28 font-medium">{i18n._(t`Kategorie`)}</dt>
                                            <dd>{data.category}</dd>
                                        </div>
                                    ) : null}
                                    {data.event ? (
                                        <div className="flex gap-2">
                                            <dt className="w-28 font-medium">{i18n._(t`Event`)}</dt>
                                            <dd>{data.event}</dd>
                                        </div>
                                    ) : null}
                                    {data.date ? (
                                        <div className="flex gap-2">
                                            <dt className="w-28 font-medium">{i18n._(t`Datum`)}</dt>
                                            <dd>{formatDate(data.date, i18n.locale)}</dd>
                                        </div>
                                    ) : null}
                                </dl>
                                {photoSrc ? (
                                    <div className="flex justify-center">
                                        {/* The portrait URL is only returned for approved applications; a missing
                                            portrait (404) hides the broken image instead of showing an error icon. */}
                                        <img
                                            src={photoSrc}
                                            alt={i18n._(t`Foto`)}
                                            className="h-40 w-32 rounded object-cover shadow"
                                            onError={(event) => {
                                                event.currentTarget.hidden = true;
                                            }}
                                        />
                                    </div>
                                ) : null}
                            </>
                        ) : null}
                    </div>
                </article>
            ) : null}
        </section>
    );
}
