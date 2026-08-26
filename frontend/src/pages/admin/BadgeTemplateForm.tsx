import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import type { BadgeTemplate } from '../../api/types';
import { BadgeCanvas } from './BadgeCanvas';
import { BadgePropertiesPanel } from './BadgePropertiesPanel';
import {
    BADGE_ENTRY_KEYS,
    badgeFieldLabel,
    badgeTemplateFormDefaults,
    createBadgeTemplateSchema,
    createDefaultBadgeRow,
    isSpecialEntry,
    type BadgeEntryKey,
    type BadgeTemplateFormValues,
} from './badgeTemplateFormUtils';

interface BadgeTemplateFormProps {
    initial: BadgeTemplate | null;
    submitLabel: string;
    submitError: string | null;
    onSubmit: (values: BadgeTemplateFormValues) => Promise<void>;
    onCancel: () => void;
}

/**
 * Badge template editor (schema v2 basis UI — FE2, features/badge-template-
 * editor.md): element palette + mm-scaled A6 canvas with selectable boxes +
 * a properties panel. Drag & drop arrives with FE3; positions are edited
 * canonically via the panel's number inputs. State stays in react-hook-form
 * (single source of truth), validation mirrors the server-authoritative
 * schema v2 rules.
 */
export function BadgeTemplateForm({ initial, submitLabel, submitError, onSubmit, onCancel }: BadgeTemplateFormProps) {
    const { i18n } = useLingui();
    const schema = createBadgeTemplateSchema();
    const [selectedIndex, setSelectedIndex] = useState<number | null>(null);

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
    const selectedRow = selectedIndex !== null ? fieldRows[selectedIndex] : undefined;
    const qrExists = fieldRows.some((row) => row.field === 'qr');

    const handleAddField = (key: BadgeEntryKey) => {
        if (isSpecialEntry(key) && key === 'qr' && qrExists) return;
        const rows = [...fieldRows, createDefaultBadgeRow(key, fieldRows)];
        setValue('fields', rows, { shouldValidate: true });
        setSelectedIndex(rows.length - 1);
    };

    const handleRemoveSelected = () => {
        if (selectedIndex === null) return;
        setValue(
            'fields',
            fieldRows.filter((_, rowIndex) => rowIndex !== selectedIndex),
            { shouldValidate: true },
        );
        setSelectedIndex(null);
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

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="flex flex-col items-center gap-3 lg:col-span-2">
                    <fieldset className="w-full">
                        <legend className="mb-1 text-xs font-medium uppercase tracking-wide text-base-content/60">
                            {i18n._(t`Elemente`)}
                        </legend>
                        <div className="flex flex-wrap gap-1.5">
                            {BADGE_ENTRY_KEYS.map((key) => (
                                <button
                                    key={key}
                                    type="button"
                                    className="btn btn-outline btn-xs"
                                    disabled={key === 'qr' && qrExists}
                                    title={key === 'qr' && qrExists ? i18n._(t`Es darf nur einen QR-Code geben.`) : undefined}
                                    onClick={() => handleAddField(key)}
                                >
                                    <span className="iconify mdi--plus text-sm"></span>
                                    {badgeFieldLabel(key, i18n)}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <div className="w-full max-w-xs">
                        <BadgeCanvas rows={fieldRows} selectedIndex={selectedIndex} onSelect={setSelectedIndex} />
                    </div>
                    <p className="text-center text-xs text-base-content/60">
                        {i18n._(t`Live-Vorschau (DIN A6, Maße in mm)`)}
                    </p>

                    {errors.fields?.message ? (
                        <p role="alert" className="text-sm text-error">
                            {errors.fields.message}
                        </p>
                    ) : null}
                </div>

                <BadgePropertiesPanel
                    index={selectedIndex}
                    row={selectedRow}
                    errors={errors}
                    register={register}
                    setValue={setValue}
                    onDelete={handleRemoveSelected}
                />
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
