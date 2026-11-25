<?php

namespace App\Modules\Admissions\Booklet;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Admission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The apprenticeship booklet of a candidature with an alternance contract,
 * already filled with what the TMS knows (spec 2026-11-23, part A): cover,
 * partners, team, individual pathway, planning, then the organisme's annexes
 * (PDFs merged as they are), then the monthly follow-up pages titled month
 * by month, the competence grids and the company visits, to be filled by
 * hand. Rendered on demand, never stored.
 */
final class BookletPdf
{
    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly CompanyBranding $branding,
        private readonly Fiches $fiches,
        private readonly Trainings $trainings,
        private readonly BookletGrid $grid,
    ) {}

    public static function fileName(Candidature $candidature): string
    {
        return 'livret-apprentissage-'.Str::slug($candidature->number).'.pdf';
    }

    public function pdf(Candidature $candidature): string
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        if ($contract === null) {
            throw new UserFacingHttpException(409, 'Saisissez d\'abord le contrat d\'alternance : le livret en reprend l\'employeur, le tuteur et les dates.');
        }
        $data = $this->data($candidature, $contract);
        $settings = BookletSettings::all();
        $footer = view('documents.document-footer', ['label' => 'Livret d\'apprentissage - '.$data['apprentice']['name']])->render();

        $annexes = array_values(array_filter(array_map(
            fn (array $a) => Storage::disk(TenantStorage::DISK)->get(BookletSettings::annexPath($a['id'])),
            $settings['annexes'],
        ), fn ($bytes) => is_string($bytes) && str_starts_with($bytes, '%PDF-')));

        if ($annexes === []) {
            return $this->renderer->fromHtml($this->html($data, 'all'), $footer);
        }

        return $this->renderer->merge([
            $this->renderer->fromHtml($this->html($data, 'front'), $footer),
            ...$annexes,
            $this->renderer->fromHtml($this->html($data, 'follow'), $footer),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  'all'|'front'|'follow'  $part
     */
    private function html(array $data, string $part): string
    {
        return view('documents.apprenticeship-booklet', [...$data, 'part' => $part])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Candidature $candidature, AlternanceContract $contract): array
    {
        $person = $this->fiches->contactCard('person', $candidature->person_id);
        $employer = $this->fiches->contactCard('organisation', $contract->employer_organisation_id);
        $tutor = $contract->tutor_person_id === null ? null : $this->fiches->contactCard('person', $contract->tutor_person_id);
        $opco = $contract->opco_organisation_id === null ? null : $this->fiches->contactCard('organisation', $contract->opco_organisation_id);
        $training = $candidature->training_id === null ? null : $this->trainings->find($candidature->training_id);
        $modules = $candidature->training_id === null ? [] : $this->trainings->modules($candidature->training_id);
        $dispensed = $candidature->dispensed_module_ids ?? [];
        $schedule = $candidature->dossier_id === null ? null : Admission::booklet($candidature->dossier_id, $candidature->person_id);
        $company = Brand::company();
        $settings = BookletSettings::all();
        $legal = is_array($candidature->legal_representative) ? $candidature->legal_representative : [];

        $months = array_map(fn (array $m) => [
            'label' => $m['label'],
            'places' => $m['places'],
            'centre_hours' => $m['centre_minutes'] > 0 ? self::hours((string) round($m['centre_minutes'] / 60, 2)) : null,
            'pages' => array_map(fn (array $page) => [
                ...$page,
                'apprentice' => self::signature($page['apprentice']),
                'tutor' => $page['tutor'] === null ? null : self::signature($page['tutor']),
            ], $m['pages']),
        ], BookletMonths::months($candidature, wholeContract: true));

        return [
            'company' => $company === null ? null : [
                'name' => $company->name,
                'logo' => $this->branding->logoDataUri($company),
                'address' => trim(implode(' ', array_filter([$company->address_line1, $company->address_line2, $company->address_postal_code, $company->address_city]))),
                'phone' => $company->phone,
                'email' => $company->email,
                'siret' => $company->siret,
                'nda' => $company->nda_number,
            ],
            'apprentice' => [
                'name' => trim(($person['first_name'] ?? '').' '.mb_strtoupper((string) ($person['last_name'] ?? ''))) ?: 'Apprenti',
                'first_name' => $person['first_name'] ?? null,
                'last_name' => $person['last_name'] === null ? null : mb_strtoupper((string) $person['last_name']),
                'birth_date' => self::day($person['birth_date'] ?? null),
                'address' => $person['address'] ?? null,
                'phone' => $person['phone'] ?? null,
                'email' => $person['email'] ?? null,
            ],
            'legal' => array_filter([
                'last_name' => $legal['last_name'] ?? null,
                'first_name' => $legal['first_name'] ?? null,
                'relationship' => $legal['relationship'] ?? null,
                'phone' => $legal['phone'] ?? null,
                'email' => $legal['email'] ?? null,
            ]),
            'contract' => [
                'type' => $contract->contract_type->label(),
                'starts_on' => $contract->starts_on->format('d/m/Y'),
                'ends_on' => $contract->ends_on->format('d/m/Y'),
            ],
            'employer' => $employer,
            'tutor' => $tutor,
            'opco' => $opco['label'] ?? null,
            'training' => [
                'title' => $training->title ?? 'Formation à préciser',
                'rncp_code' => $training?->rncp_code,
            ],
            'modules' => array_map(fn (array $m) => [
                'title' => $m['title'],
                'hours' => self::hours($m['duration_hours']),
                'dispensed' => in_array($m['id'], $dispensed, true),
            ], $modules),
            'grid' => $this->grid->tree($candidature)['types'],
            'visits' => array_map(fn (array $v) => [...$v, 'visited_on' => self::day($v['visited_on'])], BookletVisits::list($candidature)),
            'schedule' => $schedule === null ? null : [
                'session' => $schedule['session'],
                'centre_hours' => self::hours((string) round($schedule['centre_minutes'] / 60, 2)),
                'teachers' => $schedule['teachers'],
                'placements' => array_map(fn (array $p) => [...$p, 'from' => self::day($p['from']), 'to' => self::day($p['to'])], $schedule['placements']),
            ],
            'months' => $months,
            'team' => $settings['team'],
            'director_note' => $settings['director_note'],
        ];
    }

    /**
     * A signature state with its drawing as a data URI (read from the
     * archive; null when missing).
     *
     * @param  array{status: string, at: ?string, name: ?string, drawing: ?string}  $state
     * @return array{status: string, at: ?string, name: ?string, drawing: ?string}
     */
    private static function signature(array $state): array
    {
        $bytes = $state['drawing'] === null ? null : Storage::disk(TenantStorage::DISK)->get($state['drawing']);

        return [...$state, 'drawing' => is_string($bytes) && $bytes !== '' ? 'data:image/png;base64,'.base64_encode($bytes) : null];
    }

    private static function day(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    /** « 12 », « 7,5 » hours. */
    private static function hours(?string $hours): ?string
    {
        if ($hours === null || $hours === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $hours, 2, ',', ' '), '0'), ',');
    }
}
