<?php

namespace App\Services;

use App\Models\Application;
use App\Models\BadgeImage;
use App\Models\BadgeTemplate;
use App\Support\MandantContext;
use Dompdf\Dompdf;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
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
 * embedded `<img>` blocks (private disk — no network access in the render
 * path). The source is resolved server-side from the `src` discriminator:
 * `{kind: brand, ref: logo|header}` → the mandant's brand media, `{kind:
 * upload, image_id: <int>}` → the mandant-scoped `badge_images` row. `fit`
 * defaults to `contain` (logos are untouched); a missing source renders an
 * empty box at the layout position (the card still prints).
 *
 * The verify URL is `{scheme}://{host}/verify/{token}`: `host` is the current
 * mandant's first domain or, without a domain, the host of `config('app.url')'.
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

    public function __construct(
        private readonly QrTokenService $tokens,
        private readonly MandantMediaService $mandantMedia,
    ) {}

    /**
     * The full verify URL of one application (used by the QR and the CSV
     * export). Deterministic — `QrTokenService::make()` returns the same token
     * for the same application.
     */
    public function verifyUrl(Application $application): string
    {
        return sprintf('%s://%s/verify/%s', $this->scheme(), $this->host(), $this->tokens->make($application));
    }

    /**
     * Render the PDF for one accreditation's approved applications. An empty
     * collection yields a blank A6 page (the export endpoint answers 200 with
     * an empty document, not 204).
     */
    public function renderPdf(Collection $applications, BadgeTemplate $template): string
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

    private function html(Collection $applications, BadgeTemplate $template): string
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
     * the private disk, or null when the source is absent/invalid. Brand refs
     * resolve through `MandantMediaService`; upload ids resolve against the
     * current mandant's `badge_images` rows only (never a raw path/URL).
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

            if ($path === null || ! Storage::disk('private')->exists($path)) {
                return null;
            }

            return 'data:'.(string) Storage::disk('private')->mimeType($path).';base64,'
                .base64_encode((string) Storage::disk('private')->get($path));
        }

        if ($kind === 'upload') {
            $imageId = $src['image_id'] ?? null;

            if (! is_int($imageId) || $imageId < 1) {
                return null;
            }

            $image = BadgeImage::query()
                ->when(
                    MandantContext::hasCurrent(),
                    fn (EloquentBuilder $q) => $q->where('mandant_id', MandantContext::currentId()),
                )
                ->find($imageId);

            if ($image === null || ! Storage::disk('private')->exists($image->path)) {
                return null;
            }

            return 'data:'.$image->mime.';base64,'
                .base64_encode((string) Storage::disk('private')->get($image->path));
        }

        return null;
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

        $domain = MandantContext::current()?->domains()->orderBy('id')->value('hostname');
        $host = $domain ?? (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $resolved = $host === '' ? 'localhost' : $host;

        $this->hostCache[$mandantId] = $resolved;

        return $resolved;
    }

    private function scheme(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https');
    }
}
