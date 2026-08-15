import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import useSWR from 'swr';
import { listMandants } from '../../api/client';
import type { Mandant } from '../../api/types';

const PAGE_SIZE = 20;

/**
 * Wide tables scroll horizontally by design. On mobile there is no native
 * scroll affordance, so a subtle right-edge fade (over the container) plus a
 * one-line hint shows that more columns are reachable by swiping. Desktop
 * keeps the default scrollbar.
 */
function MobileScrollHint() {
    const { i18n } = useLingui();

    return (
        <p className="mt-2 flex items-center gap-1 text-sm text-base-content/60 lg:hidden">
            <span className="iconify mdi--gesture-swipe-horizontal text-lg"></span>
            {i18n._(t`Zum Scrollen wischen`)}
        </p>
    );
}

export function MandantListPage() {
    const { i18n } = useLingui();
    const { data: mandants, error, isLoading } = useSWR<Mandant[]>('/api/admin/mandants', () => listMandants());
    const [page, setPage] = useState(1);

    const totalCount = mandants?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders alphabetically, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedMandants = [...(mandants ?? [])].sort((a, b) => b.id - a.id);
    const pagedMandants = orderedMandants.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

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
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {totalCount === 1 ? '1 Mandant' : `${totalCount} Mandanten`}
                        </p>
                        {pageCount > 1 ? (
                            <div className="join" role="group" aria-label={i18n._(t`Seitennavigation`)}>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage <= 1}
                                    onClick={() => setPage((previous) => Math.max(1, previous - 1))}
                                >
                                    {i18n._(t`Zurück`)}
                                </button>
                                <span className="join-item btn btn-sm btn-disabled" aria-live="polite">
                                    {i18n._(t`Seite ${currentPage} von ${pageCount}`)}
                                </span>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage >= pageCount}
                                    onClick={() => setPage((previous) => Math.min(pageCount, previous + 1))}
                                >
                                    {i18n._(t`Weiter`)}
                                </button>
                            </div>
                        ) : null}
                    </div>
                    <div className="flex flex-col">
                        <div className="relative">
                            <div className="overflow-x-auto">
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="table">
                                        <thead>
                                            <tr>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Logo`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Name`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Slug`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Domains`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Teams`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Status`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedMandants.map((mandant) => (
                                                <tr key={mandant.id}>
                                                    <td>
                                                        {mandant.logo_url ? (
                                                            <img
                                                                src={mandant.logo_url}
                                                                alt=""
                                                                className="h-10 w-10 rounded object-cover"
                                                            />
                                                        ) : (
                                                            <span>—</span>
                                                        )}
                                                    </td>
                                                    <td>
                                                        <Link to={`/admin/mandants/${mandant.id}`} className="link">
                                                            {mandant.name}
                                                        </Link>
                                                    </td>
                                                    <td>
                                                        <code>{mandant.slug}</code>
                                                    </td>
                                                    <td>
                                                        {(mandant.domains ?? []).map((domain, index) => (
                                                            <span key={domain.id}>
                                                                {index > 0 ? ', ' : null}
                                                                <a
                                                                    href={`http://${domain.hostname}`}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                >
                                                                    {domain.hostname}
                                                                    <span className="iconify mdi--open-in-new text-sm"></span>
                                                                </a>
                                                            </span>
                                                        ))}
                                                    </td>
                                                    <td>{mandant.teams_count}</td>
                                                    <td>
                                                        {mandant.is_active ? (
                                                            <span className="badge badge-success badge-sm">
                                                                {i18n._(t`Aktiv`)}
                                                            </span>
                                                        ) : (
                                                            <span className="badge badge-ghost badge-sm">
                                                                {i18n._(t`Inaktiv`)}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
                        </div>
                        <MobileScrollHint />
                    </div>
                </div>
            ) : null}

            {mandants && mandants.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Noch keine Mandanten vorhanden.`)}</p>
            ) : null}
        </section>
    );
}
