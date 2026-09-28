<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BadgeImage;
use App\Models\BadgeTemplate;
use App\Support\MandantContext;
use Dompdf\Dompdf;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Renders an approved application as a badge (P4). The layout comes from a
 * `BadgeTemplate.layout` array; every field is positioned absolutely on the
 * A6 card (105 × 148 mm):
 *
 *   [{field, x, y, w, h, size, align}]   — x/y/w/h in mm, size in pt
 *
 * CSS mm/pt are physical units for dompdf: 1 layout-mm prints as exactly
 * 1 mm on the fixed 105 × 148 mm card (`@page A6, margin 0`) — no server-side
 * scaling step. Missing keys stay defensively defaulted (`?? 0` / defaults).
 *
 * **The card is printed on an opaque white page** ({@see PAGE_BACKGROUND_STYLE}):
 * dompdf paints no page background of its own, so without it a badge PDF came
 * out transparent and its raster carried an alpha channel whose treatment the
 * CONSUMER decided. The price — a badge is no longer transparent, so foil, glass
 * and screen-print over-prints are no longer served — was weighed and decided
 * knowingly on 2026-09-28; features/badges-qr.md carries the full reasoning.
 *
 * `field` values are resolved from the application graph:
 *
 *   name        → user name
 *   category    → accreditation.category.name
 *   event       → accreditation.event.title
 *   date        → event date (d.m.Y)
 *   photo       → the applicant's portrait from the private disk (base64 data URI);
 *                 without a portrait the bundled person silhouette
 *                 (`BadgePhotoPlaceholder`) is printed in the same box — see
 *                 features/badge-template-editor.md, "Platzhalter für ein
 *                 fehlendes Porträt". `fit` defaults to `cover`
 *   status      → human German status label
 *   team        → accreditation.team.name (empty string without a team)
 *   vest_number → user.vest_number (empty string when unset)
 *
 * **`fit` is geometry, not a declaration.** dompdf implements no `object-fit`
 * (measured: a card rendered with `object-fit: contain` and one without it are
 * byte-identical PDFs — the property falls through the cascade silently), so
 * `width:100%;height:100%` means STRETCH. Every picture in a badge therefore
 * gets its drawn rectangle computed in millimetres from the source's intrinsic
 * aspect ratio — `contain` (fully inside, centered), `cover` (box filled, the
 * other axis cropped by the box's `overflow:hidden`). One implementation
 * ({@see fittedImage}) serves ALL FOUR picture branches: the portrait, the
 * placeholder, the free `image` entries and the verification QR; see
 * features/badge-template-editor.md, "Die `fit`-Geometrie rechnet der Renderer
 * selbst".
 *
 * The QR is the one branch that does not take its `fit` from the layout entry —
 * the wire format has no `fit` key for `qr` — so it is hard-coded to `cover`
 * and the paragraph above's "Every picture … from the source's intrinsic aspect
 * ratio" applies to it too. `cover` rather than `contain` because a verification
 * code must fill the box the tenant reserved for it: a stretched code does not
 * scan at all, and a letterboxed one wastes that area. See {@see renderQr}.
 *
 * The verification QR code (PNG, data URI of the verify URL) is part of every
 * card (schema v2, features/badge-template-editor.md): a dedicated `qr`
 * layout entry positions it (`left/top/width/height`, `size`/`align` are
 * ignored); without such an entry it renders at the historical fixed position
 * bottom-right (`QR_FALLBACK_*`) so existing templates keep rendering
 * identically.
 *
 * Freely placed `image` entries render as absolutely positioned, Base64-
 * embedded `<img>` blocks (public `media` disk with a legacy `private`
 * fallback — no network access in the render path). The source is resolved
 * server-side from the `src` discriminator:
 * `{kind: brand, ref: logo|header}` → the mandant's brand media, `{kind:
 * upload, image_id: <int>}` → the mandant-scoped `badge_images` row. `fit`
 * defaults to `contain` (logos are untouched) and selects the mm geometry
 * described above; a missing source renders an empty box at the layout
 * position (the card still prints). The upload lookup is mandant-scoped
 * unconditionally and cached per render run, so an export issues O(distinct
 * image ids) queries instead of one per card.
 *
 * The verify URL is `{scheme}://{host}/verify/{token}`: `host` is the current
 * mandant's first domain or, without a domain, the host of `config('app.url')`.
 * `{token}` is the tenant-bound token of `QrTokenService` (format v2) and is
 * (re-)minted on the row when it is missing or no longer verifiable.
 *
 * That host fallback is only sound while it routes BACK to this mandant: a v2
 * token is tenant-bound, so a URL on a foreign host 404s on every scan no matter
 * how valid the token is. `BadgeExportController` therefore refuses a domain-less
 * mandant up front, unconditionally (F2); this class keeps rendering
 * unconditionally so a direct/service-level call still produces a document
 * instead of throwing.
 */
