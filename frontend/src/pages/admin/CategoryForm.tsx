import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState, type ChangeEvent } from 'react';
import { useForm } from 'react-hook-form';
import type { Category } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { categoryFormDefaults, createCategorySchema, type CategoryFormValues } from './categoryFormUtils';

interface CategoryFormProps {
    initial: Category | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: CategoryFormValues) => Promise<void>;
    onCancel: () => void;
}

export function CategoryForm({ initial, submitLabel, submitError, onSubmit, onCancel }: CategoryFormProps) {
    const { i18n } = useLingui();
    const { teams } = useAdminTeams();
    const categorySchema = createCategorySchema();
    const [isTeamLevel, setIsTeamLevel] = useState(() => initial?.team_id !== null && initial?.team_id !== undefined);

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<CategoryFormValues>({
        resolver: zodResolver(categorySchema),
        defaultValues: categoryFormDefaults(initial),
    });

    const showTeamSelect = teams !== undefined;

    const handleTeamChange = (event: ChangeEvent<HTMLSelectElement>) => {
        setIsTeamLevel(event.target.value !== '');
    };

    const teamSelectRegister = register('team_id', { onChange: handleTeamChange });

    return (
        <form
            className="flex flex-col gap-4"
            noValidate
            onSubmit={handleSubmit(async (values) => {
                await onSubmit(values);
            })}
        >
            {submitError ? (
                <div role="alert" className="alert alert-error">
                    <span>{submitError}</span>
                </div>
            ) : null}

            <div className="form-control">
                <label className="label" htmlFor="category-name">
                    <span className="label-text">{i18n._(t`Name`)}</span>
                </label>
                <input
                    id="category-name"
                    type="text"
                    className={`input ${errors.name ? 'input-error' : ''}`}
                    {...register('name')}
                    required
                />
                {errors.name ? <span className="label-text-alt mt-1 text-error">{errors.name.message}</span> : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="category-slug">
                    <span className="label-text">{i18n._(t`Slug`)}</span>
                </label>
                <input
                    id="category-slug"
                    type="text"
                    className={`input ${errors.slug ? 'input-error' : ''}`}
                    {...register('slug')}
                    required
                />
                <span className="label-text-alt mt-1 text-base-content/70">
                    {i18n._(t`Erlaubt sind Kleinbuchstaben, Zahlen und Bindestriche.`)}
                </span>
                {errors.slug ? <span className="label-text-alt text-error">{errors.slug.message}</span> : null}
            </div>

            <div className="form-control">
                <label className="label" htmlFor="category-description">
                    <span className="label-text">{i18n._(t`Beschreibung`)}</span>
                </label>
                <textarea
                    id="category-description"
                    className="textarea"
                    rows={3}
                    {...register('description')}
                />
            </div>

            {showTeamSelect ? (
                <div className="form-control">
                    <label className="label" htmlFor="category-team">
                        <span className="label-text">{i18n._(t`Team`)}</span>
                    </label>
                    <select
                        id="category-team"
                        className={`select ${errors.team_id ? 'select-error' : ''}`}
                        {...teamSelectRegister}
                    >
                        <option value="">{i18n._(t`Verbandsebene (Mandant)`)}</option>
                        {(teams ?? []).map((team) => (
                            <option key={team.id} value={String(team.id)}>
                                {team.name}
                            </option>
                        ))}
                    </select>
                    {isTeamLevel ? (
                        <span className="label-text-alt mt-1 text-base-content/70">
                            {i18n._(t`Diese Kategorie gilt nur für das gewählte Team und überschreibt die Verbandsebene.`)}
                        </span>
                    ) : null}
                </div>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {submitLabel}
                </button>
                <button type="button" className="btn" onClick={onCancel}>
                    {i18n._(t`Abbrechen`)}
                </button>
            </div>
        </form>
    );
}
