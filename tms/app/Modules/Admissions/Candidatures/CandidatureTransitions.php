<?php

namespace App\Modules\Admissions\Candidatures;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\CustomFields\CustomFieldValues;
use App\Modules\Admissions\Support\Rules;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only way a candidature changes stage, under a row lock. Forward jumps
 * are allowed (admitted without a test); going back too, while no live
 * dossier exists. Rejected and withdrawn leave the path from any stage before
 * the admission (rejected needs a reason of candidature_refusal_reason);
 * back on the path, the reason goes. Admitted needs the training.
 */
final class CandidatureTransitions
{
    public const TEST_REQUIRED = 'Le test de positionnement est obligatoire : indiquez sa date de réception avant l\'entretien.';

    public function move(Candidature $candidature, CandidatureStatus $to, User $by, ?string $reasonId = null): void
    {
        DB::transaction(function () use ($candidature, $to, $reasonId): void {
            /** @var Candidature $locked */
            $locked = Candidature::query()->lockForUpdate()->findOrFail($candidature->getKey());
            $from = $locked->status;

            if ($from === $to) {
                throw new UserFacingHttpException(409, 'La candidature est déjà à cette étape.');
            }
            if ($from === CandidatureStatus::Admitted && $locked->dossier_id !== null && Admission::dossierState($locked->dossier_id) === 'live') {
                throw new UserFacingHttpException(409, 'Le dossier de formation est créé : la candidature ne peut plus revenir en arrière.');
            }
            if ($from === CandidatureStatus::Admitted && $to->isExit()) {
                throw new UserFacingHttpException(409, 'Un candidat admis ne peut qu\'être ramené à une étape précédente.');
            }
            if ($from->isExit() && $to->isExit()) {
                throw new UserFacingHttpException(409, 'Ramenez d\'abord la candidature à une étape du parcours.');
            }

            $changes = ['status' => $to->value];
            if ($to === CandidatureStatus::Admitted && $locked->training_id === null) {
                throw ValidationException::withMessages(['training_id' => 'Choisissez la formation visée avant d\'admettre le candidat.']);
            }
            if ($to === CandidatureStatus::Admitted && ($missing = CustomFieldValues::missingForAdmission($locked)) !== []) {
                throw ValidationException::withMessages(['custom_values' => 'À remplir avant l\'admission : '.implode(', ', $missing).'.']);
            }
            // The positioning test is compulsory: back before the interview and the admission.
            if (in_array($to, [CandidatureStatus::Interview, CandidatureStatus::Admitted], true) && $locked->positioning_received_on === null) {
                throw ValidationException::withMessages(['positioning_received_on' => self::TEST_REQUIRED]);
            }

            if ($to === CandidatureStatus::Rejected) {
                Validator::make(['decision_reason_id' => $reasonId], ['decision_reason_id' => ['required', ...Rules::item(ReferenceList::CandidatureRefusalReason)]], [
                    'required' => 'Indiquez le motif.',
                    'uuid' => 'Indiquez le motif.',
                    'exists' => 'Ce motif n\'existe pas.',
                ])->validate();
                $changes['decision_reason_id'] = $reasonId;
            } else {
                $changes['decision_reason_id'] = null;
            }

            $locked->forceFill($changes)->save();
            $candidature->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
