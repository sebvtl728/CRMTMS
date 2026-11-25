<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Documents\PdfSource;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Documents\Texts\DocumentTexts;
use App\Modules\Documents\Texts\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a training document (Gotenberg) from the data of its dossier
 * (Sessions\Public\Paperwork) and the organisme's texts, and serves an
 * issued one: always its archive, checked against its SHA-256 (410 when
 * lost or altered), never rendered again.
 */
class DocumentPdf
{
    public const NBSP = "\u{00A0}";

    public const LOST = 'Le PDF archivé de ce document est introuvable ou a été modifié : il ne peut plus être servi.';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly CompanyBranding $branding,
        private readonly DocumentTexts $texts,
        private readonly Fiches $fiches,
    ) {}

    /**
     * @param  array<string, mixed>  $dossier  Paperwork::dossier()
     * @param  array<string, mixed>  $recipient  DocumentRecipient
     */
    public function source(DocumentType $type, array $dossier, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient): PdfSource
    {
        return new PdfSource($this->html($type, $dossier, $company, $number, $issuedAt, $recipient), self::footer($type, $number));
    }

    /** The PDF of a source (Gotenberg). */
    public function renderSource(PdfSource $source): string
    {
        return $this->renderer->fromHtml($source->html, $source->footer);
    }

    private static function footer(DocumentType $type, string $number): string
    {
        return view('documents.document-footer', ['label' => $type->label().' '.$number])->render();
    }

    /**
     * @param  array<string, mixed>  $dossier
     * @param  array<string, mixed>  $recipient
     */
    public function html(DocumentType $type, array $dossier, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient): string
    {
        $trainees = $this->fiches->personLabels(array_values(array_unique(array_column($dossier['trainees'], 'person_id'))));
        $names = array_values(array_map(fn (array $p) => $p['label'], $trainees));
        $sessions = $dossier['sessions'];
        $minutes = array_sum(array_map(fn (array $s) => (int) ($s['planned_minutes'] ?? 0), $sessions));
        $exempt = $company->vat_regime === VatRegime::Exempt;
        $values = [
            '{stagiaire}' => $names[0] ?? '',
            '{stagiaires}' => $names === [] ? 'stagiaires à désigner' : implode(', ', $names),
            '{formation}' => implode(' ; ', array_map(fn (array $l) => $l['label'], array_filter($dossier['lines'], fn (array $l) => $l['kind'] === 'training'))),
            '{dates}' => implode(' ; ', array_map(fn (array $s) => self::period($s['starts_on'], $s['ends_on']), $sessions)),
            '{duree}' => $minutes > 0 ? self::hours($minutes) : 'la durée indiquée au programme',
            '{lieu}' => implode(' ; ', array_values(array_unique(array_filter(array_map(fn (array $s) => $s['place'] ?? null, $sessions))))) ?: 'le lieu indiqué dans la convocation',
            '{prix}' => $dossier['price_cents'] === null ? self::TO_COMPLETE : self::euros((int) $dossier['price_cents']).($exempt ? ' net de taxe' : ' HT'),
            '{contractant}' => (string) $recipient['name'],
            '{societe}' => $company->name,
            '{numero_dossier}' => (string) ($dossier['number'] ?? self::TO_COMPLETE),
            ...$this->alternanceValues($dossier['alternance'] ?? null, $minutes),
        ];
        // The alternance conventions share the frame of the convention.
        $view = $type->isAlternance() ? 'convention' : $type->value;

        return view('documents.training-'.$view, [
            'title' => $type->label(),
            'number' => $number,
            'issued_on' => $issuedAt->setTimezone('Europe/Paris')->format('d/m/Y'),
            'company' => $this->companyData($company, $issuedAt),
            'recipient' => $recipient,
            'dossier_number' => $dossier['number'],
            'lines' => array_map(fn (array $l) => ['label' => $l['label'], 'total' => $l['total_cents'] === null ? self::TO_COMPLETE : self::euros((int) $l['total_cents'])], $dossier['lines']),
            'price' => $values['{prix}'],
            'sessions' => array_map(fn (array $s) => ['label' => $s['label'], 'period' => self::period($s['starts_on'], $s['ends_on']),
                'hours' => $s['planned_minutes'] === null ? null : self::hours((int) $s['planned_minutes']), 'place' => $s['place']], $sessions),
            'trainees' => $names,
            'fundings' => $this->fundings($dossier),
            'blocks' => collect($this->texts->resolve($type, $company->id))->map(fn (string $text, string $key) => [
                'title' => DocumentTexts::blocks($type)[$key]['label'],
                'text' => DocumentTexts::fill($text, $values),
            ])->values()->all(),
        ])->render();
    }

    /**
     * The fields of an alternance convention (empty strings without a
     * contract): apprentice, employer, tutor, OPCO, contract type and dates,
     * hours in centre, RNCP code.
     *
     * @param  array<string, mixed>|null  $alternance  Paperwork::dossier()['alternance']
     * @return array<string, string>
     */
    private function alternanceValues(?array $alternance, int $minutes): array
    {
        if ($alternance === null) {
            return array_fill_keys(['{apprenti}', '{employeur}', '{tuteur}', '{type_contrat}', '{debut_contrat}', '{fin_contrat}', '{heures_centre}', '{code_rncp}', '{opco}'], '');
        }
        $people = $this->fiches->personLabels(array_values(array_filter([$alternance['apprentice_person_id'], $alternance['tutor_person_id']])));
        $organisations = $this->fiches->organisationLabels(array_values(array_filter([$alternance['employer_organisation_id'], $alternance['opco_organisation_id']])));

        return [
            '{apprenti}' => $people[$alternance['apprentice_person_id']]['label'] ?? self::TO_COMPLETE,
            '{employeur}' => $organisations[$alternance['employer_organisation_id']]['label'] ?? self::TO_COMPLETE,
            '{tuteur}' => $alternance['tutor_person_id'] === null ? self::TO_COMPLETE : ($people[$alternance['tutor_person_id']]['label'] ?? self::TO_COMPLETE),
            '{type_contrat}' => $alternance['contract_type'] === 'professionalisation' ? 'contrat de professionnalisation' : "contrat d'apprentissage",
            '{debut_contrat}' => CarbonImmutable::parse($alternance['starts_on'])->format('d/m/Y'),
            '{fin_contrat}' => CarbonImmutable::parse($alternance['ends_on'])->format('d/m/Y'),
            '{heures_centre}' => $minutes > 0 ? self::hours($minutes) : 'la durée indiquée au programme',
            '{code_rncp}' => $alternance['rncp_code'] ?: self::TO_COMPLETE,
            '{opco}' => $alternance['opco_organisation_id'] === null ? self::TO_COMPLETE : ($organisations[$alternance['opco_organisation_id']]['label'] ?? self::TO_COMPLETE),
        ];
    }

    /**
     * The fundings asked or agreed for the dossier (never a refused one, nor
     * the contractant funding itself), and what is left to the contractant:
     * the price minus them (« sous réserve » while one is not agreed). Null
     * without such a funding.
     *
     * @param  array<string, mixed>  $dossier  Paperwork::dossier()
     * @return array{rows: list<array{funder: string, status: string, amount: string, direct: bool}>, left: string, pending: bool}|null
     */
    private function fundings(array $dossier): ?array
    {
        $contractor = $dossier['contractor'];
        $fundings = array_values(array_filter($dossier['fundings'] ?? [], fn (array $f) => $f['status'] !== 'refused'
            && ! ($f['funder']['type'] === $contractor['type'] && $f['funder']['id'] === $contractor['id'])));
        if ($fundings === []) {
            return null;
        }
        $ids = fn (string $type) => array_values(array_map(fn (array $f) => (string) $f['funder']['id'], array_filter($fundings, fn (array $f) => $f['funder']['type'] === $type)));
        $names = [...$this->fiches->organisationLabels($ids('organisation')), ...$this->fiches->personLabels($ids('person'))];
        $total = 0;
        $rows = [];
        foreach ($fundings as $f) {
            $total += (int) $f['amount_cents'];
            $rows[] = [
                'funder' => $names[$f['funder']['id']]['label'] ?? '-',
                'status' => $f['status'] === 'agreed'
                    ? 'prise en charge accordée'.($f['agreement_number'] ? ', accord n° '.$f['agreement_number'] : '')
                    : 'demande en cours',
                'amount' => self::euros((int) $f['amount_cents']),
                'direct' => (bool) $f['pays_directly'],
            ];
        }

        return [
            'rows' => $rows,
            'left' => self::euros(max(0, (int) $dossier['price_cents'] - $total)),
            'pending' => array_filter($fundings, fn (array $f) => $f['status'] !== 'agreed') !== [],
        ];
    }

    /**
     * A per-trainee document (convocation, attestation, ministry certificate).
     *
     * @param  array<string, mixed>  $enrolment  Paperwork::enrolment()
     * @param  array<string, mixed>  $dossier  Paperwork::dossier()
     * @param  array<string, mixed>  $recipient
     */
    public function sourceForEnrolment(DocumentType $type, array $enrolment, array $dossier, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient): PdfSource
    {
        return new PdfSource($this->htmlForEnrolment($type, $enrolment, $dossier, $company, $number, $issuedAt, $recipient), self::footer($type, $number));
    }

    /**
     * @param  array<string, mixed>  $enrolment
     * @param  array<string, mixed>  $dossier
     * @param  array<string, mixed>  $recipient
     */
    public function htmlForEnrolment(DocumentType $type, array $enrolment, array $dossier, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient): string
    {
        $session = $enrolment['session'];
        $from = $enrolment['entered_on'] ?? $session['starts_on'];
        $to = $enrolment['left_on'] ?? $session['ends_on'];
        $training = implode(' ; ', array_map(fn (array $l) => $l['label'], array_filter($dossier['lines'], fn (array $l) => $l['kind'] === 'training' && $l['training_id'] === $session['training_id'])))
            ?: $session['label'];
        $contractor = $dossier['contractor']['type'] === 'organisation'
            ? ($this->fiches->billingIdentity('organisation', $dossier['contractor']['id'])['name'] ?? null) : null;
        $planned = $enrolment['planned_minutes'] === null ? null : self::hours((int) $enrolment['planned_minutes']);
        $values = [
            '{stagiaire}' => (string) $recipient['name'],
            '{stagiaires}' => (string) $recipient['name'],
            '{formation}' => $training,
            '{dates}' => self::period($session['starts_on'], $session['ends_on']),
            '{duree}' => $planned ?? 'la durée indiquée au programme',
            '{lieu}' => $session['place'] ?? 'le lieu indiqué ci-dessous',
            '{prix}' => '',
            '{contractant}' => $contractor ?? (string) $recipient['name'],
            '{societe}' => $company->name,
            '{numero_dossier}' => (string) $dossier['number'],
            '{heures_realisees}' => self::hours((int) $enrolment['realised_minutes']),
            '{date_entree}' => CarbonImmutable::parse($from)->format('d/m/Y'),
            '{date_sortie}' => CarbonImmutable::parse($to)->format('d/m/Y'),
        ];

        return view('documents.training-'.$type->value, [
            'title' => $type->label(),
            'number' => $number,
            'issued_on' => $issuedAt->setTimezone('Europe/Paris')->format('d/m/Y'),
            'company' => $this->companyData($company, $issuedAt),
            'recipient' => $recipient,
            'dossier_number' => $dossier['number'],
            'training' => $training,
            'employer' => $contractor,
            'period' => self::period($from, $to),
            'from' => $values['{date_entree}'],
            'to' => $values['{date_sortie}'],
            'realised' => $values['{heures_realisees}'],
            'planned' => $planned,
            'place' => $session['place'],
            'session_label' => $session['label'],
            'schedule' => array_map(fn (array $s) => [
                'date' => CarbonImmutable::parse($s['date'])->locale('fr')->isoFormat('dddd D MMMM YYYY'),
                'hours' => $s['starts_at'].' - '.$s['ends_at'],
                'place' => $s['place'],
            ], $enrolment['schedule']),
            'city' => $company->address_city,
            'blocks' => collect($this->texts->resolve($type, $company->id))->map(fn (string $text, string $key) => [
                'title' => DocumentTexts::blocks($type)[$key]['label'],
                'text' => DocumentTexts::fill($text, $values),
            ])->values()->all(),
        ])->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function companyData(Company $company, CarbonImmutable $issuedAt): array
    {
        $exempt = $company->vat_regime === VatRegime::Exempt;

        return [
            'name' => $company->name,
            'logo' => $this->branding->logoDataUri($company),
            'signature' => $this->branding->signatureDataUri($company),
            'address' => array_values(array_filter([$company->address_line1, $company->address_line2,
                trim(($company->address_postal_code ?? '').' '.($company->address_city ?? ''))], fn (?string $l) => $l !== null && trim($l) !== '')),
            'siret' => $company->siret,
            'nda_number' => $company->nda_number,
            'legal' => LegalMentions::for($company),
            'vat_notice' => $exempt ? LegalMentions::vatExemptionNotice($issuedAt) : null,
        ];
    }

    /**
     * The archived PDF of an issued document, checked against its SHA-256.
     *
     * @throws UserFacingHttpException 410 when lost or altered
     */
    public static function archived(Document $document): string
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $ownPath = str_starts_with($document->pdf_path, TenantStorage::path('documents').'/') && ! str_contains($document->pdf_path, '..');
        $content = $ownPath && $disk->exists($document->pdf_path) ? $disk->get($document->pdf_path) : null;
        if ($content === null || ! hash_equals($document->pdf_sha256, hash('sha256', $content))) {
            throw new UserFacingHttpException(410, self::LOST);
        }

        return $content;
    }

    /** What a preview cannot know yet (no dossier, no price). */
    public const TO_COMPLETE = 'à compléter';

    public static function euros(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.number_format(intdiv($cents, 100), 0, ',', self::NBSP).','.sprintf('%02d', $cents % 100).self::NBSP.'€';
    }

    public static function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h.self::NBSP.'h'.($m > 0 ? self::NBSP.sprintf('%02d', $m) : '');
    }

    public static function period(string $from, string $to): string
    {
        $start = CarbonImmutable::parse($from)->format('d/m/Y');
        $end = CarbonImmutable::parse($to)->format('d/m/Y');

        return $start === $end ? "le {$start}" : "du {$start} au {$end}";
    }
}
