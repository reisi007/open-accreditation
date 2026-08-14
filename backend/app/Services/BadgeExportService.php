<?php

namespace App\Services;

use App\Models\Accreditation;
use App\Models\Application;
use App\Models\BadgeTemplate;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Badge export (P4): downloads the approved applications of one accreditation
 * as a streamed document.
 *
 * - PDF (dompdf): one A6 card per approved application, rendered from the
 *   template's layout (see `BadgeRenderService`).
 * - CSV: `fputcsv` with the `;` separator (DE-Excel). A UTF-8 BOM is prepended
 *   so Excel decodes umlauts correctly. Columns: Name, E-Mail, Kategorie,
 *   Event, Status, Verify-URL.
 *
 * Decision (documented for the frontend contract): an accreditation without
 * approved applications answers **200 with an empty document** — a blank A6
 * page for PDF, the header row only for CSV — not 204. The template must
 * always resolve (explicit `template_id` or the mandant's default), otherwise
 * the controller answers 422 "No badge template" before this service runs.
 */
final class BadgeExportService
{
    public const CSV_HEADER = ['Name', 'E-Mail', 'Kategorie', 'Event', 'Status', 'Verify-URL'];

    public function __construct(private readonly BadgeRenderService $renderer) {}

    public function export(Accreditation $accreditation, BadgeTemplate $template, string $format): StreamedResponse
    {
        $applications = $this->approvedApplications($accreditation);

        return $format === 'csv'
            ? $this->csv($applications)
            : $this->pdf($applications, $template, (int) $accreditation->id);
    }

    /**
     * The approved applications of one accreditation in stable order, eager-
     * loaded for the renderer (user + portrait, category, event).
     *
     * @return Collection<int, Application>
     */
    private function approvedApplications(Accreditation $accreditation): Collection
    {
        return Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->with(['user.media', 'accreditation.category', 'accreditation.event'])
            ->orderBy('id')
            ->get();
    }

    private function pdf(Collection $applications, BadgeTemplate $template, int $accreditationId): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($applications, $template): void {
                echo $this->renderer->renderPdf($applications, $template);
            },
            'badges-'.$accreditationId.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function csv(Collection $applications): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($applications): void {
                $out = fopen('php://output', 'w');

                if ($out === false) {
                    return;
                }

                echo "\xEF\xBB\xBF";

                // `fputcsv` with the `;` separator. The `$escape` argument is
                // deliberately omitted: PHP 8.4+ deprecates it entirely (it
                // will disappear in PHP 9) and the 3-argument form produces
                // the portable, RFC-ish output (fields are enclosed with `"`
                // only when they need it).
                fputcsv($out, self::CSV_HEADER, ';');

                foreach ($applications as $application) {
                    fputcsv($out, $this->csvRow($application), ';');
                }

                fclose($out);
            },
            'badges.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @return list<string>
     */
    private function csvRow(Application $application): array
    {
        return [
            $application->user?->name ?? '',
            $application->user?->email ?? '',
            $application->accreditation?->category?->name ?? '',
            $application->accreditation?->event?->title ?? '',
            $this->renderer->statusLabel((string) $application->status),
            $this->renderer->verifyUrl($application),
        ];
    }
}
