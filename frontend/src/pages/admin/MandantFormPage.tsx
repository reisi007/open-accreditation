import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { mutate as globalMutate } from 'swr';
import { ApiError, createMandant } from '../../api/client';
import { MandantForm } from './MandantForm';
import { buildMandantPayload, type MandantFormValues } from './mandantFormUtils';

export function MandantFormPage() {
    const { i18n } = useLingui();
    const navigate = useNavigate();
    const [submitError, setSubmitError] = useState<string | null>(null);

    const handleSubmit = async (values: MandantFormValues) => {
        setSubmitError(null);
        try {
            const mandant = await createMandant(buildMandantPayload(values));
            await globalMutate('/api/admin/mandants');
            navigate(`/admin/mandants/${mandant.id}`);
        } catch (error) {
            setSubmitError(
                error instanceof ApiError
                    ? error.message
                    : i18n._(t`Mandant konnte nicht erstellt werden.`),
            );
        }
    };

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Neuer Mandant`)}</h1>
            <MandantForm
                initial={null}
                isEdit={false}
                submitLabel={i18n._(t`Mandant erstellen`)}
                submitError={submitError}
                onSubmit={handleSubmit}
                onCancel={() => navigate('/admin/mandants')}
            />
        </section>
    );
}
