<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;

/**
 * The copy of a submitted online test as a PDF (Gotenberg), rendered on
 * demand so it shows the latest grading: candidate, test and version,
 * dates, time spent, traces, each answer next to the right one with its
 * points, and the score. Every typed value is escaped ({{ }} only).
 */
final class OnlineAttemptPdf
{
    public function __construct(private readonly PdfRenderer $renderer, private readonly CompanyBranding $branding) {}

    public function render(Candidature $candidature, OnlineAttempt $attempt): string
    {
        return $this->renderer->fromHtml($this->html($candidature, $attempt));
    }

    public function html(Candidature $candidature, OnlineAttempt $attempt): string
    {
        $view = OnlineReview::view($attempt);
        $person = app(Fiches::class)->personLabels([$candidature->person_id])[$candidature->person_id] ?? null;
        $company = Brand::company();
        $paris = fn (?string $at) => $at === null ? null : CarbonImmutable::parse($at)->setTimezone('Europe/Paris')->format('d/m/Y à H:i');

        return view('documents.online-test', [
            'company' => ['name' => $company->name ?? '', 'logo' => $company === null ? null : $this->branding->logoDataUri($company)],
            'candidate' => (string) ($person['label'] ?? 'Personne anonymisée'),
            'number' => $candidature->number,
            'test' => $view,
            'started' => $paris($view['started_at']),
            'submitted' => $paris($view['submitted_at']),
            'score' => $view['score'] !== null
                ? "{$view['score']} / {$view['max_score']}"
                : ($view['auto_score'] ?? 0)." / {$view['max_score']} (réponses rédigées à corriger)",
        ])->render();
    }
}
