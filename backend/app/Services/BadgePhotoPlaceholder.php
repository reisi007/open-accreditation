<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * The bundled fallback icon for a `photo` badge entry whose application has no
 * portrait (features/badge-template-editor.md, "Platzhalter für ein fehlendes
 * Porträt"): a neutral person silhouette instead of the empty box the renderer
 * used to print.
 *
 * **One file, two consumers.** The bytes live in the repository exactly once —
 * `resources/img/badge/photo-placeholder.png` — and both consumers read them
 * from there: the PDF renderer embeds them as a Base64 data URI, the
 * badge-template editor fetches them through the auth-gated
 * `GET /api/admin/badge-assets/photo-placeholder`. A second copy (e.g. an SVG
 * next to the PNG, or the editor's own icon font glyph) would drift; the PNG's
 * provenance is `scripts/render-badge-photo-placeholder.mjs`, which derives it
 * from the same `mdi account` glyph the editor showed before.
 *
 * **Why the asset lives in `resources/`, not in `public/`:** `public/` is the
 * web root — Caddy/Laravel serve whatever is there without authentication
 * (AGENTS.md §11: auth-gated file delivery). A bundled asset *may* be public by
 * construction, but the editor's delivery path is the same auth-gated admin API
 * the mandant's own badge images travel through, so the asset stays behind that
 * gate instead of growing a second, unguarded route through the web root.
 *
 * **The file is read, never written, and never resolved from a request:** the
 * path is a class constant (the only override is the constructor argument below,
 * which production never sets), so neither the render path nor the delivery
 * route can be steered to an arbitrary file.
 *
 * **A missing asset must not break an export.** `bytes()` returns `null` and
 * logs once per process; the renderer then falls back to the historical empty
 * box. The card still prints, which is the renderer's whole contract (see
 * `BadgeRenderService::renderImage`). It is a deployment defect, and the log
 * says so — it does not take down a tenant's badge export.
 */
final class BadgePhotoPlaceholder
{
    public const MIME = 'image/png';

    /** Relative to `resource_path()`; deliberately NOT below `public/`. */
    public const RELATIVE_PATH = 'img/badge/photo-placeholder.png';

    /**
     * The Base64 payload, resolved once per service instance (one instance
     * serves a whole export: `BadgeExportController` resolves the render service
     * once and renders N cards). Without this, every card without a portrait
     * would re-read and re-encode ~12 KB for nothing — the same reasoning as
     * `BadgeRenderService::$badgeImageCache` (WP-2-d).
     */
    private ?string $base64 = null;

    private bool $missing = false;

    /**
     * @param  string|null  $relativePath  override for the bundled file's
     *                                     location. Production never passes one —
     *                                     Laravel autowires this class without
     *                                     arguments — and the value is never
     *                                     derived from a request. It exists so the
     *                                     missing-asset path can be tested without
     *                                     moving the real file out of the tree
     *                                     (which would race a parallel test run).
     */
    public function __construct(private readonly ?string $relativePath = null) {}

    /**
     * Absolute path of the bundled file.
     */
    public function path(): string
    {
        return resource_path($this->relativePath ?? self::RELATIVE_PATH);
    }

    /**
     * The raw bytes, or null when the bundled file is missing (logged once per
     * process — see the class doc).
     */
    public function bytes(): ?string
    {
        $path = $this->path();

        if (! is_file($path)) {
            $this->reportMissing($path);

            return null;
        }

        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            $this->reportMissing($path);

            return null;
        }

        return $bytes;
    }

    /**
     * The icon as a `data:image/png;base64,…` URI, or null when the bundled file
     * is missing. The render path embeds this instead of a network URL — the
     * same no-network-in-the-render-path rule every other image in a badge obeys.
     */
    public function dataUri(): ?string
    {
        if ($this->base64 === null) {
            $bytes = $this->bytes();

            if ($bytes === null) {
                return null;
            }

            $this->base64 = base64_encode($bytes);
        }

        return 'data:'.self::MIME.';base64,'.$this->base64;
    }

    private function reportMissing(string $path): void
    {
        if ($this->missing) {
            return;
        }

        $this->missing = true;

        Log::error('The bundled badge photo placeholder is missing; photo entries without a portrait render as an empty box.', [
            'path' => $path,
            'regenerate' => 'node scripts/render-badge-photo-placeholder.mjs',
        ]);
    }
}
