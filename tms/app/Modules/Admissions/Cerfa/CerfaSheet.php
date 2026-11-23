<?php

namespace App\Modules\Admissions\Cerfa;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Public\Admission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The « fiche Cerfa » of an apprenticeship contract: every heading of the
 * Cerfa, in its order (employer, apprentice, master, contract, training),
 * filled with what the TMS knows, the rest marked « à compléter », to copy
 * on the OPCO's portal. Rendered on demand, never stored. The RQTH (a
 * sensitive datum) only for who may see the sensitive data.
 */
final class CerfaSheet
{
    public const TO_COMPLETE = 'à compléter';

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly Fiches $fiches,
        private readonly Trainees $trainees,
        private readonly Trainings $trainings,
    ) {}

    public static function fileName(Candidature $candidature): string
    {
        return 'fiche-cerfa-apprentissage-'.Str::slug($candidature->number).'.pdf';
    }

    public function pdf(Candidature $candidature, User $viewer): string
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        if ($contract === null) {
            throw new UserFacingHttpException(409, 'Saisissez d\'abord le contrat d\'alternance : la fiche Cerfa en reprend l\'employeur, le maître d\'apprentissage et les dates.');
        }

        return $this->renderer->fromHtml(
            view('documents.apprenticeship-cerfa', ['sections' => $this->sections($candidature, $contract, $viewer), 'number' => $candidature->number])->render(),
            view('documents.document-footer', ['label' => 'Fiche Cerfa du contrat d\'apprentissage - '.$candidature->number])->render(),
        );
    }

    /**
     * @return list<array{title: string, rows: list<array{0: string, 1: ?string}>}>
     */
    private function sections(Candidature $candidature, AlternanceContract $contract, User $viewer): array
    {
        $employer = $this->fiches->contactCard('organisation', $contract->employer_organisation_id);
        $codes = $this->fiches->organisationCodes($contract->employer_organisation_id);
        $opco = $contract->opco_organisation_id === null ? null : $this->fiches->contactCard('organisation', $contract->opco_organisation_id);
        $person = $this->fiches->contactCard('person', $candidature->person_id);
        $profile = $this->trainees->profile($candidature->person_id);
        $tutor = $contract->tutor_person_id === null ? null : $this->fiches->contactCard('person', $contract->tutor_person_id);
        $training = $candidature->training_id === null ? null : $this->trainings->find($candidature->training_id);
        $schedule = $candidature->dossier_id === null ? null : Admission::booklet($candidature->dossier_id, $candidature->person_id);
        $company = Brand::company();
        $legal = is_array($candidature->legal_representative) ? $candidature->legal_representative : [];
        $labels = ReferenceItem::withTrashed()->whereKey(array_filter([$profile['professional_situation_id'] ?? null, $profile['education_level_id'] ?? null]))->pluck('label', 'id');
        $label = fn (?string $id) => $id === null ? null : ($labels[$id] ?? null);
        $sensitive = AdmissionsVisibility::sensitive($viewer);

        return [
            ['title' => 'Employeur', 'rows' => [
                ['Dénomination', $employer['label'] ?? null],
                ['N° SIRET de l\'établissement d\'exécution du contrat', self::siret($codes['siret'] ?? null)],
                ['Code activité (NAF)', $codes['naf_code'] ?? null],
                ['Code IDCC de la convention collective', $codes['idcc'] ?? null],
                ['Adresse de l\'établissement', $employer['address'] ?? null],
                ['Téléphone', $employer['phone'] ?? null],
                ['Courriel', $employer['email'] ?? null],
                ['Type d\'employeur (privé / public)', null],
                ['Effectif salarié de l\'entreprise', null],
                ['OPCO', $opco['label'] ?? null],
            ]],
            ['title' => 'Apprenti', 'rows' => [
                ['Nom de naissance', $person['last_name'] === null ? null : mb_strtoupper((string) $person['last_name'])],
                ['Prénom', $person['first_name'] ?? null],
                ['NIR (numéro de sécurité sociale)', null],
                ['Date de naissance', self::day($person['birth_date'] ?? null)],
                ['Commune et département de naissance', null],
                ['Nationalité', null],
                ['Adresse', $person['address'] ?? null],
                ['Téléphone', $person['phone'] ?? null],
                ['Courriel', $person['email'] ?? null],
                ['Situation avant ce contrat', $label($profile['professional_situation_id'] ?? null)],
                ['Dernier diplôme ou titre préparé', $candidature->last_diploma],
                ['Diplôme ou titre le plus élevé obtenu', $label($profile['education_level_id'] ?? null)],
                ['Reconnaissance travailleur handicapé', $sensitive ? ($candidature->rqth ? 'Oui' : 'Non') : 'Donnée sensible, réservé aux personnes autorisées'],
                ['Représentant légal (nom, prénom)', trim(($legal['last_name'] ?? '').' '.($legal['first_name'] ?? '')) ?: null],
                ['Représentant légal : lien, téléphone, courriel', trim(implode(' - ', array_filter([$legal['relationship'] ?? null, $legal['phone'] ?? null, $legal['email'] ?? null]))) ?: null],
            ]],
            ['title' => 'Maître d\'apprentissage', 'rows' => [
                ['Nom et prénom', $tutor['label'] ?? null],
                ['Date de naissance', self::day($tutor['birth_date'] ?? null)],
                ['Courriel', $tutor['email'] ?? null],
                ['Téléphone', $tutor['phone'] ?? null],
                ['Emploi occupé', null],
                ['Diplôme ou titre le plus élevé obtenu', null],
            ]],
            ['title' => 'Contrat', 'rows' => [
                ['Type de contrat', $contract->contract_type->label()],
                ['Date de début d\'exécution', $contract->starts_on->format('d/m/Y')],
                ['Date de fin du contrat', $contract->ends_on->format('d/m/Y')],
                ['Date de conclusion (signature)', null],
                ['Durée hebdomadaire de travail', null],
                ['Rémunération (pourcentage du SMIC ou du minimum conventionnel)', null],
            ]],
            ['title' => 'Formation et CFA', 'rows' => [
                ['CFA : dénomination', $company?->name],
                ['CFA : N° SIRET', self::siret($company?->siret)],
                ['CFA : N° de déclaration d\'activité', $company?->nda_number],
                ['CFA : N° UAI', null],
                ['CFA : adresse', $company === null ? null : (trim(implode(' ', array_filter([$company->address_line1, $company->address_line2, $company->address_postal_code, $company->address_city]))) ?: null)],
                ['Diplôme ou titre visé', $training?->title],
                ['Code RNCP', $candidature->rncp_code ?? $training?->rncp_code],
                ['Durée de la formation en CFA (heures)', self::hours($training === null ? null : ($training->centre_hours ?? $training->duration_hours))],
                ['Date de début de la formation', self::day($schedule['starts_on'] ?? null)],
                ['Date prévue de fin des épreuves ou examens', self::day($schedule['ends_on'] ?? null)],
            ]],
        ];
    }

    private static function day(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    /** « 49358822200080 » → « 493 588 222 00080 ». */
    private static function siret(?string $siret): ?string
    {
        return $siret === null || strlen($siret) !== 14 ? $siret : substr($siret, 0, 3).' '.substr($siret, 3, 3).' '.substr($siret, 6, 3).' '.substr($siret, 9);
    }

    private static function hours(?string $hours): ?string
    {
        return $hours === null ? null : rtrim(rtrim(number_format((float) $hours, 2, ',', ' '), '0'), ',');
    }
}
