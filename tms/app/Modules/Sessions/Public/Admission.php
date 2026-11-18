<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Str;

/**
 * What the Admissions module needs from Sessions (which never imports it):
 * the sessions a candidate may aim at, and (Task 8) the dossier created at
 * the admission.
 */
final class Admission
{
    /**
     * Sessions of $trainingId a candidate may aim at: planned or confirmed,
     * not finished (Paris days), out of the trash, by start date.
     *
     * @return list<array{id: string, label: string, starts_on: string}>
     */
    public static function sessionOptions(string $trainingId): array
    {
        if (! Str::isUuid($trainingId)) {
            return [];
        }
        $title = app(Trainings::class)->find($trainingId)?->title;

        return TrainingSession::query()->where('training_id', $trainingId)
            ->whereIn('status', [SessionStatus::Planned->value, SessionStatus::Confirmed->value])
            ->whereDate('ends_on', '>=', TrainingSession::today()->toDateString())
            ->orderBy('starts_on')->orderBy('number')->get()
            ->map(fn (TrainingSession $s) => ['id' => $s->id, 'label' => $s->label($title), 'starts_on' => $s->starts_on->toDateString()])
            ->values()->all();
    }

    /**
     * A session (trashed included) as a candidature shows it; null when unknown.
     *
     * @return array{id: string, label: string, training_id: string, starts_on: string, ends_on: string, trashed: bool}|null
     */
    public static function session(string $sessionId): ?array
    {
        $session = Str::isUuid($sessionId) ? TrainingSession::withTrashed()->find($sessionId) : null;
        if ($session === null) {
            return null;
        }

        return [
            'id' => $session->id,
            'label' => $session->label(app(Trainings::class)->find($session->training_id)?->title),
            'training_id' => $session->training_id,
            'starts_on' => $session->starts_on->toDateString(),
            'ends_on' => $session->ends_on->toDateString(),
            'trashed' => $session->trashed(),
        ];
    }
}
