<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\ReferenceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes actions: quick entry, « Fait » on an action to do, and the next
 * follow-up (an action to do on the same fiches and opportunity, for the
 * same responsible), « Modifier » and « Confier à… ». The contact date of
 * the fiches follows the model events (Activity::booted).
 *
 * An action handed to someone else than the user who acts (creation, edit
 * or « Confier à… ») is journaled (crm.activity_assigned, user ids only: the
 * « Action confiée à X par Y » of the history) and, while to do, announced
 * once committed (ActivityAssigned: e-mail to the new responsible).
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
            $this->announceAssignment($activity, null);

            return [$activity, $next === null || $activity->done_at === null ? null : $this->followUp($activity, $next['type_id'], $next['due_at'])];
        });
    }

    /**
     * « Modifier »: validated values (ActivityRequest::values).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Activity $activity, array $attributes): void
    {
        DB::transaction(function () use ($activity, $attributes): void {
            $before = $activity->responsible_user_id;
            $activity->fill($attributes)->save();
            $this->announceAssignment($activity, $before);
        });
    }

    /**
     * « Confier à… »: a new responsible, with an optional message for him.
     */
    public function assign(Activity $activity, string $userId, ?string $message = null): void
    {
        DB::transaction(function () use ($activity, $userId, $message): void {
            $before = $activity->responsible_user_id;
            $activity->forceFill(['responsible_user_id' => $userId])->save();
            $this->announceAssignment($activity, $before, $message);
        });
    }

    /**
     * Journals and announces a responsible that changed to someone else
     * than the acting user; nothing otherwise (given to oneself, unchanged).
     */
    private function announceAssignment(Activity $activity, ?string $before, ?string $message = null): void
    {
        $after = $activity->responsible_user_id;
        $by = Auth::id();
        if (! is_string($by) || $after === null || $after === $before || $after === $by) {
            return;
        }

        app(AuditRecorder::class)->record('crm.activity_assigned', $activity, ['responsible_user_id' => $before], ['responsible_user_id' => $after]);
        if ($activity->done_at === null) {
            ActivityAssigned::dispatch($activity->id, $by, $message);
        }
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
