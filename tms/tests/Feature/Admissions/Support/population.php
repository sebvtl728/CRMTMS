<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureListQuery;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;

/* The candidature population of the list tests (needs Support/admissions.php). */

/**
 * Four candidatures: a candidate, a rejected one, an apprenant and an
 * alternant; plus a candidate with a contract and no dossier yet.
 *
 * @return array<string, Candidature>
 */
function adPopulation(): array
{
    $move = fn (Candidature $c, CandidatureStatus $to, ?string $reason = null) => app(CandidatureTransitions::class)->move($c, $to, admin(), $reason);
    $contract = function (Candidature $c): void {
        app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'apprenticeship',
            'employer_organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Parc'])->id,
            'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30'], admin());
    };
    $dossier = function (Candidature $c): void {
        $creation = app(DossierCreation::class);
        $creation->create($c->fresh(), $creation->defaults($c->fresh()), admin());
    };

    $candidate = adCandidature(['person_id' => adPerson('Alpha')->id]);
    $rejected = adCandidature(['person_id' => adPerson('Bravo')->id]);
    $move($rejected, CandidatureStatus::Rejected, crmItem(ReferenceList::CandidatureRefusalReason, 'Autre')->id);
    $apprenant = adCandidature(['person_id' => adPerson('Charlie', ['is_individual' => true])->id]);
    $move($apprenant, CandidatureStatus::Admitted);
    $dossier($apprenant);
    $alternant = adCandidature(['person_id' => adPerson('Delta')->id]);
    $move($alternant, CandidatureStatus::Interview);
    $contract($alternant);
    $move($alternant->fresh(), CandidatureStatus::Admitted);
    $dossier($alternant);
    $pending = adCandidature(['person_id' => adPerson('Echo')->id]);
    $move($pending, CandidatureStatus::Interview);
    $contract($pending);

    return compact('candidate', 'rejected', 'apprenant', 'alternant', 'pending');
}

function adListed(array $filters, ?User $user = null): array
{
    return CandidatureListQuery::build($user ?? admin(), CandidatureListQuery::filters($filters))->pluck('candidatures.id')->all();
}
