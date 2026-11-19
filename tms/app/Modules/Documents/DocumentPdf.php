<?php

namespace App\Modules\Documents;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Companies\LegalMentions;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Documents\PdfRenderer;
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
    public function render(DocumentType $type, array $dossier, Company $company, string $number, CarbonImmutable $issuedAt, array $recipient): string
    {
        return $this->renderer->fromHtml(
            $this->html($type, $dossier, $company, $number, $issuedAt, $recipient),
            view('documents.document-footer', ['label' => $type->label().' '.$number])->render(),
        );
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
            '{prix}' => self::euros((int) $dossier['price_cents']).($exempt ? ' net de taxe' : ' HT'),
            '{contractant}' => (string) $recipient['name'],
            '{societe}' => $company->name,
            '{numero_dossier}' => (string) $dossier['number'],
        ];

        return view('documents.training-'.$type->value, [
            'title' => $type->label(),
            'number' => $number,
            'issued_on' => $issuedAt->setTimezone('Europe/Paris')->format('d/m/Y'),
            'company' => [
                'name' => $company->name,
                'logo' => $this->branding->logoDataUri($company),
                'signature' => $this->branding->signatureDataUri($company),
                'address' => array_values(array_filter([$company->address_line1, $company->address_line2,
                    trim(($company->address_postal_code ?? '').' '.($company->address_city ?? ''))], fn (?string $l) => $l !== null && trim($l) !== '')),
                'siret' => $company->siret,
                'nda_number' => $company->nda_number,
                'legal' => LegalMentions::for($company),
                'vat_notice' => $exempt ? LegalMentions::vatExemptionNotice($issuedAt) : null,
            ],
            'recipient' => $recipient,
            'dossier_number' => $dossier['number'],
            'lines' => array_map(fn (array $l) => ['label' => $l['label'], 'total' => self::euros((int) $l['total_cents'])], $dossier['lines']),
            'price' => $values['{prix}'],
            'sessions' => array_map(fn (array $s) => ['label' => $s['label'], 'period' => self::period($s['starts_on'], $s['ends_on']),
                'hours' => $s['planned_minutes'] === null ? null : self::hours((int) $s['planned_minutes']), 'place' => $s['place']], $sessions),
            'trainees' => $names,
            'blocks' => collect($this->texts->resolve($type, $company->id))->map(fn (string $text, string $key) => [
                'title' => DocumentTexts::blocks($type)[$key]['label'],
                'text' => DocumentTexts::fill($text, $values),
            ])->values()->all(),
        ])->render();
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
