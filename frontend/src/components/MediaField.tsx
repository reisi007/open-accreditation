import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useId, useState } from 'react';
import { ApiError } from '../api/client';

interface MediaFieldProps {
    label: string;
    url: string | null;
    onUpload: (file: File) => Promise<void>;
    onDelete: () => Promise<void>;
}

export function MediaField({ label, url, onUpload, onDelete }: MediaFieldProps) {
    const { i18n } = useLingui();
    const fileInputId = useId();
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleUpload = async () => {
        if (!file) return;
        setBusy(true);
        setError(null);
        try {
            await onUpload(file);
            setFile(null);
        } catch (err) {
            setError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Upload fehlgeschlagen.`),
            );
        } finally {
            setBusy(false);
        }
    };

    const handleDelete = async () => {
        setBusy(true);
        setError(null);
        try {
            await onDelete();
        } catch (err) {
            setError(
                err instanceof ApiError
                    ? err.message
                    : i18n._(t`Bild konnte nicht entfernt werden.`),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <span className="label-text">{label}</span>
            {url ? (
                <img
                    src={url}
                    alt={label}
                    className="h-32 w-full rounded-box bg-base-100 object-contain"
                />
            ) : (
                <p className="label-text-alt text-base-content/70">{i18n._(t`Kein Bild hinterlegt.`)}</p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <label htmlFor={fileInputId} className="btn btn-outline btn-sm">
                    <span className="iconify mdi--image-outline text-lg"></span>
                    {i18n._(t`Bild auswählen`)}
                </label>
                {file ? <span className="text-sm text-base-content/70">{file.name}</span> : null}
            </div>
            <input
                id={fileInputId}
                type="file"
                accept="image/*"
                className="hidden"
                aria-label={label}
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
            <div className="flex gap-2">
                <button type="button" className="btn btn-sm" disabled={!file || busy} onClick={() => void handleUpload()}>
                    {i18n._(t`Hochladen`)}
                </button>
                {url ? (
                    <button
                        type="button"
                        className="btn btn-sm btn-error btn-outline"
                        disabled={busy}
                        onClick={() => void handleDelete()}
                    >
                        {i18n._(t`Entfernen`)}
                    </button>
                ) : null}
            </div>
            {error ? <span className="text-sm text-error">{error}</span> : null}
        </div>
    );
}
