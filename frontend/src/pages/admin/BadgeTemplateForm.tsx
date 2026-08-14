import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useForm, useWatch } from 'react-hook-form';
import type { BadgeTemplate } from '../../api/types';
import { BadgePreview } from './BadgePreview';
import {
    BADGE_ALIGNS,
    BADGE_FIELD_KEYS,
    addBadgeFieldRow,
    badgeAlignLabel,
    badgeFieldLabel,
    badgeTemplateFormDefaults,
    createBadgeTemplateSchema,
    removeBadgeFieldRow,
    type BadgeTemplateFormValues,
} from './badgeTemplateFormUtils';

interface BadgeTemplateFormProps {
    initial: BadgeTemplate | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: BadgeTemplateFormValues) => Promise<void>;
    onCancel: () => void;
}

export function BadgeTemplateForm({ initial, submitLabel, submitError, onSubmit, onCancel }: BadgeTemplateFormProps) {
    const { i18n } = useLingui();
    const schema = createBadgeTemplateSchema();

    const {
        register,
        handleSubmit,
        control,
        setValue,
        formState: { errors, isSubmitting },
    } = useForm<BadgeTemplateFormValues>({
        resolver: zodResolver(schema),
        defaultValues: badgeTemplateFormDefaults(initial),
    });

    const fieldRows = useWatch<BadgeTemplateFormValues, 'fields'>({ control, name: 'fields' }) ?? [];

    const handleAddField = () => {
        setValue('fields', addBadgeFieldRow(fieldRows));
    };

    const handleRemoveField = (index: number) => {
        if (fieldRows.length <= 1) return;
        setValue('fields', removeBadgeFieldRow(fieldRows, index));
    };

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

            <div className="grid gap-4 md:grid-cols-2">
                <div className="form-control">
                    <label className="label" htmlFor="badge-template-name">
                        <span className="label-text">{i18n._(t`Name`)}</span>
                    </label>
                    <input
                        id="badge-template-name"
                        type="text"
                        className={`input ${errors.name ? 'input-error' : ''}`}
                        {...register('name')}
                        required
                    />
                    {errors.name ? <span className="label-text-alt mt-1 text-error">{errors.name.message}</span> : null}
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="badge-template-default">
                        <span className="label-text">{i18n._(t`Standard-Template`)}</span>
                    </label>
                    <input id="badge-template-default" type="checkbox" className="toggle" {...register('is_default')} />
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <div className="overflow-x-auto">
                    <table className="table table-sm">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Feld`)}</th>
                                <th>X</th>
                                <th>Y</th>
                                <th>W</th>
                                <th>H</th>
                                <th>{i18n._(t`Größe`)}</th>
                                <th>{i18n._(t`Ausrichtung`)}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {fieldRows.map((_, index) => (
                                <tr key={index}>
                                    <td>
                                        <select
                                            aria-label={i18n._(t`Feld Typ`)}
                                            className={`select select-sm ${errors.fields?.[index]?.field ? 'select-error' : ''}`}
                                            {...register(`fields.${index}.field`)}
                                        >
                                            {BADGE_FIELD_KEYS.map((key) => (
                                                <option key={key} value={key}>
                                                    {badgeFieldLabel(key, i18n)}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.fields?.[index]?.field ? (
                                            <span className="label-text-alt text-error">
                                                {errors.fields?.[index]?.field?.message}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="any"
                                            aria-label={i18n._(t`X (mm)`)}
                                            className={`input input-sm w-16 ${errors.fields?.[index]?.x ? 'input-error' : ''}`}
                                            {...register(`fields.${index}.x`, { valueAsNumber: true })}
                                            required
                                        />
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="any"
                                            aria-label={i18n._(t`Y (mm)`)}
                                            className={`input input-sm w-16 ${errors.fields?.[index]?.y ? 'input-error' : ''}`}
                                            {...register(`fields.${index}.y`, { valueAsNumber: true })}
                                            required
                                        />
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="any"
                                            aria-label={i18n._(t`Breite (mm)`)}
                                            className={`input input-sm w-16 ${errors.fields?.[index]?.w ? 'input-error' : ''}`}
                                            {...register(`fields.${index}.w`, { valueAsNumber: true })}
                                            required
                                        />
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="any"
                                            aria-label={i18n._(t`Höhe (mm)`)}
                                            className={`input input-sm w-16 ${errors.fields?.[index]?.h ? 'input-error' : ''}`}
                                            {...register(`fields.${index}.h`, { valueAsNumber: true })}
                                            required
                                        />
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="any"
                                            aria-label={i18n._(t`Schriftgröße (pt)`)}
                                            className={`input input-sm w-16 ${errors.fields?.[index]?.size ? 'input-error' : ''}`}
                                            {...register(`fields.${index}.size`, { valueAsNumber: true })}
                                            required
                                        />
                                    </td>
                                    <td>
                                        <select
                                            aria-label={i18n._(t`Ausrichtung`)}
                                            className={`select select-sm ${errors.fields?.[index]?.align ? 'select-error' : ''}`}
                                            {...register(`fields.${index}.align`)}
                                        >
                                            {BADGE_ALIGNS.map((align) => (
                                                <option key={align} value={align}>
                                                    {badgeAlignLabel(align, i18n)}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-ghost btn-sm"
                                            aria-label={i18n._(t`Feld entfernen`)}
                                            onClick={() => handleRemoveField(index)}
                                        >
                                            <span className="iconify mdi--trash-can-outline text-lg"></span>
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {errors.fields ? (
                        <p role="alert" className="mt-1 text-sm text-error">
                            {errors.fields.message}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col items-center gap-2 self-start lg:sticky lg:top-4">
                    <BadgePreview fields={fieldRows} />
                    <p className="text-center text-xs text-base-content/60">
                        {i18n._(t`Live-Vorschau (A6, Maße in mm)`)}
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <button type="button" className="btn btn-sm btn-outline" onClick={handleAddField}>
                    <span className="iconify mdi--plus text-xl"></span>
                    {i18n._(t`Feld hinzufügen`)}
                </button>
            </div>

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
