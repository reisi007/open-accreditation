import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import useSWR from 'swr';
import {
    ApiError,
    deleteMyHeader,
    deleteMyLogo,
    getPortalOverview,
    uploadMyHeader,
    uploadMyLogo,
} from '../../api/client';
import type { PortalOverview } from '../../api/types';
import { MediaField } from '../../components/MediaField';

export function MandantMediaPage() {
    const { i18n } = useLingui();
    const { data, error, mutate } = useSWR<PortalOverview>('/api/portal/overview', () => getPortalOverview());

    if (error) {
        const noMandantContext = error instanceof ApiError && error.status === 404;
        return (
            <div role="alert" className="alert alert-error">
                <span>
                    {noMandantContext
                        ? i18n._(t`Kein Mandanten-Kontext vorhanden.`)
                        : i18n._(t`Portaldaten konnten nicht geladen werden.`)}
                </span>
            </div>
        );
    }

    if (!data) {
        return <span className="loading loading-spinner loading-lg"></span>;
    }

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Logo & Header`)}</h1>
            <section className="card bg-base-200 p-6">
                <div className="grid gap-6 md:grid-cols-2">
                    <MediaField
                        label={i18n._(t`Logo`)}
                        url={data.mandant.logo_url}
                        onUpload={async (file) => {
                            await uploadMyLogo(file);
                            await mutate();
                        }}
                        onDelete={async () => {
                            await deleteMyLogo();
                            await mutate();
                        }}
                    />
                    <MediaField
                        label={i18n._(t`Header`)}
                        url={data.mandant.header_url}
                        onUpload={async (file) => {
                            await uploadMyHeader(file);
                            await mutate();
                        }}
                        onDelete={async () => {
                            await deleteMyHeader();
                            await mutate();
                        }}
                    />
                </div>
            </section>
        </section>
    );
}
