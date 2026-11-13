<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creation and stage moves of an opportunity, whatever the screen (list,
 * fiche, kanban): closing rules, probability and history live here.
 *
 * - to « lost »: a loss reason is required, lost_on = today (Paris);
 * - to « won »: won_on = the given date, today by default;
 * - leaving won / lost clears these fields;
 * - the probability follows the stage, unless the user typed one since the
 *   last move (kept once, then the flag is reset); won / lost always force
 *   100 / 0;
 * - a history row per move; last_contact_at is never touched (only done
 *   actions and notes are contacts).
 */
final class OpportunityStage
{
    public const LOSS_REASON_REQUIRED = 'Le motif de perte est obligatoire.';

    /**
     * @param  array<string, mixed>  $attributes  validated fields (probability null = stage default)
     * @param  list<string>  $contactIds
     */
    public function create(array $attributes, array $contactIds = []): Opportunity
    {
        return DB::transaction(function () use ($attributes, $contactIds): Opportunity {
            $stage = ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->findOrFail($attributes['stage_id']);
            $default = self::defaultProbability($stage);
            $probability = isset($attributes['probability']) ? (int) $attributes['probability'] : $default;

            $opportunity = new Opportunity;
            $opportunity->fill([...$attributes, 'probability' => $probability, 'probability_overridden' => $probability !== $default])->save();

            if ($opportunity->person_id !== null && $contactIds === []) {
                $contactIds = [$opportunity->person_id];
            }
            $this->syncContacts($opportunity, $contactIds);
            $this->record($opportunity, null, $stage);

            return $opportunity;
        });
    }

    /**
     * Probability typed on the form: an override unless it is the stage default.
     */
    public function setProbability(Opportunity $opportunity, int $probability): void
    {
        if ($probability === $opportunity->probability) {
            return;
        }

        $stage = ReferenceItem::withTrashed()->findOrFail($opportunity->stage_id);
        $opportunity->probability = $probability;
        $opportunity->probability_overridden = $probability !== self::defaultProbability($stage);
    }

    /**
     * @param  list<string>  $personIds
     */
    public function syncContacts(Opportunity $opportunity, array $personIds): void
    {
        $tenant = ['tenant_id' => $opportunity->tenant_id];
        $opportunity->contacts()->sync(collect($personIds)->unique()->mapWithKeys(fn (string $id) => [$id => $tenant])->all());
    }

    public function move(Opportunity $opportunity, ReferenceItem $to, ?string $lossReasonId, ?string $lossComment, ?CarbonImmutable $wonOn): void
    {
        if ($to->list !== ReferenceList::PipelineStage) {
            throw ValidationException::withMessages(['stage_id' => 'Cette étape n\'existe pas.']);
        }

        $outcome = self::outcome($to);
        if ($outcome === 'lost' && ($lossReasonId === null || $lossReasonId === '')) {
            throw ValidationException::withMessages(['loss_reason_id' => self::LOSS_REASON_REQUIRED]);
        }
        if ($outcome === 'lost' && ! ReferenceItem::query()->where('list', ReferenceList::LossReason)->whereKey($lossReasonId)->exists()) {
            throw ValidationException::withMessages(['loss_reason_id' => 'Ce motif de perte n\'existe pas.']);
        }

        DB::transaction(function () use ($opportunity, $to, $outcome, $lossReasonId, $lossComment, $wonOn): void {
            $locked = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->getKey());
            if ($locked->stage_id === $to->getKey()) {
                return;
            }

            $from = ReferenceItem::withTrashed()->find($locked->stage_id);
            $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

            $locked->stage_id = $to->getKey();
            $locked->won_on = $outcome === 'won' ? ($wonOn?->toDateString() ?? $today) : null;
            $locked->lost_on = $outcome === 'lost' ? $today : null;
            $locked->loss_reason_id = $outcome === 'lost' ? $lossReasonId : null;
            $locked->loss_comment = $outcome === 'lost' && $lossComment !== null && trim($lossComment) !== '' ? trim($lossComment) : null;
            if ($outcome !== null || ! $locked->probability_overridden) {
                $locked->probability = self::defaultProbability($to);
            }
            $locked->probability_overridden = false;
            $locked->save();

            $this->record($locked, $from, $to);
            $opportunity->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public static function defaultProbability(ReferenceItem $stage): int
    {
        return max(0, min(100, (int) ($stage->meta['probability'] ?? 0)));
    }

    /** « won », « lost » or null (open stage). */
    public static function outcome(ReferenceItem $stage): ?string
    {
        $outcome = $stage->meta['outcome'] ?? null;

        return in_array($outcome, ['won', 'lost'], true) ? $outcome : null;
    }

    private function record(Opportunity $opportunity, ?ReferenceItem $from, ReferenceItem $to): void
    {
        StageChange::query()->create([
            'opportunity_id' => $opportunity->getKey(),
            'from_stage_id' => $from?->getKey(),
            'to_stage_id' => $to->getKey(),
            'changed_by' => Auth::id(),
            'changed_at' => CarbonImmutable::now(),
        ]);
    }
}
