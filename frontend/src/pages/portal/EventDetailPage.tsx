import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Link, useParams } from 'react-router-dom';
import useSWR from 'swr';
import { getPortalEvent } from '../../api/client';
import type { PortalEventDetail } from '../../api/types';
import { DeadlineCountdown } from '../../components/DeadlineCountdown';
import { formatDate } from '../../logic/formatDate';

export function EventDetailPage() {
    const { i18n } = useLingui();
    const { id } = useParams();
    const eventId = Number(id);
    const validId = Number.isInteger(eventId) && eventId > 0 ? eventId : null;

    const {
        data: event,
        error,
        isLoading,
    } = useSWR<PortalEventDetail>(
        validId === null ? null : ['/api/portal/events/detail', validId],
        validId === null ? null : () => getPortalEvent(validId),
    );

    return (
        <section className="flex flex-col gap-6">
            <Link to="/" className="btn btn-ghost btn-sm justify-start">
                <span className="iconify mdi--arrow-left text-xl"></span>
                {i18n._(t`Zurück zur Startseite`)}
            </Link>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Veranstaltung konnte nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {event && !isLoading && !error ? (
                <article className="card border border-base-300 bg-base-100 p-6">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <h1 className="text-3xl font-bold">{event.title}</h1>
                        {event.deadline_effective ? <DeadlineCountdown deadline={event.deadline_effective} /> : null}
                    </div>

                    {event.team ? <span className="badge badge-outline mt-2">{event.team.name}</span> : null}

                    <dl className="mt-4 grid gap-2 text-sm">
                        {event.date ? (
                            <div className="flex gap-2">
                                <dt className="w-28 font-medium">{i18n._(t`Datum`)}</dt>
                                <dd>{formatDate(event.date, i18n.locale)}</dd>
                            </div>
                        ) : null}
                        {event.venue_effective ? (
                            <div className="flex gap-2">
                                <dt className="w-28 font-medium">{i18n._(t`Ort`)}</dt>
                                <dd>{event.venue_effective}</dd>
                            </div>
                        ) : null}
                        {event.competition ? (
                            <div className="flex gap-2">
                                <dt className="w-28 font-medium">{i18n._(t`Wettbewerb`)}</dt>
                                <dd>{event.competition}</dd>
                            </div>
                        ) : null}
                    </dl>

                    {event.contact ? (
                        <div className="mt-6 border-t border-base-200 pt-4">
                            <h2 className="text-lg font-semibold">{i18n._(t`Kontakt`)}</h2>
                            <p>{event.contact.name}</p>
                            <a href={`mailto:${event.contact.email}`} className="link link-primary">
                                {event.contact.email}
                            </a>
                        </div>
                    ) : null}
                </article>
            ) : null}
        </section>
    );
}
