import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Link } from 'react-router-dom';
import useSWR from 'swr';
import { listMandants } from '../../api/client';
import type { Mandant } from '../../api/types';

export function MandantListPage() {
    const { i18n } = useLingui();
    const { data: mandants, error, isLoading } = useSWR<Mandant[]>('/api/admin/mandants', () => listMandants());

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Mandanten`)}</h1>
                <Link to="/admin/mandants/new" className="btn btn-primary">
                    <span className="iconify mdi--plus text-xl"></span>
                    {i18n._(t`Neu`)}
                </Link>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Mandanten konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {mandants && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Name`)}</th>
                                <th>{i18n._(t`Slug`)}</th>
                                <th>{i18n._(t`Domains`)}</th>
                                <th>{i18n._(t`Teams`)}</th>
                                <th>{i18n._(t`Status`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mandants.map((mandant) => (
                                <tr key={mandant.id}>
                                    <td>
                                        <Link to={`/admin/mandants/${mandant.id}`} className="link">
                                            {mandant.name}
                                        </Link>
                                    </td>
                                    <td>
                                        <code>{mandant.slug}</code>
                                    </td>
                                    <td>{(mandant.domains ?? []).map((domain) => domain.hostname).join(', ')}</td>
                                    <td>{mandant.teams_count}</td>
                                    <td>
                                        {mandant.is_active ? (
                                            <span className="badge badge-success badge-sm">{i18n._(t`Aktiv`)}</span>
                                        ) : (
                                            <span className="badge badge-ghost badge-sm">{i18n._(t`Inaktiv`)}</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {mandants && mandants.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Noch keine Mandanten vorhanden.`)}</p>
            ) : null}
        </section>
    );
}
