import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import useSWR, { mutate as globalMutate } from 'swr';
import {
    addDomain,
    ApiError,
    deleteHeader,
    deleteLogo,
    deleteMandant,
    deleteTeam,
    getMandant,
    listDomains,
    listTeams,
    removeDomain,
    updateMandant,
    uploadHeader,
    uploadLogo,
    createTeam,
    updateTeam,
} from '../../api/client';
import type { Mandant, MandantDomain, Team } from '../../api/types';
import { MediaField } from '../../components/MediaField';
import { MandantForm } from './MandantForm';
import { buildMandantPayload, type MandantFormValues } from './mandantFormUtils';
import { TeamForm } from './TeamForm';
import { buildTeamPayload, type TeamFormValues } from './teamFormUtils';

export function MandantDetailPage() {
    const { i18n } = useLingui();
    const navigate = useNavigate();
    const params = useParams<{ id: string }>();
    const mandantId = Number(params.id);

    const mandantKey = `/api/admin/mandants/${mandantId}`;
    const { data: mandant, error, isLoading, mutate } = useSWR<Mandant>(mandantKey, () => getMandant(mandantId));
    const { data: domains, mutate: mutateDomains } = useSWR<MandantDomain[]>(
        `${mandantKey}/domains`,
        () => listDomains(mandantId),
    );
    const { data: teams, mutate: mutateTeams } = useSWR<Team[]>(`${mandantKey}/teams`, () => listTeams(mandantId));

    const [submitError, setSubmitError] = useState<string | null>(null);
    const [domainError, setDomainError] = useState<string | null>(null);
    const [teamFormError, setTeamFormError] = useState<string | null>(null);
    const [showTeamForm, setShowTeamForm] = useState(false);
    const [editingTeam, setEditingTeam] = useState<Team | null>(null);

    if (isLoading) {
        return <span className="loading loading-spinner loading-lg"></span>;
    }

    if (error || !mandant) {
        return (
            <div role="alert" className="alert alert-error">
                <span>{i18n._(t`Mandant konnte nicht geladen werden.`)}</span>
            </div>
        );
    }

    const handleUpdateMandant = async (values: MandantFormValues, smtpCleared: boolean) => {
        setSubmitError(null);
        try {
            await updateMandant(mandantId, buildMandantPayload(values, { clearSmtp: smtpCleared }));
            await mutate();
        } catch (err) {
            setSubmitError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Mandant konnte nicht gespeichert werden.`),
            );
        }
    };

    const handleDeleteMandant = async () => {
        if (!window.confirm(i18n._(t`Mandant wirklich löschen?`))) return;
        setSubmitError(null);
        try {
            await deleteMandant(mandantId);
            await globalMutate('/api/admin/mandants');
            navigate('/admin/mandants');
        } catch (err) {
            setSubmitError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Mandant konnte nicht gelöscht werden.`),
            );
        }
    };

    const handleAddDomain = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = event.currentTarget;
        const formData = new FormData(form);
        const hostname = String(formData.get('hostname') ?? '').trim();
        if (hostname === '') return;

        setDomainError(null);
        try {
            await addDomain(mandantId, hostname);
            form.reset();
            await mutateDomains();
        } catch (err) {
            setDomainError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Domain konnte nicht hinzugefügt werden.`),
            );
        }
    };

    const handleRemoveDomain = async (domain: MandantDomain) => {
        setDomainError(null);
        try {
            await removeDomain(mandantId, domain.id);
            await mutateDomains();
        } catch (err) {
            setDomainError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Domain konnte nicht entfernt werden.`),
            );
        }
    };

    const handleSaveTeam = async (values: TeamFormValues) => {
        setTeamFormError(null);
        try {
            if (editingTeam) {
                await updateTeam(mandantId, editingTeam.id, buildTeamPayload(values));
            } else {
                await createTeam(mandantId, buildTeamPayload(values));
            }
            setEditingTeam(null);
            setShowTeamForm(false);
            await mutateTeams();
        } catch (err) {
            setTeamFormError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Team konnte nicht gespeichert werden.`),
            );
        }
    };

    const handleDeleteTeam = async (team: Team) => {
        setTeamFormError(null);
        try {
            await deleteTeam(mandantId, team.id);
            if (editingTeam?.id === team.id) {
                setEditingTeam(null);
                setShowTeamForm(false);
            }
            await mutateTeams();
        } catch (err) {
            setTeamFormError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Team konnte nicht gelöscht werden.`),
            );
        }
    };

    const closeTeamForm = () => {
        setEditingTeam(null);
        setShowTeamForm(false);
        setTeamFormError(null);
    };

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">{mandant.name}</h1>
                    <Link to="/admin/mandants" className="link link-sm">
                        {i18n._(t`Zurück zur Mandantenliste`)}
                    </Link>
                </div>
                {mandant.is_primary ? (
                    <span className="badge badge-outline badge-sm">{i18n._(t`Primär`)}</span>
                ) : null}
            </div>

            <section className="card bg-base-200 p-6">
                <h2 className="text-xl font-semibold">{i18n._(t`Einstellungen`)}</h2>
                <div className="mt-4">
                    <MandantForm
                        initial={mandant}
                        isEdit
                        submitLabel={i18n._(t`Speichern`)}
                        submitError={submitError}
                        onSubmit={handleUpdateMandant}
                    />
                </div>
            </section>

            <section className="card bg-base-200 p-6">
                <h2 className="text-xl font-semibold">{i18n._(t`Logo & Header`)}</h2>
                <div className="mt-4 grid gap-6 md:grid-cols-2">
                    <MediaField
                        label={i18n._(t`Logo`)}
                        url={mandant.logo_url}
                        onUpload={async (file) => {
                            await uploadLogo(mandantId, file);
                            await mutate();
                        }}
                        onDelete={async () => {
                            await deleteLogo(mandantId);
                            await mutate();
                        }}
                    />
                    <MediaField
                        label={i18n._(t`Header`)}
                        url={mandant.header_url}
                        onUpload={async (file) => {
                            await uploadHeader(mandantId, file);
                            await mutate();
                        }}
                        onDelete={async () => {
                            await deleteHeader(mandantId);
                            await mutate();
                        }}
                    />
                </div>
            </section>

            <section className="card bg-base-200 p-6">
                <h2 className="text-xl font-semibold">{i18n._(t`Domains`)}</h2>
                <form className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end" onSubmit={handleAddDomain}>
                    <div className="form-control flex-1">
                        <label className="label" htmlFor="domain-hostname">
                            <span className="label-text">{i18n._(t`Domain`)}</span>
                        </label>
                        <input
                            id="domain-hostname"
                            name="hostname"
                            type="text"
                            className="input"
                            placeholder="example.test"
                            required
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <button type="submit" className="btn btn-primary">
                            {i18n._(t`Domain hinzufügen`)}
                        </button>
                    </div>
                </form>
                {domainError ? <span className="mt-2 block text-sm text-error">{domainError}</span> : null}
                <ul className="mt-4 flex flex-col gap-2">
                    {(domains ?? []).map((domain) => (
                        <li
                            key={domain.id}
                            className="flex flex-wrap items-center justify-between gap-2 rounded-box bg-base-100 p-3"
                        >
                            <code>{domain.hostname}</code>
                            <button
                                type="button"
                                className="btn btn-sm btn-error btn-outline"
                                onClick={() => void handleRemoveDomain(domain)}
                            >
                                {i18n._(t`Entfernen`)}
                            </button>
                        </li>
                    ))}
                </ul>
                {domains && domains.length === 0 ? (
                    <p className="mt-4 text-base-content/70">{i18n._(t`Noch keine Domains vorhanden.`)}</p>
                ) : null}
            </section>

            <section className="card bg-base-200 p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h2 className="text-xl font-semibold">{i18n._(t`Teams`)}</h2>
                    {mandant.teams_enabled ? (
                        <button type="button" className="btn btn-sm" onClick={() => setShowTeamForm(true)}>
                            {i18n._(t`Team hinzufügen`)}
                        </button>
                    ) : null}
                </div>

                {mandant.teams_enabled ? (
                    <>
                        {showTeamForm ? (
                            <TeamForm
                                key={editingTeam?.id ?? 'new'}
                                initial={editingTeam}
                                submitLabel={i18n._(t`Team speichern`)}
                                submitError={teamFormError}
                                onSubmit={handleSaveTeam}
                                onCancel={closeTeamForm}
                            />
                        ) : null}

                        <ul className="mt-4 flex flex-col gap-2">
                            {(teams ?? []).map((team) => (
                                <li
                                    key={team.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-box bg-base-100 p-3"
                                >
                                    <div>
                                        <span className="font-medium">{team.name}</span>
                                        <span className="ml-2 text-sm text-base-content/70">({team.slug})</span>
                                        {team.home_venue ? (
                                            <span className="ml-2 text-sm text-base-content/70">{team.home_venue}</span>
                                        ) : null}
                                    </div>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline"
                                            onClick={() => {
                                                setEditingTeam(team);
                                                setShowTeamForm(true);
                                            }}
                                        >
                                            {i18n._(t`Bearbeiten`)}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-error btn-outline"
                                            onClick={() => void handleDeleteTeam(team)}
                                        >
                                            {i18n._(t`Löschen`)}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        {teams && teams.length === 0 ? (
                            <p className="mt-4 text-base-content/70">{i18n._(t`Noch keine Teams vorhanden.`)}</p>
                        ) : null}
                    </>
                ) : (
                    <p className="mt-2 text-base-content/70">{i18n._(t`Teams deaktiviert`)}</p>
                )}
            </section>

            <section className="flex justify-end">
                <button type="button" className="btn btn-error" onClick={() => void handleDeleteMandant()}>
                    {i18n._(t`Mandant löschen`)}
                </button>
            </section>
        </section>
    );
}
