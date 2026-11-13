<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes actions: quick entry, « Fait » on an action to do, and the next
 * follow-up (an action to do on the same fiches and opportunity, for the
 * same responsible). The contact date of the fiches follows the model
 * events (Activity::booted).
 */
final class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $attributes  validated (ActivityRequest::values)
     * @param  array{type_id: string, due_at: CarbonImmutable}|null  $next
     * @return array{0: Activity, 1: Activity|null} the action and its follow-up
     */
    public function create(array $attributes, ?array $next = null): array
    {
        return DB::transaction(function () use ($attributes, $next): array {
            $activity = new Activity($attributes);
            $this->fillFicheFromOpportunity($activity);
            if (($activity->subject ?? '') === '') {
                $activity->subject = $this->defaultSubject($activity->type_id, $activity);
            }
            $activity->save();

            return [$activity, $next === null || $activity->done_at === null ? null : $this->followUp($activity, $next['type_id'], $next['due_at'])];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated (CompleteRequest::values)
     * @param  array{type_id: string, due_at: CarbonImmutable}|null  $next
     */
    public function complete(Activity $activity, array $attributes, ?array $next = null): ?Activity
    {
        return DB::transaction(function () use ($activity, $attributes, $next): ?Activity {
            $activity->fill($attributes)->save();

            return $next === null ? null : $this->followUp($activity, $next['type_id'], $next['due_at']);
        });
    }

    public function followUp(Activity $from, string $typeId, CarbonImmutable $dueAt): Activity
    {
        $next = new Activity([
            'type_id' => $typeId,
            'organisation_id' => $from->organisation_id,
            'person_id' => $from->person_id,
            'opportunity_id' => $from->opportunity_id,
            'responsible_user_id' => $from->responsible_user_id,
            'due_at' => $dueAt,
        ]);
        $next->subject = $this->defaultSubject($typeId, $next);
        $next->save();

        return $next;
    }

    /**
     * « Appel – Jean Dupont »: the type, then the fiche.
     */
    public function defaultSubject(string $typeId, Activity $activity): string
    {
        $type = ReferenceItem::withTrashed()->find($typeId)->label ?? 'Action';
        $fiche = $activity->ficheName();

        return mb_substr($fiche === '' ? $type : "{$type} – {$fiche}", 0, 200);
    }

    /**
     * An action on an opportunity also hangs on its fiche (its history,
     * last contact), when no fiche of that kind was given.
     */
    private function fillFicheFromOpportunity(Activity $activity): void
    {
        if ($activity->opportunity_id === null) {
            return;
        }
        $account = DB::table('opportunities')->where('id', $activity->opportunity_id)->first(['organisation_id', 'person_id']);
        if ($account === null) {
            return;
        }
        if (is_string($account->organisation_id)) {
            $activity->organisation_id ??= $account->organisation_id;
        } elseif (is_string($account->person_id)) {
            $activity->person_id ??= $account->person_id;
        }
    }
}
