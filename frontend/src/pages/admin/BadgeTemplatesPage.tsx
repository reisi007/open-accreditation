import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    createBadgeTemplate,
    deleteBadgeTemplate,
    listBadgeTemplates,
    updateBadgeTemplate,
} from '../../api/client';
import type { BadgeTemplate } from '../../api/types';
import { BadgeTemplateForm } from './BadgeTemplateForm';
import { buildBadgeTemplatePayload, type BadgeTemplateFormValues } from './badgeTemplateFormUtils';

function firstErrorMessage(err: unknown, fallback: string): string {
    return err instanceof ApiError ? err.message : fallback;
}

export function BadgeTemplatesPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading, mutate } = useSWR<BadgeTemplate[]>('/api/admin/badge-templates', () =>
        listBadgeTemplates(),
    );

    const [showForm, setShowForm] = useState(false);
    const [formTemplate, setFormTemplate] = useState<BadgeTemplate | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const openNew = () => {
        setFormTemplate(null);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const openEdit = (template: BadgeTemplate) => {
        setFormTemplate(template);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setFormTemplate(null);
        setFormError(null);
    };

    const handleSave = async (values: BadgeTemplateFormValues) => {
        setFormError(null);
        try {
            const payload = buildBadgeTemplatePayload(values);
            if (formTemplate) {
                await updateBadgeTemplate(formTemplate.id, payload);
            } else {
                await createBadgeTemplate(payload);
            }
            await mutate();
            closeForm();
        } catch (err) {
            setFormError(firstErrorMessage(err, i18n._(t`Template konnte nicht gespeichert werden.`)));
        }
    };

    const handleDelete = async (template: BadgeTemplate) => {
        if (!window.confirm(i18n._(t`Template wirklich löschen?`))) return;
        setListError(null);
        try {
            await deleteBadgeTemplate(template.id);
            await mutate();
        } catch (err) {
            setListError(firstErrorMessage(err, i18n._(t`Template konnte nicht gelöscht werden.`)));
        }
    };

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Ausweis-Templates`)}</h1>
                <button type="button" className="btn btn-primary" onClick={openNew}>
                    <span className="iconify mdi--plus text-xl"></span>
                    {i18n._(t`Neu`)}
                </button>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Templates konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {data && data.length > 0 && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Name`)}</th>
                                <th>{i18n._(t`Standard`)}</th>
                                <th>{i18n._(t`Felder`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((template) => {
                                const fieldCount = template.layout.length;
                                return (
                                    <tr key={template.id}>
                                        <td className="max-w-56">
                                            <div className="truncate font-medium" title={template.name}>
                                                {template.name}
                                            </div>
                                        </td>
                                        <td>
                                            {template.is_default ? (
                                                <span className="badge badge-success badge-sm">{i18n._(t`Standard`)}</span>
                                            ) : (
                                                <span className="text-base-content/40">—</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className="badge badge-ghost badge-sm">
                                                {i18n._(t`${fieldCount} Felder`)}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-outline"
                                                    onClick={() => openEdit(template)}
                                                >
                                                    {i18n._(t`Bearbeiten`)}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-error btn-outline"
                                                    onClick={() => void handleDelete(template)}
                                                >
                                                    {i18n._(t`Löschen`)}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--badge-account-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Ausweis-Templates`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Lege das erste Ausweis-Template an, um Ausweise zu drucken und zu verifizieren.`)}
                        </p>
                        <button type="button" className="btn btn-primary mt-2" onClick={openNew}>
                            <span className="iconify mdi--plus text-xl"></span>
                            {i18n._(t`Neu`)}
                        </button>
                    </div>
                </div>
            ) : null}

            {showForm ? (
                <dialog className="modal modal-open">
                    <div className="modal-box max-w-4xl">
                        <h3 className="text-lg font-bold">
                            {formTemplate ? i18n._(t`Template bearbeiten`) : i18n._(t`Neues Template`)}
                        </h3>
                        <div className="mt-4">
                            <BadgeTemplateForm
                                initial={formTemplate}
                                submitLabel={formTemplate ? i18n._(t`Speichern`) : i18n._(t`Template erstellen`)}
                                submitError={formError}
                                onSubmit={handleSave}
                                onCancel={closeForm}
                            />
                        </div>
                    </div>
                    <form method="dialog" className="modal-backdrop">
                        <button type="button" onClick={closeForm}>
                            {i18n._(t`Schließen`)}
                        </button>
                    </form>
                </dialog>
            ) : null}
        </section>
    );
}
