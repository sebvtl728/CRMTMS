<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Resources\Rooms;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainer;
use Illuminate\Support\Collection;

/**
 * The séances of the « Planning » tab of a session (those the viewer's
 * sessions.seances.view scope reaches), with their alerts, and what its
 * calendar shades: public holidays, closures, the working days of its
 * interval modules. One query per kind of data.
 */
final class SeanceRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forSession(TrainingSession $session, User $viewer): array
    {
        $seances = SessionsVisibility::seances(Seance::query()->where('training_session_id', $session->id), $viewer)
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->get();
        if ($seances->isEmpty()) {
            return [];
        }
        $modules = SessionModule::withTrashed()->whereIn('id', $seances->pluck('session_module_id')->unique()->all())->pluck('title', 'id');
        $trainers = self::trainerLabels($seances->pluck('trainer_id')->filter()->unique()->values()->all());
        $rooms = Room::withTrashed()->whereIn('id', $seances->pluck('room_id')->filter()->unique()->all())->get()->keyBy('id');
        $planned = $seances->reject(fn (Seance $s) => $s->isCancelled())->values();
        $alerts = array_combine($planned->pluck('id')->all(), Conflicts::forMany($planned->all()) ?: []) ?: [];

        return $seances->map(fn (Seance $s) => [
            'id' => $s->id,
            'module_id' => $s->session_module_id,
            'module' => $modules[$s->session_module_id] ?? '—',
            'date' => $s->date->toDateString(),
            'start' => $s->start(),
            'end' => $s->end(),
            'break_minutes' => $s->break_minutes,
            'minutes' => $s->duration_minutes,
            'duration' => SessionModules::label($s->duration_minutes),
            'trainer' => $s->trainer_id === null ? null : ['id' => $s->trainer_id, 'label' => $trainers[$s->trainer_id] ?? 'Formateur introuvable'],
            'room' => $s->room_id === null || ! $rooms->has($s->room_id) ? null : ['id' => $s->room_id, 'name' => $rooms[$s->room_id]->name],
            'place_text' => $s->place_text,
            'status' => $s->status,
            'cancel_reason' => $s->cancel_reason,
            'note' => $s->note,
            'alerts' => array_map(fn (Conflict $c) => $c->toArray(), $alerts[$s->id] ?? []),
        ])->values()->all();
    }

    /**
     * What the calendar of the session shades and shows: holidays and
     * closures within its dates, the working days of its interval modules.
     *
     * @return array{holidays: array<string, string>, closures: list<array{starts_on: string, ends_on: string, label: string}>, interval_days: array<string, list<string>>}
     */
    public static function calendar(TrainingSession $session): array
    {
        $from = $session->starts_on->toDateString();
        $to = $session->ends_on->toDateString();
        $closures = ClosurePeriods::between($session->starts_on, $session->ends_on);
        $days = [];
        foreach (SessionModule::query()->where('training_session_id', $session->id)->where('dating_mode', 'interval')->get() as $module) {
            $days[$module->id] = WorkingDays::between($module->starts_on?->toDateString() ?? $from, $module->ends_on?->toDateString() ?? $to, $closures);
        }

        return [
            'holidays' => PublicHolidays::between($from, $to),
            'closures' => self::closures($closures),
            'interval_days' => $days,
        ];
    }

    /**
     * @param  Collection<int, ClosurePeriod>  $closures
     * @return list<array{starts_on: string, ends_on: string, label: string}>
     */
    public static function closures(Collection $closures): array
    {
        return $closures->map(fn (ClosurePeriod $c) => [
            'starts_on' => $c->starts_on->toDateString(),
            'ends_on' => $c->ends_on->toDateString(),
            'label' => $c->label,
        ])->values()->all();
    }

    /**
     * Choices of the séance form and the assistant: active trainers and
     * rooms, the modules dated in séances, default slots.
     *
     * @return array<string, mixed>
     */
    public static function options(TrainingSession $session): array
    {
        $trainers = Trainer::query()->where('active', true)->get(['id', 'person_id']);
        $labels = app(Fiches::class)->personLabels($trainers->pluck('person_id')->map(fn ($id) => (string) $id)->all());

        return [
            'trainers' => $trainers->map(fn (Trainer $t) => ['value' => $t->id, 'label' => $labels[$t->person_id]['label'] ?? 'Formateur'])
                ->sortBy(fn (array $o) => mb_strtolower($o['label']))->values()->all(),
            'rooms' => Rooms::active()->map(fn (Room $r) => [
                'value' => $r->id,
                'label' => $r->capacity === null ? $r->name : "{$r->name} ({$r->capacity} places)",
            ])->values()->all(),
            'slots' => [
                ['start' => '09:00', 'end' => '12:30', 'break_minutes' => 0],
                ['start' => '13:30', 'end' => '17:00', 'break_minutes' => 0],
            ],
            'weekdays' => [1, 2, 3, 4, 5],
        ];
    }

    /**
     * @param  list<string>  $trainerIds
     * @return array<string, string>
     */
    public static function trainerLabels(array $trainerIds): array
    {
        if ($trainerIds === []) {
            return [];
        }
        $trainers = Trainer::withTrashed()->whereIn('id', $trainerIds)->pluck('person_id', 'id');
        $labels = app(Fiches::class)->personLabels($trainers->values()->map(fn ($id) => (string) $id)->all());

        return $trainers->map(fn ($personId) => $labels[$personId]['label'] ?? 'Formateur introuvable')->all();
    }
}
