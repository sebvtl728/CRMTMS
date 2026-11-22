<?php

namespace App\Modules\Admissions\Candidatures;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * « Créer le dossier » of an admitted candidate: the dialog's defaults, then
 * the dossier through Sessions\Public\Admission. One live dossier per
 * candidature (candidature row lock); a dossier put in the trash may be
 * replaced by a new one.
 */
final class DossierCreation
{
    /**
     * The dialog prefilled: the employer of the contract as contractant (its
     * company price), else the entreprise of an intra targeted session (only
     * its own trainees may join it), else the person when she is a
     * particulier (individual price), else none; the targeted session; a
     * funding by the OPCO of the contract (type and amount left to the user).
     * The user may still switch: person_account (the candidate as particulier,
     * null when she is not one) and both prices come along.
     *
     * @return array<string, mixed>
     */
    public function defaults(Candidature $candidature): array
    {
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first();
        $training = $candidature->training_id !== null ? app(Trainings::class)->find($candidature->training_id) : null;
        $session = $candidature->target_session_id !== null ? Admission::session($candidature->target_session_id) : null;
        $intraOf = $session !== null && $session['kind'] === 'intra' ? $session['organisation_id'] : null;
        $person = app(Fiches::class)->individual($candidature->person_id) !== null ? ['type' => 'person', 'id' => $candidature->person_id] : null;
        $account = match (true) {
            $contract !== null => ['type' => 'organisation', 'id' => $contract->employer_organisation_id],
            $intraOf !== null => ['type' => 'organisation', 'id' => $intraOf],
            default => $person,
        };
        $price = fn (?int $cents): string => $cents === null ? '' : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
        $prices = ['organisation' => $price($training?->price_company_cents), 'person' => $price($training?->price_individual_cents)];

        return [
            'account' => $account,
            'account_label' => $account !== null && $account['type'] === 'organisation' ? app(Fiches::class)->organisation($account['id'])['label'] ?? null : null,
            'person_account' => $person,
            'prices' => $prices,
            'das_id' => $candidature->das_id,
            'responsible_user_id' => $candidature->responsible_user_id,
            'training_id' => $candidature->training_id,
            'label' => $training->title ?? '',
            'quantity' => '1',
            'unit' => 'stagiaire',
            'unit_price' => $account === null ? '' : $prices[$account['type']],
            'session_id' => $candidature->target_session_id,
            'funding' => $contract?->opco_organisation_id === null ? null : [
                'funder_organisation_id' => $contract->opco_organisation_id,
                'funding_type_id' => null,
                'amount' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return string the dossier id
     */
    public function create(Candidature $candidature, array $data, User $by): string
    {
        $values = Validator::make($data, [
            'account' => ['required', 'array'],
            'account.type' => ['required', Rule::in(['organisation', 'person'])],
            'account.id' => ['required', 'uuid'],
            'company_id' => ['nullable', 'uuid'],
            'responsible_user_id' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'unit_price' => ['required', 'string'],
            'session_id' => ['nullable', 'uuid'],
            'funding' => ['nullable', 'array'],
            'funding.funder_organisation_id' => ['required_with:funding', 'uuid'],
            'funding.funding_type_id' => ['required_with:funding', 'uuid'],
            'funding.amount' => ['required_with:funding', 'string'],
        ], [
            'account.required' => 'Choisissez le contractant (entreprise ou particulier).',
            'account.id.required' => 'Choisissez le contractant (entreprise ou particulier).',
            'unit_price.required' => 'Indiquez le prix.',
            'funding.funding_type_id.required_with' => 'Choisissez le type de financement.',
            'funding.amount.required_with' => 'Indiquez le montant du financement.',
            'required' => 'Ce champ est obligatoire.',
            'uuid' => 'Valeur inconnue.',
        ])->validate();

        return DB::transaction(function () use ($candidature, $values): string {
            /** @var Candidature $locked */
            $locked = Candidature::query()->lockForUpdate()->findOrFail($candidature->getKey());
            if ($locked->status !== CandidatureStatus::Admitted) {
                throw new UserFacingHttpException(409, 'Seul un candidat admis devient apprenant.');
            }
            if ($locked->dossier_id !== null && Admission::dossierState($locked->dossier_id) === 'live') {
                throw new UserFacingHttpException(409, 'Le dossier de cette candidature existe déjà.');
            }
            if ($locked->training_id === null) {
                throw new UserFacingHttpException(409, 'Choisissez la formation visée avant de créer le dossier.');
            }

            $dossierId = Admission::createDossier([
                'account' => $values['account'],
                'company_id' => $values['company_id'] ?? null,
                'das_id' => $locked->das_id,
                'responsible_user_id' => $values['responsible_user_id'] ?? null,
                'training_id' => $locked->training_id,
                'label' => $values['label'],
                'quantity' => $values['quantity'],
                'unit' => $values['unit'],
                'unit_price' => str_replace([' ', ','], ['', '.'], $values['unit_price']),
                'session_id' => $values['session_id'] ?? null,
                'person_id' => $locked->person_id,
                'funding' => ($values['funding'] ?? null) === null ? null : [
                    'funder_organisation_id' => $values['funding']['funder_organisation_id'],
                    'funding_type_id' => $values['funding']['funding_type_id'],
                    'amount' => str_replace([' ', ','], ['', '.'], $values['funding']['amount']),
                ],
            ]);
            $locked->forceFill(['dossier_id' => $dossierId])->save();
            $candidature->setRawAttributes($locked->getAttributes(), true);

            return $dossierId;
        });
    }
}
