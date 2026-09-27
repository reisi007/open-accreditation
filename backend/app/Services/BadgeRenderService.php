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
 * `field` values are resolved from the application graph:
 *
 *   name        → user name
 *   category    → accreditation.category.name
 *   event       → accreditation.event.title
 *   date        → event date (d.m.Y)
 *   photo       → the applicant's portrait from the private disk (base64 data URI;
 *                 an empty box when no portrait exists — the layout position stays)
 *   status      → human German status label
 *   team        → accreditation.team.name (empty string without a team)
 *   vest_number → user.vest_number (empty string when unset)
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
 * defaults to `contain` (logos are untouched); a missing source renders an
 * empty box at the layout position (the card still prints). The upload lookup
 * is mandant-scoped unconditionally and cached per render run, so an export
 * issues O(distinct image ids) queries instead of one per card.
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

    /** Historical QR fallback geometry: bottom-right, 5 mm margin, 20 × 20 mm. */
    public const QR_FALLBACK_MARGIN_MM = 5;

    public const QR_FALLBACK_SIZE_MM = 20;

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
     * rebuilt per request when Laravel re-resolves the service.
     *
     * @var array<string, string|null>
     */
    private array $badgeImageCache = [];

    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly MandantMediaService $mandantMedia,
        private readonly MediaStorage $mediaStorage,
        private readonly MediaHostResolver $hosts,
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

        return '<div class="card">'
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
        $style = $entry === null
            ? sprintf(
                'position:absolute;right:%dmm;bottom:%dmm;width:%dmm;height:%dmm;',
                self::QR_FALLBACK_MARGIN_MM,
                self::QR_FALLBACK_MARGIN_MM,
                self::QR_FALLBACK_SIZE_MM,
                self::QR_FALLBACK_SIZE_MM,
            )
            : sprintf(
                'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;',
                $this->mm((float) ($entry['x'] ?? 0)),
                $this->mm((float) ($entry['y'] ?? 0)),
                $this->mm((float) ($entry['w'] ?? 0)),
                $this->mm((float) ($entry['h'] ?? 0)),
            );

        return sprintf(
            '<div style="%s"><img src="%s" style="width:100%%;height:100%%;"></div>',
            $style,
            $this->qrDataUri($application),
        );
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
        $style = sprintf(
            'position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;font-size:%dpt;text-align:%s;',
            $this->mm((float) ($field['x'] ?? 0)),
            $this->mm((float) ($field['y'] ?? 0)),
            $this->mm((float) ($field['w'] ?? 0)),
            $this->mm((float) ($field['h'] ?? 0)),
            max(1, (int) ($field['size'] ?? 12)),
            in_array($field['align'] ?? null, ['center', 'right'], true) ? $field['align'] : 'left',
        );

        if ($name === 'photo') {
            return $this->renderPhoto($style, $application);
        }

        return sprintf('<div style="%s">%s</div>', $style, e((string) ($this->valueFor($application, $name) ?? '')));
    }

    private function renderPhoto(string $style, Application $application): string
    {
        $portrait = $application->user?->media->firstWhere('type', 'portrait');

        if ($portrait === null || ! Storage::disk('private')->exists($portrait->path)) {
            return sprintf('<div style="%s"></div>', $style);
        }

        // `e()` hardening: the data URI is base64 today (escape-neutral), but
        // escaping keeps the src attribute safe should the mime/source path
        // ever change.
        $dataUri = 'data:'.$portrait->mime.';base64,'.base64_encode((string) Storage::disk('private')->get($portrait->path));

        return sprintf(
            '<div style="%soverflow:hidden;"><img src="%s" style="width:100%%;height:100%%;object-fit:cover;"></div>',
            $style,
            e($dataUri),
        );
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
     * `fit` defaults to `contain` (logos are not cropped); `cover` opts into
     * the fill-and-crop behaviour.
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

        $dataUri = $this->resolveImageSource($entry['src'] ?? null);

        if ($dataUri === null) {
            return sprintf('<div style="%s"></div>', $style);
        }

        $fit = ($entry['fit'] ?? null) === 'cover' ? 'cover' : 'contain';

        return sprintf(
            '<div style="%s"><img src="%s" style="width:100%%;height:100%%;object-fit:%s;"></div>',
            $style,
            e($dataUri),
            $fit,
        );
    }

    /**
     * Resolve an `image` entry's `src` discriminator to a Base64 data URI from
     * the public media disk (legacy `private` fallback), or null when the
     * source is absent/invalid. Brand refs resolve through
     * `MandantMediaService`; upload ids resolve against the current mandant's
     * `badge_images` rows only (never a raw path/URL).
     *
     * The tenancy scope is UNCONDITIONAL in the render path (WP-2-d): without a
     * resolved mandant the entry renders as an empty box. A fail-open filter
     * (`->when(MandantContext::hasCurrent(), …)`) would drop the filter exactly
     * in the console/test context and embed a foreign mandant's file.
     *
     * @param  mixed  $src  the raw `src` discriminator of an image entry
     */
    private function resolveImageSource(mixed $src): ?string
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

            return 'data:'.$this->mediaStorage->mimeType($path).';base64,'
                .base64_encode($this->mediaStorage->get($path));
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
     * The mandant-scoped `BadgeImage` upload as a Base64 data URI, or null when
     * the row belongs to another mandant, is gone, or its file is missing. The
     * `forMandant()` scope is always applied — a foreign id yields null, never a
     * foreign file.
     */
    private function resolveUploadSource(int $imageId, int $mandantId): ?string
    {
        $image = BadgeImage::query()->forMandant($mandantId)->find($imageId);

        if ($image === null || ! $this->mediaStorage->exists($image->path)) {
            return null;
        }

        return 'data:'.$image->mime.';base64,'
            .base64_encode($this->mediaStorage->get($image->path));
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
        $result = (new Builder(data: $this->verifyUrl($application), size: 300, margin: 0))->build();

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
