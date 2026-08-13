import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import useSWR from 'swr';
import { getPortalEvents, getPortalOverview } from '../../api/client';
import type { PortalEvent, PortalOverview } from '../../api/types';
import { DeadlineCountdown } from '../../components/DeadlineCountdown';
import { formatDate } from '../../logic/formatDate';

export function PortalHomePage() {
    const { i18n } = useLingui();
    const [teamFilter, setTeamFilter] = useState<number | null>(null);
    const [competitionFilter, setCompetitionFilter] = useState('');
    const [logoFailed, setLogoFailed] = useState(false);
    const [headerFailed, setHeaderFailed] = useState(false);

    const {
        data: overview,
        error: overviewError,
        isLoading: overviewLoading,
    } = useSWR<PortalOverview>('/api/portal/overview', getPortalOverview);

    const eventsParams = {
        team_id: teamFilter,
        competition: competitionFilter === '' ? undefined : competitionFilter,
    };
    const {
        data: events,
        error: eventsError,
        isLoading: eventsLoading,
    } = useSWR<PortalEvent[]>(['/api/portal/events', teamFilter, competitionFilter], () => getPortalEvents(eventsParams));

    const teams = overview?.teams ?? [];
    const showTeamsSection = Boolean(overview?.mandant.teams_enabled) && teams.length > 0;

    const competitions = [
        ...new Set((events ?? []).map((event) => event.competition).filter((value): value is string => Boolean(value))),
    ].sort();
    const competitionOptions =
        competitionFilter !== '' && !competitions.includes(competitionFilter) ? [competitionFilter, ...competitions] : competitions;

    const eventLocation = (event: PortalEvent): string | null => event.venue;

    const handleTeamSelect = (value: string) => {
        setTeamFilter(value === '' ? null : Number(value));
    };

    const handleTeamTileClick = (teamId: number) => {
        setTeamFilter((current) => (current === teamId ? null : teamId));
    };

    const mandant = overview?.mandant;

    return (
        <section className="flex flex-col gap-8">
            {overviewLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {overviewError ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Portal konnte nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {mandant && !overviewLoading && !overviewError ? (
                <>
                    {mandant.header_url && !headerFailed ? (
                        <img
                            src={mandant.header_url}
                            alt=""
                            className="h-40 w-full rounded-box object-cover"
                            onError={() => setHeaderFailed(true)}
                        />
                    ) : null}

                    <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                        {mandant.logo_url && !logoFailed ? (
                            <img
                                src={mandant.logo_url}
                                alt={mandant.name}
                                className="h-20 w-20 rounded-box object-cover"
                                onError={() => setLogoFailed(true)}
                            />
                        ) : null}
                        <h1 className="text-3xl font-bold">{mandant.name}</h1>
                    </div>

                    {showTeamsSection ? (
                        <section aria-label={i18n._(t`Vereine`)} className="flex flex-col gap-4">
                            <h2 className="text-2xl font-bold">{i18n._(t`Vereine`)}</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {teams.map((team) => {
                                    const isActive = teamFilter === team.id;
                                    return (
                                        <button
                                            key={team.id}
                                            type="button"
                                            className={`card w-full border bg-base-100 p-4 text-left transition-colors ${
                                                isActive ? 'border-primary shadow-md' : 'border-base-300 hover:border-primary/50'
                                            }`}
                                            onClick={() => handleTeamTileClick(team.id)}
                                        >
                                            <span className="font-semibold">{team.name}</span>
                                            {team.home_venue ? (
                                                <span className="text-sm text-base-content/70">{team.home_venue}</span>
                                            ) : null}
                                        </button>
                                    );
                                })}
                            </div>
                        </section>
                    ) : null}

                    <section aria-label={i18n._(t`Veranstaltungskalender`)} className="flex flex-col gap-4">
                        <h2 className="text-2xl font-bold">{i18n._(t`Veranstaltungskalender`)}</h2>

                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                aria-label={i18n._(t`Team`)}
                                className="select select-sm"
                                value={teamFilter ?? ''}
                                onChange={(event) => handleTeamSelect(event.target.value)}
                            >
                                <option value="">{i18n._(t`Alle Teams`)}</option>
                                {teams.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={i18n._(t`Wettbewerb`)}
                                className="select select-sm"
                                value={competitionFilter}
                                onChange={(event) => setCompetitionFilter(event.target.value)}
                            >
                                <option value="">{i18n._(t`Alle Wettbewerbe`)}</option>
                                {competitionOptions.map((competition) => (
                                    <option key={competition} value={competition}>
                                        {competition}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {eventsLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

                        {eventsError ? (
                            <div role="alert" className="alert alert-error">
                                <span>{i18n._(t`Veranstaltungen konnten nicht geladen werden.`)}</span>
                            </div>
                        ) : null}

                        {events && !eventsLoading && !eventsError && events.length === 0 ? (
                            <p className="text-base-content/70">{i18n._(t`Keine Veranstaltungen`)}</p>
                        ) : null}

                        {events && !eventsLoading && !eventsError && events.length > 0 ? (
                            <div className="flex flex-col gap-4">
                                {events.map((event) => {
                                    const location = eventLocation(event);
                                    return (
                                        <Link
                                            key={event.id}
                                            to={`/events/${event.id}`}
                                            className="card border border-base-300 bg-base-100 p-4 transition-colors hover:border-primary"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                <h3 className="text-lg font-semibold">{event.title}</h3>
                                                {event.deadline_end ? <DeadlineCountdown deadline={event.deadline_end} /> : null}
                                            </div>
                                            <dl className="mt-2 grid gap-1 text-sm">
                                                {event.date ? (
                                                    <div className="flex gap-2">
                                                        <dt className="font-medium">{i18n._(t`Datum`)}</dt>
                                                        <dd>{formatDate(event.date, i18n.locale)}</dd>
                                                    </div>
                                                ) : null}
                                                {location ? (
                                                    <div className="flex gap-2">
                                                        <dt className="font-medium">{i18n._(t`Ort`)}</dt>
                                                        <dd>{location}</dd>
                                                    </div>
                                                ) : null}
                                                {event.competition ? (
                                                    <div className="flex gap-2">
                                                        <dt className="font-medium">{i18n._(t`Wettbewerb`)}</dt>
                                                        <dd>{event.competition}</dd>
                                                    </div>
                                                ) : null}
                                            </dl>
                                        </Link>
                                    );
                                })}
                            </div>
                        ) : null}
                    </section>

                    {mandant.impressum_text || mandant.privacy_text ? (
                        <section className="flex flex-col gap-6 border-t border-base-300 pt-6">
                            {mandant.impressum_text ? (
                                <div>
                                    <h2 className="text-xl font-semibold">{i18n._(t`Impressum`)}</h2>
                                    <p className="whitespace-pre-line text-sm text-base-content/80">{mandant.impressum_text}</p>
                                </div>
                            ) : null}
                            {mandant.privacy_text ? (
                                <div>
                                    <h2 className="text-xl font-semibold">{i18n._(t`Datenschutzerklärung`)}</h2>
                                    <p className="whitespace-pre-line text-sm text-base-content/80">{mandant.privacy_text}</p>
                                </div>
                            ) : null}
                        </section>
                    ) : null}
                </>
            ) : null}
        </section>
    );
}
