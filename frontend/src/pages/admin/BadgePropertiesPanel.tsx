import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    listBadgeImages,
    uploadBadgeImage,
} from '../../api/client';
import type { FieldErrors, UseFormRegister, UseFormSetValue } from 'react-hook-form';
import {
    BADGE_ALIGNS,
    BADGE_IMAGE_FITS,
    BADGE_IMAGE_REFS,
    MAX_FONT_SIZE_PT,
    MIN_FONT_SIZE_PT,
    badgeAlignLabel,
    badgeFieldLabel,
    badgeFitLabel,
    badgeImageRefLabel,
    showsTypography,
    type BadgeTemplateFormValues,
} from './badgeTemplateFormUtils';

/**
 * Properties panel of the badge template editor: numeric mm inputs for the
 * selected canvas box plus the type-specific sections (typography for data
 * fields, source union + fit for `image`; `qr` carries geometry only).
 */
interface BadgePropertiesPanelProps {
    index: number | null;
    row: BadgePropertiesRow | undefined;
    errors: FieldErrors<BadgeTemplateFormValues>;
    register: UseFormRegister<BadgeTemplateFormValues>;
    setValue: UseFormSetValue<BadgeTemplateFormValues>;
    onDelete: () => void;
}

/** The row slice the panel actually needs (keeps the props surface narrow). */
type BadgePropertiesRow = BadgeTemplateFormValues['fields'][number];

