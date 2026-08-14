/**
 * Triggers a browser download for a binary blob via an object URL + click on a
 * temporary anchor. The object URL is revoked asynchronously — an immediate
 * `revokeObjectURL` can abort the download hand-off in some browsers.
 */
export function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
