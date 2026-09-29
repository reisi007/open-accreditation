import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import useSWR from 'swr';
import { getPortalEvents, getPortalOverview } from '../../api/client';
import type { PortalEvent, PortalOverview } from '../../api/types';
import { DeadlineCountdown } from '../../components/DeadlineCountdown';
import { deletionSuccessMessage, mediaResidueWarning } from '../../logic/accountDeletion';
import { readAccountDeletedNotice } from '../../logic/accountDeletedNotice';
import { formatDate } from '../../logic/formatDate';
import { getHomepageLogo } from '../../logic/homepageLogo';

export function PortalHomePage() {
    const { i18n } = useLingui();
    const location = useLocation();
    const [teamFilter, setTeamFilter] = useState<number | null>(null);
    const [competitionFilter, setCompetitionFilter] = useState('');
    const [logoFailed, setLogoFailed] = useState(false);
    const [headerFailed, setHeaderFailed] = useState(false);

    /**
     * The one-shot report of a self-service account deletion, handed over by
     * `AccountPage` through the router state (see
     * `logic/accountDeletedNotice.ts` for why the result cannot stay on the
     * page that produced it). `null` on every ordinary visit, so the block
     * below renders nothing.
     */
    const accountDeleted = readAccountDeletedNotice(location.state);
    const mediaResidue = accountDeleted ? mediaResidueWarning(accountDeleted.result.media_files_left_over, i18n) : null;

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

    /**
     * Competition options come from a competition-INDEPENDENT query, not from
     * the currently filtered result set: `PortalController` filters
     * `competition` with a partial LIKE, so "Bundesliga" also matches
     * "2. Bundesliga" and the option list would otherwise shrink (and change
     * meaning) with every narrowing step. Keyed by the team filter only, so it
     * stays a stable list for the selected team and is cached by SWR.
     */
    const { data: teamEvents } = useSWR<PortalEvent[]>(['/api/portal/events', teamFilter], () =>
        getPortalEvents({ team_id: teamFilter }),
    );

    const teams = overview?.teams ?? [];
    const showTeamsSection = Boolean(overview?.mandant.teams_enabled) && teams.length > 0;

    const competitionOptions = [
        ...new Set(
            (teamEvents ?? [])
                .map((event) => event.competition)
                .filter((value): value is string => Boolean(value)),
        ),
    ].sort();

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
            {accountDeleted ? (
                <div role="alert" className="alert alert-success">
                    <span>{deletionSuccessMessage(accountDeleted.result, 'self', i18n)}</span>
                </div>
            ) : null}

            {/*
              A file that survived the deletion is a WARNING, never an error:
              the account is gone either way, and the backend deliberately
              reports the residue instead of failing the request. Rendering it
              as an error would tell the user their deletion failed when it
              succeeded — see `AccountDeletionService`.
            */}
            {mediaResidue ? (
                <div role="alert" className="alert alert-warning">
                    <span className="break-all">{mediaResidue}</span>
                </div>
            ) : null}

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
                        <img
                            src={getHomepageLogo(mandant, logoFailed)}
                            alt={mandant.name}
                            className="h-20 w-20 rounded-box object-cover"
                            onError={mandant.logo_url !== null && !logoFailed ? () => setLogoFailed(true) : undefined}
                        />
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
                            <div className="card border border-base-300 bg-base-100">
                                <div className="card-body items-center justify-center py-16 text-center">
                                    <span className="iconify mdi--calendar-outline text-6xl text-base-content/40"></span>
                                    <h2 className="card-title">{i18n._(t`Keine Veranstaltungen`)}</h2>
                                    <p className="text-base-content/70">
                                        {i18n._(t`Zurzeit sind keine Veranstaltungen eingetragen.`)}
                                    </p>
                                </div>
                            </div>
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
