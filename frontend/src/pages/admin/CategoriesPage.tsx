import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import { ApiError, createCategory, deleteCategory, listCategories, updateCategory } from '../../api/client';
import type { Category } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { CategoryForm } from './CategoryForm';
import { buildCategoryPayload, type CategoryFormValues } from './categoryFormUtils';

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

export function CategoriesPage() {
    const { i18n } = useLingui();
    const { currentTeamIds } = useAdminTeams();
    const { data: categories, error, isLoading, mutate } = useSWR<Category[]>('/api/admin/categories', () =>
        listCategories(),
    );

    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [formCategory, setFormCategory] = useState<Category | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const isTeamScoped = currentTeamIds.length > 0;
    const isReadOnly = (category: Category) =>
        isTeamScoped && (category.team_id === null || !currentTeamIds.includes(category.team_id));

    const totalCount = categories?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders alphabetically, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedCategories = [...(categories ?? [])].sort((a, b) => b.id - a.id);
    const pagedCategories = orderedCategories.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

    const openNew = () => {
        setFormCategory(null);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const openEdit = (category: Category) => {
        setFormCategory(category);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setFormCategory(null);
        setFormError(null);
    };

    const handleSave = async (values: CategoryFormValues) => {
        setFormError(null);
        try {
            if (formCategory) {
                await updateCategory(formCategory.id, buildCategoryPayload(values));
            } else {
                await createCategory(buildCategoryPayload(values));
            }
            await mutate();
            closeForm();
        } catch (err) {
            setFormError(
                err instanceof ApiError ? err.message : i18n._(t`Kategorie konnte nicht gespeichert werden.`),
            );
        }
    };

    const handleDelete = async (category: Category) => {
        if (!window.confirm(i18n._(t`Kategorie wirklich löschen?`))) return;
        setListError(null);
        try {
            await deleteCategory(category.id);
            await mutate();
        } catch (err) {
            setListError(
                err instanceof ApiError ? err.message : i18n._(t`Kategorie konnte nicht gelöscht werden.`),
            );
        }
    };

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Kategorien`)}</h1>
                <button type="button" className="btn btn-primary" onClick={openNew}>
                    <span className="iconify mdi--plus text-xl"></span>
                    {i18n._(t`Neu`)}
                </button>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Kategorien konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {categories && !isLoading && !error ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {totalCount === 1 ? '1 Kategorie' : `${totalCount} Kategorien`}
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
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Name`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Slug`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Ebene`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Aktionen`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedCategories.map((category) => (
                                                <tr key={category.id}>
                                                    <td className="font-medium">{category.name}</td>
                                                    <td>
                                                        <code>{category.slug}</code>
                                                    </td>
                                                    <td>
                                                        <div className="flex flex-wrap gap-1">
                                                            {category.team ? (
                                                                <span className="badge badge-outline badge-sm">{category.team.name}</span>
                                                            ) : (
                                                                <span className="badge badge-ghost badge-sm">
                                                                    {i18n._(t`Verbandsebene`)}
                                                                </span>
                                                            )}
                                                            {category.is_team_override ? (
                                                                <span className="badge badge-warning badge-sm">
                                                                    {i18n._(t`Team-Override`)}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td>
                                                        {isReadOnly(category) ? null : (
                                                            <div className="flex gap-2">
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline"
                                                                    onClick={() => openEdit(category)}
                                                                >
                                                                    {i18n._(t`Bearbeiten`)}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-error btn-outline"
                                                                    onClick={() => void handleDelete(category)}
                                                                >
                                                                    {i18n._(t`Löschen`)}
                                                                </button>
                                                            </div>
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

            {categories && categories.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--shape-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Kategorien vorhanden.`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Lege die erste Kategorie an, damit Akkreditierungen erstellt werden können.`)}
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
                    <div className="modal-box">
                        <h3 className="text-lg font-bold">
                            {formCategory ? i18n._(t`Kategorie bearbeiten`) : i18n._(t`Neue Kategorie`)}
                        </h3>
                        <div className="mt-4">
                            <CategoryForm
                                initial={formCategory}
                                submitLabel={formCategory ? i18n._(t`Speichern`) : i18n._(t`Kategorie erstellen`)}
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