final class BadgeRenderService
{
    public const A6_WIDTH_MM = 105;

    public const A6_HEIGHT_MM = 148;

    /**
     * The white page background, painted inline on the card root.
     *
     * **Why it is set at the source (Nutzerentscheidung 2026-09-28).** dompdf
     * paints NO page background of its own: a badge PDF came out of the renderer
     * fully transparent, so the raster of it carried an **alpha channel** and
     * what the page looked like was decided by the CONSUMER, not by the
     * renderer — on white paper correct, on a dark background black, with the
     * badge's black text vanishing into it. That is why the visual verification
     * needed a post-processing script to strip the alpha at all. A white
     * background painted here removes the defect in the PDF itself, so there is
     * nothing left to repair: measured, the raw raster of a badge page goes
     * from `srgba` with corner alpha 0 to no alpha channel at all.
     *
     * It sits on the CARD ROOT, not on the individual elements: `cardHtml()`
     * returns the A6-sized `.card` div (105 × 148 mm, the page container), so
     * the fill covers the whole A6 surface instead of only the boxes that happen
     * to carry a field. Every state of every element inherits it — a card whose
     * `photo` entry has a portrait, one that falls back to the silhouette
     * placeholder, and one with no picture behind the spot at all. A background
     * on the picture boxes would have been transparent again in the third case.
     *
     * **The price, decided knowingly:** a badge is no longer transparent. Foil,
     * glass and screen-print over-prints that rely on a transparent background
     * are no longer served by this export. features/badges-qr.md records the
     * decision; features/badge-template-editor.md the render contract.
     *
     * `scripts/pdf-to-png-vision.sh` keeps its alpha-stripping logic over this
     * deliberately: for OUR badges there is nothing left to strip, but the
     * script must still repair a FOREIGN PDF robustly, and its postcondition
     * ("no alpha channel, opaque background") is what proves that.
     */
    public const PAGE_BACKGROUND_STYLE = 'background-color:#ffffff;';

    /** Historical QR fallback geometry: bottom-right, 5 mm margin, 20 × 20 mm. */
    public const QR_FALLBACK_MARGIN_MM = 5;

    public const QR_FALLBACK_SIZE_MM = 20;

    /**
     * Edge length in pixels the QR PNG is generated at, and — because the QR
     * code is square by construction — its intrinsic width AND height. The
     * builder rounds the requested size up to a whole number of modules, so the
     * generated PNG is `>=` this on both axes and keeps the module ratio either
     * way: `fittedImage` only ever uses the aspect ratio of the intrinsic size,
     * and a square source in a square box is the one case where `cover` and
     * `contain` must agree exactly.
     */
    public const QR_SIZE_PX = 300;

    /**
     * In-memory host cache for one render run (FE1-F4). The verify URL's host
     * depends only on the current mandant's first domain (or app.url) — without
     * caching, every card issues the same `domains` query (N+1 on export). Keyed
     * by mandant id so a single service instance stays correct even when the
     * mandant context changes between renders. Not persisted — rebuilt per
     * request when Laravel re-resolves the service.
     *
     * @var array<int|string, string>
     */
    private array $hostCache = [];

