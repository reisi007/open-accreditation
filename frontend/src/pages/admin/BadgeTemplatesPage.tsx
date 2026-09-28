import { msg, t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { Fragment, useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    createBadgeTemplate,
    deleteBadgeTemplate,
    listBadgeTemplates,
    updateBadgeTemplate,
} from '../../api/client';
import type { BadgeTemplate } from '../../api/types';
import { Modal } from '../../components/Modal';
import { BadgeTemplateForm } from './BadgeTemplateForm';
import { buildBadgeTemplatePayload, type BadgeTemplateFormValues } from './badgeTemplateFormUtils';

const PAGE_SIZE = 20;

function firstErrorMessage(err: unknown, fallback: string): string {
    return err instanceof ApiError ? err.message : fallback;
}

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

/**
 * One row's edit/delete pair. Rendered TWICE per row on purpose — once inside
 * the `Aktionen` cell for `lg` and up, once in a dedicated full-width row below
 * the data on mobile (P6). Both instances are real buttons with real handlers,
 * so the accessible names the E2E specs look up are present at every viewport;
 * exactly one of the two is visible at a time, which keeps the a11y tree free
 * of duplicate "Bearbeiten" controls.
 */
function TemplateRowActions({ onEdit, onDelete }: { onEdit: () => void; onDelete: () => void }) {
    const { i18n } = useLingui();

    return (
        <div className="flex gap-2">
            <button type="button" className="btn btn-sm btn-outline" onClick={onEdit}>
                {i18n._(t`Bearbeiten`)}
            </button>
            <button type="button" className="btn btn-sm btn-error btn-outline" onClick={onDelete}>
                {i18n._(t`Löschen`)}
            </button>
        </div>
    );
}

export function BadgeTemplatesPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading, mutate } = useSWR<BadgeTemplate[]>('/api/admin/badge-templates', () =>
        listBadgeTemplates(),
    );

    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [formTemplate, setFormTemplate] = useState<BadgeTemplate | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const totalCount = data?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders alphabetically, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedTemplates = [...(data ?? [])].sort((a, b) => b.id - a.id);
    const pagedTemplates = orderedTemplates.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

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

            {data && !isLoading && !error ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {i18n._({
                                ...msg`{totalCount, plural, one {# Ausweis-Template} other {# Ausweis-Templates}}`,
                                values: { totalCount },
                            })}
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
                                <table className="table">
                                        <thead>
                                            <tr>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Name`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Standard`)}</th>
                                                {/*
                                                  `whitespace-nowrap` on the header
                                                  AND the cell: the plural label
                                                  ("9 Felder") is a badge whose own
                                                  text wrapped, splitting a
                                                  two-word count across two lines
                                                  and misaligning the row. Same
                                                  reason as the date columns.
                                                */}
                                                <th className="sticky top-0 z-10 whitespace-nowrap bg-base-100">
                                                    {i18n._(t`Felder`)}
                                                </th>
                                                {/*
                                                  P6. The actions column is the
                                                  ONE column whose loss blocks the
                                                  page's purpose: the name and the
                                                  field count are readable without
                                                  it, but a template that cannot
                                                  be edited or deleted on a phone
                                                  cannot be managed there at all.

                                                  `hidden lg:table-cell` on this
                                                  header plus the cells below
                                                  removes the column from the
                                                  horizontally scrolling table on
                                                  mobile, and the buttons are
                                                  re-rendered underneath each row
                                                  (`TemplateRowActions`), which
                                                  is reachable without any
                                                  sideways movement. The scroll
                                                  hint stays for the DATA columns,
                                                  which are still meant to be
                                                  swiped.
                                                */}
                                                <th className="sticky top-0 z-10 hidden bg-base-100 lg:table-cell">
                                                    {i18n._(t`Aktionen`)}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedTemplates.map((template) => {
                                                const fieldCount = template.layout.length;
                                                return (
                                                    /*
                                                      A table row cannot host a
                                                      block below its own cells, so
                                                      the mobile action row is a
                                                      SECOND `<tr>` with a single
                                                      full-width `<td>`. Splitting
                                                      it out is what keeps the
                                                      buttons inside the viewport
                                                      instead of behind the
                                                      horizontal scroll: a `tr`
                                                      with `colSpan` occupies the
                                                      table's full width, and the
                                                      table itself is only as wide
                                                      as its remaining data
                                                      columns at this viewport.
                                                    */
                                                    <Fragment key={template.id}>
                                                    <tr>
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
                                                        <td className="whitespace-nowrap">
                                                            <span className="badge badge-ghost badge-sm">
                                                                {i18n._({
                                                                    ...msg`{fieldCount, plural, one {# Feld} other {# Felder}}`,
                                                                    values: { fieldCount },
                                                                })}
                                                            </span>
                                                        </td>
                                                        <td className="hidden lg:table-cell">
                                                            <TemplateRowActions
                                                                onEdit={() => openEdit(template)}
                                                                onDelete={() => void handleDelete(template)}
                                                            />
                                                        </td>
                                                    </tr>
                                                    <tr className="lg:hidden">
                                                        {/*
                                                          `w-full` is the load-bearing
                                                          class: a `<td>` in a table
                                                          row is sized by the COLUMN,
                                                          and a single-cell row still
                                                          gets the width of the widest
                                                          column — which is the one
                                                          that overflows. `w-full`
                                                          resolves it against the
                                                          table's own width, so the
                                                          buttons land inside the
                                                          viewport instead of behind
                                                          the horizontal scroll.
                                                        */}
                                                        <td className="w-full border-t-0 pb-4">
                                                            <TemplateRowActions
                                                                onEdit={() => openEdit(template)}
                                                                onDelete={() => void handleDelete(template)}
                                                            />
                                                        </td>
                                                    </tr>
                                                    </Fragment>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                            </div>
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
                        </div>
                        <MobileScrollHint />
                    </div>
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
                <Modal boxClassName="max-w-5xl" onClose={closeForm}>
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
                </Modal>
            ) : null}
        </section>
    );
}
