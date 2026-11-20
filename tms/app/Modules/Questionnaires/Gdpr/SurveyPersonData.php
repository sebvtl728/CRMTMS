<?php

namespace App\Modules\Questionnaires\Gdpr;

use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use Illuminate\Support\Facades\DB;

/**
 * What the questionnaires keep on a person, for the CRM (FicheLinks):
 * linked data (an invitation keeps the fiche on an import undo), merge
 * (repoint; one invitation per campaign, the answered one wins), anonymisation
 * (address and free texts cleared, marks and choices kept, links closed) and
 * the RGPD export.
 */
final class SurveyPersonData
{
    public static function hasLinked(string $type, string $id): bool
    {
        return $type === 'person' && SurveyInvitation::query()->where('person_id', $id)->exists();
    }

    public static function merge(string $type, string $keepId, string $dropId): void
    {
        if ($type !== 'person') {
            return;
        }
        $kept = SurveyInvitation::query()->where('person_id', $keepId)->get()->keyBy('campaign_id');
        foreach (SurveyInvitation::query()->where('person_id', $dropId)->get() as $dropped) {
            $mine = $kept[$dropped->campaign_id] ?? null;
            if ($mine === null) {
                $dropped->forceFill(['person_id' => $keepId])->save();

                continue;
            }
            // Both invited to the same questionnaire: keep the answered one.
            if ($mine->answered_at === null && $dropped->answered_at !== null) {
                $mine->delete();
                $dropped->forceFill(['person_id' => $keepId])->save();
            } else {
                $dropped->delete();
            }
        }
    }

    /** @param  list<string>  $personIds */
    public static function anonymise(array $personIds): void
    {
        $invitations = SurveyInvitation::query()->whereIn('person_id', $personIds)->get();
        if ($invitations->isEmpty()) {
            return;
        }
        $questions = SurveyCampaign::query()->whereKey($invitations->pluck('campaign_id')->unique()->values())->get(['id', 'questions'])->keyBy('id');
        DB::transaction(function () use ($invitations, $questions): void {
            foreach ($invitations as $invitation) {
                $texts = collect($questions[$invitation->campaign_id]->questions ?? [])->where('type', 'text')->pluck('id')->all();
                $response = SurveyResponse::query()->where('invitation_id', $invitation->id)->first();
                if ($response !== null && $texts !== []) {
                    $response->forceFill(['answers' => array_diff_key($response->answers, array_flip($texts))])->save();
                }
                // The link stops working: a new hash whose token nobody knows.
                $invitation->forceFill(['email' => null, 'token_hash' => SurveyInvitation::newToken()[1]])->save();
            }
        });
    }

    /** @return array{questionnaires: list<array<string, mixed>>} */
    public static function export(string $personId): array
    {
        $invitations = SurveyInvitation::query()->with(['campaign', 'response'])->where('person_id', $personId)->orderBy('created_at')->get();

        return ['questionnaires' => $invitations->map(function (SurveyInvitation $i): array {
            $answers = [];
            foreach ($i->campaign->questions as $q) {
                $value = $i->response?->answers[$q['id']] ?? null;
                if ($value !== null) {
                    $answers[$q['label']] = $value;
                }
            }

            return [
                'questionnaire' => $i->campaign->title,
                'envoye_le' => $i->sent_at?->setTimezone('Europe/Paris')->toDateString(),
                'repondu_le' => $i->answered_at?->setTimezone('Europe/Paris')->toDateString(),
                'reponses' => $answers,
            ];
        })->values()->all()];
    }
}