    /**
     * In-memory cache of the resolved `BadgeImage` data URIs for one render run
     * (WP-2-d). An `image` layout entry addresses a `badge_images` row by id and
     * the SAME template (with the same one or two images) renders for every card
     * of an export — without a cache every card re-ran the DB lookup AND
     * re-read + re-encoded the file (N×M queries and N×M base64 encodes per
     * export). Keyed by `mandantId:imageId` so a single service instance stays
     * correct when the mandant context changes between renders. Not persisted —
     * rebuilt per request when Laravel re-resolves the service. The intrinsic
     * pixel size is cached WITH the URI (same reasoning: it is derived from the
     * same bytes).
     *
     * @var array<string, array{uri: string, size: array{0: int, 1: int}|null}|null>
     */
    private array $badgeImageCache = [];

    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly MandantMediaService $mandantMedia,
        private readonly MediaStorage $mediaStorage,
        private readonly MediaHostResolver $hosts,
        private readonly BadgePhotoPlaceholder $placeholder,
    ) {}

    /**
     * The full verify URL of one application (used by the QR and the CSV
     * export). The token is tenant-bound (QrTokenService format v2) and, because
     * this is a write path, a row whose stored token is missing, legacy (v1) or
     * unverifiable after a key rotation is repaired here.
     */
    public function verifyUrl(Application $application): string
    {
        return sprintf('%s://%s/verify/%s', $this->scheme(), $this->host(), $this->tokens->make($application));
    }

    /**
     * Render the PDF for one accreditation's approved applications. An empty
     * collection yields a blank A6 page (the export endpoint answers 200 with
     * an empty document, not 204).
     *
     * The applications may be an eager-loaded `Collection` or a chunked
     * `LazyCollection` (the export passes the latter): they are consumed once, in
     * order, and are never materialised as a whole. dompdf itself remains
     * inherently in-memory — the complete HTML (including every base64 portrait)
     * plus the canvas object graph must fit into the PHP memory limit. That
     * residual limit is documented in features/badges-qr.md ("Export —
     * Speicherprofil"); the DB side is chunked, so a large export no longer also
     * holds every application model.
     *
     * @param  iterable<Application>  $applications
     */
    public function renderPdf(iterable $applications, BadgeTemplate $template): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml($this->html($applications, $template), 'UTF-8');
        $dompdf->setPaper('a6', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Human-readable German status label (printed badges, CSV status column).
     */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Akkreditiert',
            'requested' => 'Beantragt',
            'denied' => 'Abgelehnt',
            'blacklisted' => 'Gesperrt',
            default => $status,
        };
    }

    /**
     * @param  iterable<Application>  $applications
     */
    private function html(iterable $applications, BadgeTemplate $template): string
    {
        $cards = '';

        foreach ($applications as $application) {
            $cards .= $this->cardHtml($application, $template);
        }

        return '<html><head><meta charset="UTF-8"><style>'
            .'@page { size: A6 portrait; margin: 0; }'
            .'body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }'
            .'.card { position: relative; width: '.self::A6_WIDTH_MM.'mm; height: '.self::A6_HEIGHT_MM.'mm; page-break-after: always; }'
            .'</style></head><body>'.$cards.'</body></html>';
    }

    /**
     * The HTML of one badge card — the exact markup dompdf prints. Public so
     * the render contract (absolute mm positions, qr placement, field values)
     * can be asserted precisely without decoding the PDF binary.
     */
    public function cardHtml(Application $application, BadgeTemplate $template): string
    {
        $fields = '';
        $qrEntry = null;
        $imageEntries = [];

        foreach ((array) $template->layout as $field) {
            // The `qr` entry is not a data field; the dedicated QR block below
            // renders it (defensively only once, validation guarantees max one).
            if (is_array($field) && ($field['field'] ?? null) === 'qr') {
                $qrEntry ??= $field;

                continue;
            }

            // Freely placed `image` entries are rendered by the dedicated
            // image block below (source-resolved, never as a text field).
            if (is_array($field) && ($field['field'] ?? null) === 'image') {
                $imageEntries[] = $field;

                continue;
            }

            $fields .= $this->renderField($application, $field);
        }

        $images = '';
        foreach ($imageEntries as $imageEntry) {
            $images .= $this->renderImage($imageEntry);
        }

        return '<div class="card" style="'.self::PAGE_BACKGROUND_STYLE.'">'
            .$fields
            .$images
            .$this->renderQr($application, $qrEntry)
            .'</div>';
    }

    /**
     * The verification QR block — positioned by its `qr` layout entry when
     * present (`left/top/width/height` in mm, `size`/`align` ignored),
     * otherwise at the historical fixed spot bottom-right so templates
     * without the entry keep rendering identically.
     *
     * @param  array<string, mixed>|null  $entry  the first `qr` layout entry, if any
     */
    private function renderQr(Application $application, ?array $entry): string
    {
        [$boxW, $boxH] = $entry === null
            ? [(float) self::QR_FALLBACK_SIZE_MM, (float) self::QR_FALLBACK_SIZE_MM]
            : [(float) ($entry['w'] ?? 0), (float) ($entry['h'] ?? 0)];

        $style = $entry === null
            ? sprintf(
                'position:absolute;right:%dmm;bottom:%dmm;width:%dmm;height:%dmm;overflow:hidden;',
                self::QR_FALLBACK_MARGIN_MM,
                self::QR_FALLBACK_MARGIN_MM,
                self::QR_FALLBACK_SIZE_MM,
                self::QR_FALLBACK_SIZE_MM,
            )
            : sprintf(
                'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;overflow:hidden;',
                $this->mm((float) ($entry['x'] ?? 0)),
                $this->mm((float) ($entry['y'] ?? 0)),
                $this->mm($boxW),
                $this->mm($boxH),
            );

        $dataUri = $this->qrDataUri($application);

        /*
         * P7: the same `fittedImage` rule as every other picture on the card, and
         * ALWAYS `cover`.
         *
         * This branch used to render `<img style="width:100%;height:100%">`, which
         * is a STRETCH in dompdf (it implements no `object-fit` at all — measured,
         * the property has zero occurrences in `vendor/dompdf/`). A non-square
         * `qr` box is legal input: `qr` is one of the box fields, minimum 10 × 10
         * mm, so a tenant can set `w:30, h:20` and the code was drawn into a 3:2
         * rectangle. Unlike a stretched portrait that is merely ugly, a stretched
         * QR code is not SCANNABLE — the finder patterns lose their module ratio,
         * so verification of that badge silently fails and the badge is the
         * product.
         *
         * `cover` and not `contain`: `cover` scales by the larger axis factor, so
         * the code fills the box and any surplus is cropped by the box's
         * `overflow:hidden` — a cropped code still scans (the quiet zone is what
         * `margin: 0` in `qrDataUri` trades away, and the locator patterns are
         * kept), while `contain` would letterbox the code inside the box and
         * waste the tenant's chosen size. A verification code must never be
         * shrunk away from the area the tenant reserved for it.
         *
         * The `qr` entry carries no `fit` key (the wire format does not offer one
         * for it — `size`/`align` are ignored here for the same reason), so this
         * is a hard-coded `cover` and not a `fitFor(...)` default.
         */
        return $this->fittedImage($style, $dataUri, $this->qrIntrinsicSize(), $boxW, $boxH, 'cover');
    }

    /**
     * The QR PNG's intrinsic pixel size.
     *
     * The builder is asked for a fixed 300 px square ({@see QR_SIZE_PX}) and the
     * result is a PNG whose modules are an integer multiple of that, so the size
     * is a CONSTANT of the code, not a per-render measurement — reading it back
     * out of the bytes for every card would decode an image header N times per
     * export for a value that cannot differ. Stated here as the same number the
     * builder is given, with the coupling called out in the docblock.
     */
    private function qrIntrinsicSize(): array
    {
        return [self::QR_SIZE_PX, self::QR_SIZE_PX];
    }

    /**
     * @param  mixed  $field  one validated layout entry
     */
    private function renderField(Application $application, mixed $field): string
    {
        if (! is_array($field) || ! isset($field['field'])) {
            return '';
        }

        $name = (string) $field['field'];
        $wMm = (float) ($field['w'] ?? 0);
        $hMm = (float) ($field['h'] ?? 0);
        $style = sprintf(
            'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;font-size:%dpt;text-align:%s;',
            $this->mm((float) ($field['x'] ?? 0)),
            $this->mm((float) ($field['y'] ?? 0)),
            $this->mm($wMm),
            $this->mm($hMm),
            max(1, (int) ($field['size'] ?? 12)),
            in_array($field['align'] ?? null, ['center', 'right'], true) ? $field['align'] : 'left',
        );

        if ($name === 'photo') {
            return $this->renderPhoto($style, $application, $wMm, $hMm, $this->fitFor($field, 'cover'));
        }

        return sprintf('<div style="%s">%s</div>', $style, e((string) ($this->valueFor($application, $name) ?? '')));
    }

    /**
     * A `photo` entry: the applicant's portrait, or — when there is none — the
     * bundled person silhouette (`BadgePhotoPlaceholder`, features/
     * badge-template-editor.md, "Platzhalter für ein fehlendes Porträt").
     *
     * Both branches print through {@see fittedImage}, i.e. the `fit` is
     * GEOMETRY, not a declaration: dompdf does not implement `object-fit` at
     * all (measured: a PDF rendered with `object-fit: contain` and one without
     * it are byte-identical), so the property would fall through the cascade
     * silently and every portrait would be stretched into its box.
     *
     * The box stays in the markup either way — a missing portrait must never
     * remove the reserved space, and if the bundled asset itself is missing the
     * historical empty box is printed (the card always prints).
     *
     * `cover` is the default: the historical markup declared `object-fit: cover`
     * for a real portrait, and a portrait box is authored as a portrait box.
     * A stored `fit` (the wire format allows one on any entry) overrides it.
     */
    private function renderPhoto(string $style, Application $application, float $wMm, float $hMm, string $fit): string
    {
        $portrait = $application->user?->media->firstWhere('type', 'portrait');

        if ($portrait !== null && Storage::disk('private')->exists($portrait->path)) {
            $bytes = (string) Storage::disk('private')->get($portrait->path);

            // `e()` hardening: the data URI is base64 today (escape-neutral), but
            // escaping keeps the src attribute safe should the mime/source path
            // ever change. The bytes are already in hand for the intrinsic size —
            // measuring them costs no second read of the file.
            $dataUri = 'data:'.$portrait->mime.';base64,'.base64_encode($bytes);

            return $this->fittedImage(
                $style.'overflow:hidden;',
                $dataUri,
                $this->intrinsicSizeOf($bytes),
                $wMm,
                $hMm,
                $fit,
            );
        }

        $placeholder = $this->placeholder->dataUri();

        if ($placeholder === null) {
            return sprintf('<div style="%s"></div>', $style);
        }

        // The silhouette is contained and centered whatever the entry's `fit`
        // says: it is the answer to "no photo on file", not a placed picture,
        // and the editor preview renders it with `object-contain` — a cropped
        // or stretched person icon would disagree with the preview on screen.
        return $this->fittedImage(
            $style.'overflow:hidden;',
            $placeholder,
            $this->placeholder->intrinsicSize(),
            $wMm,
            $hMm,
            'contain',
        );
    }

    /**
     * One image drawn into its mm box the way `object-fit: $fit` would have
     * drawn it — the part of the property dompdf would have to implement for
     * us, expressed in millimetres.
     *
     * **This is the only geometry implementation in the file.** The `photo`
     * branch, the portrait placeholder and the `image` branch all come through
     * here; a second rule (the placeholder's square-only `min(w, h)`) is
     * exactly how two implementations of one contract drift apart. The
     * square placeholder is not a special case of its own here — it is a
     * square SOURCE fed to the general rule, which is why the two agree
     * byte-for-byte.
     *
     * - `contain`: scale by the SMALLER of the two axis factors, so the whole
     *   image lands inside the box, centered, and the leftover axis stays empty.
     * - `cover`: scale by the LARGER, so the box is filled on the binding axis
     *   and the other axis overhangs — the box's `overflow:hidden` crops it.
     *
     * A square source in a square box is the degenerate case in which the two
     * must agree exactly (both factors are equal, so both draw the box
     * identically) — the case a wrong implementation hides in, and the one a
     * test has to pin.
     *
     * `object-fit: $fit` stays in the inline style: it documents the intent and
     * makes the markup idempotent on a renderer that does honour the property.
     *
     * A degenerate input — an undecodable source, or a box `≤ 0` (only
     * reachable by bypassing the controller's minimum-size validation) — falls
     * back to the plain full-size image. The card must always print, and a
     * stretched picture beats a missing one.
     *
     * @param  string  $style  the box div's style, ending in `;`, with `overflow:hidden` already on it
     * @param  array{0: int, 1: int}|null  $intrinsic  the source's pixel size, or null when undecodable
     */
    private function fittedImage(string $style, string $dataUri, ?array $intrinsic, float $wMm, float $hMm, string $fit): string
    {
        $geometry = $this->fitGeometry($intrinsic, $wMm, $hMm, $fit);

        if ($geometry === null) {
            return sprintf(
                '<div style="%s"><img src="%s" style="width:100%%;height:100%%;object-fit:%s;"></div>',
                $style,
                e($dataUri),
                $fit,
            );
        }

        return sprintf(
            '<div style="%s"><img src="%s" style="position:absolute;left:%smm;top:%smm;'
            .'width:%smm;height:%smm;object-fit:%s;"></div>',
            $style,
            e($dataUri),
            $this->mm($geometry['left']),
            $this->mm($geometry['top']),
            $this->mm($geometry['width']),
            $this->mm($geometry['height']),
            $fit,
        );
    }

    /**
     * The drawn rectangle of a source inside its box, in mm, or null when the
     * inputs are degenerate (undecodable source or a box `≤ 0`).
     *
     * The offset may be NEGATIVE: that is `cover` overhanging its box, which
     * the box's `overflow:hidden` crops. It is the intended value, not a
     * rounding artefact.
     *
     * @param  array{0: int, 1: int}|null  $intrinsic
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function fitGeometry(?array $intrinsic, float $boxW, float $boxH, string $fit): ?array
    {
        if ($intrinsic === null || $boxW <= 0.0 || $boxH <= 0.0) {
            return null;
        }

        [$sourceW, $sourceH] = $intrinsic;

        if ($sourceW < 1 || $sourceH < 1) {
            return null;
        }

        $scaleX = $boxW / $sourceW;
        $scaleY = $boxH / $sourceH;
        $scale = $fit === 'cover' ? max($scaleX, $scaleY) : min($scaleX, $scaleY);

        $width = $sourceW * $scale;
        $height = $sourceH * $scale;

        return [
            'left' => ($boxW - $width) / 2,
            'top' => ($boxH - $height) / 2,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * The intrinsic `[width, height]` in pixels of an image's raw bytes, or
     * null when the header cannot be read. `@` because a file whose header
     * PHP does not know is a rendering detail, not a reason to fail a card.
     *
     * @return array{0: int, 1: int}|null
     */
    private function intrinsicSizeOf(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
    }

    /**
     * The `fit` of one layout entry, normalised to a value the geometry can
     * apply, or `$default` when the entry carries none.
     *
     * An invalid `fit` can never be STORED: `BadgeTemplateController` restricts
     * `layout.*.fit` to `contain`/`cover` and answers 422. So this is not a
     * silent fallback for template data — it only keeps a hand-built layout
     * array (a direct service-level call, a test) from reaching the geometry
     * with a value it has no branch for.
     *
     * @param  array<string, mixed>  $entry
     */
    private function fitFor(array $entry, string $default): string
    {
        $fit = $entry['fit'] ?? null;

        return $fit === 'contain' || $fit === 'cover' ? $fit : $default;
    }

    /**
     * One freely placed `image` layout entry: an absolutely positioned,
     * `overflow:hidden` div with a Base64-`<img>` from the private disk (the
     * same technique as `photo`/QR — no network access in the render path).
     *
     * The source is resolved server-side from a discriminator that NEVER
     * carries client-controlled paths/URLs (SSRF/path-traversal/cross-mandant
     * leak protection, features/badge-template-editor.md):
     *
     * - `{kind: brand, ref: logo|header}` → the mandant's brand media via
     *   `MandantMediaService` (empty box when none is stored),
     * - `{kind: upload, image_id: <int>}` → the mandant-scoped `badge_images`
     *   row (empty box when the row/file is gone).
     *
     * `fit` defaults to `contain` (logos are not cropped) and is applied as
     * GEOMETRY, not as a declaration — see {@see fittedImage} for why dompdf
     * cannot be trusted with `object-fit` and what the mm fallback means.
     *
     * @param  array<string, mixed>  $entry  one validated `image` layout entry
     */
    private function renderImage(array $entry): string
    {
        $style = sprintf(
            'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;overflow:hidden;',
            $this->mm((float) ($entry['x'] ?? 0)),
            $this->mm((float) ($entry['y'] ?? 0)),
            $this->mm((float) ($entry['w'] ?? 0)),
            $this->mm((float) ($entry['h'] ?? 0)),
        );

        $source = $this->resolveImageSource($entry['src'] ?? null);

        if ($source === null) {
            return sprintf('<div style="%s"></div>', $style);
        }

        return $this->fittedImage(
            $style,
            $source['uri'],
            $source['size'],
            (float) ($entry['w'] ?? 0),
            (float) ($entry['h'] ?? 0),
            $this->fitFor($entry, 'contain'),
        );
    }

    /**
     * Resolve an `image` entry's `src` discriminator to an embeddable source
     * from the public media disk (legacy `private` fallback), or null when the
     * source is absent/invalid. Brand refs resolve through
     * `MandantMediaService`; upload ids resolve against the current mandant's
     * `badge_images` rows only (never a raw path/URL).
     *
     * The intrinsic pixel size travels WITH the data URI: the `fit` geometry
     * needs the source's aspect ratio, and the bytes that carry it are already
     * in hand here — carrying it avoids a second decode of the same payload in
     * the render step and, more importantly, makes it impossible for the
     * geometry to be computed against a DIFFERENT image than the one embedded.
     *
     * The tenancy scope is UNCONDITIONAL in the render path (WP-2-d): without a
     * resolved mandant the entry renders as an empty box. A fail-open filter
     * (`->when(MantantContext::hasCurrent(), …)`) would drop the filter exactly
     * in the console/test context and embed a foreign mandant's file.
     *
     * @param  mixed  $src  the raw `src` discriminator of an image entry
     * @return array{uri: string, size: array{0: int, 1: int}|null}|null
     */
    private function resolveImageSource(mixed $src): ?array
    {
        if (! is_array($src)) {
            return null;
        }

        $kind = $src['kind'] ?? null;

        if ($kind === 'brand') {
            $ref = $src['ref'] ?? null;

            if ($ref !== 'logo' && $ref !== 'header') {
                return null;
            }

            $mandant = MandantContext::current();

            if ($mandant === null) {
                return null;
            }

            $path = $this->mandantMedia->path($mandant, $ref);

            if ($path === null || ! $this->mediaStorage->exists($path)) {
                return null;
            }

            return $this->embeddableSource(
                $this->mediaStorage->mimeType($path),
                $this->mediaStorage->get($path),
            );
        }

        if ($kind === 'upload') {
            $imageId = $src['image_id'] ?? null;

            if (! is_int($imageId) || $imageId < 1) {
                return null;
            }

            $mandantId = MandantContext::currentId();

            if ($mandantId === null) {
                return null;
            }

            $cacheKey = $mandantId.':'.$imageId;

            if (array_key_exists($cacheKey, $this->badgeImageCache)) {
                return $this->badgeImageCache[$cacheKey];
            }

            return $this->badgeImageCache[$cacheKey] = $this->resolveUploadSource($imageId, $mandantId);
        }

        return null;
    }

    /**
     * The mandant-scoped `BadgeImage` upload as an embeddable source, or null
     * when the row belongs to another mandant, is gone, or its file is missing.
     * The `forMandant()` scope is always applied — a foreign id yields null,
     * never a foreign file.
     *
     * @return array{uri: string, size: array{0: int, 1: int}|null}|null
     */
    private function resolveUploadSource(int $imageId, int $mandantId): ?array
    {
        $image = BadgeImage::query()->forMandant($mandantId)->find($imageId);

        if ($image === null || ! $this->mediaStorage->exists($image->path)) {
            return null;
        }

        return $this->embeddableSource($image->mime, $this->mediaStorage->get($image->path));
    }

    /**
     * The one place a badge image becomes markup: a Base64 data URI plus the
     * intrinsic size measured from the very bytes that were encoded, so the
     * `fit` geometry and the embedded picture can never disagree.
     *
     * @return array{uri: string, size: array{0: int, 1: int}|null}
     */
    private function embeddableSource(string $mime, string $bytes): array
    {
        return [
            'uri' => 'data:'.$mime.';base64,'.base64_encode($bytes),
            'size' => $this->intrinsicSizeOf($bytes),
        ];
    }

    /**
     * The field value of a layout field, or null when the source is absent
     * (e. g. an accreditation without an event). Null renders as an empty
     * string — consistent for `event`, `date`, `team` and `vest_number`.
     */
    private function valueFor(Application $application, string $field): ?string
    {
        return match ($field) {
            'name' => $application->user?->name,
            'category' => $application->accreditation?->category?->name,
            'event' => $application->accreditation?->event?->title,
            'date' => $application->accreditation?->event?->date?->format('d.m.Y'),
            // `accreditations.team_id` is nullable — a mandant-level
            // accreditation has no team and the field stays empty.
            'team' => $application->accreditation?->team?->name,
            'vest_number' => $application->user?->vest_number,
            'status' => $this->statusLabel((string) $application->status),
            default => null,
        };
    }

    private function qrDataUri(Application $application): string
    {
        $result = (new Builder(data: $this->verifyUrl($application), size: self::QR_SIZE_PX, margin: 0))->build();

        return (string) $result->getDataUri();
    }

    private function mm(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function host(): string
    {
        $mandantId = MandantContext::currentId() ?? 'global';

        if (array_key_exists($mandantId, $this->hostCache)) {
            return $this->hostCache[$mandantId];
        }

        $mandant = MandantContext::current();
        // W6-F3: share the central "first domain = primary" convention instead
        // of querying `domains` a second time (MediaHostResolver defines it
        // once for every media service) — and with it the app.url fallback host,
        // so the verify URL's host is derived in exactly one place.
        $domain = $mandant !== null ? $this->hosts->hostFor($mandant) : null;
        $host = $domain ?? $this->hosts->fallbackHost();
        $resolved = $host === null || $host === '' ? 'localhost' : $host;

        $this->hostCache[$mandantId] = $resolved;

        return $resolved;
    }

    private function scheme(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
    }
}