export function BadgePropertiesPanel({
    index,
    row,
    errors,
    register,
    setValue,
    onDelete,
}: BadgePropertiesPanelProps) {
    const { i18n } = useLingui();

    if (index === null || !row) {
        return (
            <aside className="card border border-base-300 bg-base-100 self-start">
                <div className="card-body gap-2 p-4 text-sm text-base-content/70">
                    <h3 className="card-title text-base">{i18n._(t`Eigenschaften`)}</h3>
                    <p>{i18n._(t`Kein Feld ausgewählt. Klicke ein Feld auf der Vorschau an.`)}</p>
                </div>
            </aside>
        );
    }

    const rowErrors = errors.fields?.[index];
    const errorClass = (hasError: { message?: string } | undefined): string =>
        `input input-sm w-full ${hasError ? 'input-error' : ''}`;

    return (
        <aside className="card border border-base-300 bg-base-100 self-start">
            <div className="card-body gap-3 p-4">
                <h3 className="card-title text-base">{i18n._(t`Eigenschaften`)}</h3>
                <p className="-mt-2 text-xs font-medium uppercase tracking-wide text-base-content/60">
                    {badgeFieldLabel(row.field, i18n)}
                </p>

                <div className="grid grid-cols-2 gap-2">
                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-x`}>
                            <span className="label-text">{i18n._(t`X (mm)`)}</span>
                        </label>
                        <input
                            id={`badge-field-${index}-x`}
                            type="number"
                            step="any"
                            className={errorClass(rowErrors?.x)}
                            {...register(`fields.${index}.x`, { valueAsNumber: true })}
                            required
                        />
                        {rowErrors?.x ? (
                            <span className="label-text-alt mt-1 text-error">{rowErrors.x.message}</span>
                        ) : null}
                    </div>
                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-y`}>
                            <span className="label-text">{i18n._(t`Y (mm)`)}</span>
                        </label>
                        <input
                            id={`badge-field-${index}-y`}
                            type="number"
                            step="any"
                            className={errorClass(rowErrors?.y)}
                            {...register(`fields.${index}.y`, { valueAsNumber: true })}
                            required
                        />
                        {rowErrors?.y ? (
                            <span className="label-text-alt mt-1 text-error">{rowErrors.y.message}</span>
                        ) : null}
                    </div>
                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-w`}>
                            <span className="label-text">{i18n._(t`Breite (mm)`)}</span>
                        </label>
                        <input
                            id={`badge-field-${index}-w`}
                            type="number"
                            step="any"
                            className={errorClass(rowErrors?.w)}
                            {...register(`fields.${index}.w`, { valueAsNumber: true })}
                            required
                        />
                        {rowErrors?.w ? (
                            <span className="label-text-alt mt-1 text-error">{rowErrors.w.message}</span>
                        ) : null}
                    </div>
                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-h`}>
                            <span className="label-text">{i18n._(t`Höhe (mm)`)}</span>
                        </label>
                        <input
                            id={`badge-field-${index}-h`}
                            type="number"
                            step="any"
                            className={errorClass(rowErrors?.h)}
                            {...register(`fields.${index}.h`, { valueAsNumber: true })}
                            required
                        />
                        {rowErrors?.h ? (
                            <span className="label-text-alt mt-1 text-error">{rowErrors.h.message}</span>
                        ) : null}
                    </div>
                </div>

                {showsTypography(row.field) ? (
                    <div className="grid grid-cols-2 gap-2">
                        <div className="form-control">
                            <label className="label pb-1" htmlFor={`badge-field-${index}-size`}>
                                <span className="label-text">{i18n._(t`Schriftgröße (pt)`)}</span>
                            </label>
                            <input
                                id={`badge-field-${index}-size`}
                                type="number"
                                step="1"
                                min={MIN_FONT_SIZE_PT}
                                max={MAX_FONT_SIZE_PT}
                                className={errorClass(rowErrors?.size)}
                                {...register(`fields.${index}.size`, { valueAsNumber: true })}
                                required
                            />
                            {rowErrors?.size ? (
                                <span className="label-text-alt mt-1 text-error">{rowErrors.size.message}</span>
                            ) : null}
                        </div>
                        <div className="form-control">
                            <label className="label pb-1" htmlFor={`badge-field-${index}-align`}>
                                <span className="label-text">{i18n._(t`Ausrichtung`)}</span>
                            </label>
                            <select
                                id={`badge-field-${index}-align`}
                                className="select select-sm w-full"
                                {...register(`fields.${index}.align`)}
                            >
                                {BADGE_ALIGNS.map((align) => (
                                    <option key={align} value={align}>
                                        {badgeAlignLabel(align, i18n)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                ) : null}

                {row.field === 'image' ? (
                    <ImageSourceSection
                        index={index}
                        row={row}
                        fieldErrors={errors.fields}
                        register={register}
                        setValue={setValue}
                    />
                ) : null}

                {errors.fields?.message ? (
                    <p role="alert" className="text-sm text-error">
                        {errors.fields.message}
                    </p>
                ) : null}

                <button type="button" className="btn btn-error btn-outline btn-sm" onClick={onDelete}>
                    <span className="iconify mdi--trash-can-outline text-lg"></span>
                    {i18n._(t`Feld entfernen`)}
                </button>
            </div>
        </aside>
    );
}

/**
 * Source selection for `image` entries: the mandant's brand media (logo /
 * header) or an uploaded badge image (SOLL-API `GET/POST /api/admin/badge-
 * images` — the backend slice is pending, errors surface inline), plus the
 * contain/cover fit switch.
 */
function ImageSourceSection({
    index,
    row,
    fieldErrors,
    register,
    setValue,
}: {
    index: number;
    row: BadgePropertiesRow;
    fieldErrors: FieldErrors<BadgeTemplateFormValues>['fields'];
    register: UseFormRegister<BadgeTemplateFormValues>;
    setValue: UseFormSetValue<BadgeTemplateFormValues>;
}) {
    const { i18n } = useLingui();
    const rowErrors = fieldErrors?.[index];
    const { data: images, error: imagesError, isLoading: isLoadingImages, mutate: mutateImages } = useSWR(
        '/api/admin/badge-images',
        () => listBadgeImages(),
    );
    const [file, setFile] = useState<File | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [isUploading, setIsUploading] = useState(false);

    const handleUpload = async () => {
        if (!file) return;
        setIsUploading(true);
        setUploadError(null);
        try {
            const uploaded = await uploadBadgeImage(file);
            await mutateImages();
            setValue(`fields.${index}.imageId`, uploaded.id, { shouldValidate: true });
            setValue(`fields.${index}.srcKind`, 'upload', { shouldValidate: true });
        } catch (err) {
            setUploadError(
                err instanceof ApiError ? err.message : i18n._(t`Das Bild konnte nicht hochgeladen werden.`),
            );
        } finally {
            setIsUploading(false);
        }
    };

    return (
        <fieldset className="flex flex-col gap-2 border-t border-base-300 pt-3">
            <legend className="text-xs font-medium uppercase tracking-wide text-base-content/60">
                {i18n._(t`Bildquelle`)}
            </legend>

            <div className="form-control">
                <label className="label pb-1" htmlFor={`badge-field-${index}-src-kind`}>
                    <span className="label-text">{i18n._(t`Quelle`)}</span>
                </label>
                <select
                    id={`badge-field-${index}-src-kind`}
                    className={`select select-sm w-full ${rowErrors?.srcKind ? 'select-error' : ''}`}
                    value={row.srcKind}
                    onChange={(event) => {
                        const kind = event.target.value === 'brand' ? 'brand' : 'upload';
                        setValue(`fields.${index}.srcKind`, kind, { shouldValidate: true });
                    }}
                    required
                >
                    <option value="none" disabled>
                        {i18n._(t`Bitte wählen`)}
                    </option>
                    <option value="brand">{i18n._(t`Mandanten-Bild (Logo/Kopfbild)`)}</option>
                    <option value="upload">{i18n._(t`Hochgeladenes Bild`)}</option>
                </select>
                {rowErrors?.srcKind ? (
                    <span className="label-text-alt mt-1 text-error">{rowErrors.srcKind.message}</span>
                ) : null}
            </div>

            {row.srcKind === 'brand' ? (
                <div className="form-control">
                    <label className="label pb-1" htmlFor={`badge-field-${index}-src-ref`}>
                        <span className="label-text">{i18n._(t`Mandanten-Bild`)}</span>
                    </label>
                    <select
                        id={`badge-field-${index}-src-ref`}
                        className="select select-sm w-full"
                        {...register(`fields.${index}.srcRef`)}
                    >
                        {BADGE_IMAGE_REFS.map((ref) => (
                            <option key={ref} value={ref}>
                                {badgeImageRefLabel(ref, i18n)}
                            </option>
                        ))}
                    </select>
                </div>
            ) : null}

            {row.srcKind === 'upload' ? (
                <div className="flex flex-col gap-2">
                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-image-id`}>
                            <span className="label-text">{i18n._(t`Vorhandenes Bild`)}</span>
                        </label>
                        <select
                            id={`badge-field-${index}-image-id`}
                            className={`select select-sm w-full ${rowErrors?.imageId ? 'select-error' : ''}`}
                            value={row.imageId > 0 ? String(row.imageId) : ''}
                            onChange={(event) => {
                                setValue(`fields.${index}.imageId`, Number(event.target.value), {
                                    shouldValidate: true,
                                });
                            }}
                            required
                        >
                            <option value="" disabled>
                                {i18n._(t`Bitte wählen`)}
                            </option>
                            {(images ?? []).map((image) => (
                                <option key={image.id} value={String(image.id)}>
                                    {image.original_name}
                                </option>
                            ))}
                        </select>
                        {rowErrors?.imageId ? (
                            <span className="label-text-alt mt-1 text-error">{rowErrors.imageId.message}</span>
                        ) : null}
                    </div>

                    {isLoadingImages ? <span className="loading loading-spinner loading-sm"></span> : null}
                    {imagesError ? (
                        <p role="alert" className="text-xs text-error">
                            {i18n._(t`Bilder konnten nicht geladen werden.`)}
                        </p>
                    ) : null}

                    <div className="form-control">
                        <label className="label pb-1" htmlFor={`badge-field-${index}-upload`}>
                            <span className="label-text">{i18n._(t`Neues Bild hochladen`)}</span>
                        </label>
                        <input
                            id={`badge-field-${index}-upload`}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="file-input file-input-sm file-input-bordered w-full"
                            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                        />
                    </div>
                    <button
                        type="button"
                        className="btn btn-outline btn-sm"
                        disabled={!file || isUploading}
                        onClick={() => void handleUpload()}
                    >
                        {isUploading ? <span className="loading loading-spinner loading-xs"></span> : null}
                        {i18n._(t`Bild hochladen`)}
                    </button>
                    {uploadError ? (
                        <p role="alert" className="text-xs text-error">
                            {uploadError}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <div className="form-control">
                <label className="label pb-1" htmlFor={`badge-field-${index}-fit`}>
                    <span className="label-text">{i18n._(t`Skalierung`)}</span>
                </label>
                <select
                    id={`badge-field-${index}-fit`}
                    className="select select-sm w-full"
                    {...register(`fields.${index}.fit`)}
                >
                    {BADGE_IMAGE_FITS.map((fit) => (
                        <option key={fit} value={fit}>
                            {badgeFitLabel(fit, i18n)}
                        </option>
                    ))}
                </select>
            </div>
        </fieldset>
    );
}
