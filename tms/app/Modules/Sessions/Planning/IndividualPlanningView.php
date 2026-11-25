<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Catalogue\Trainings;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Sessions\TrainingSession;

/**
 * What the « Planning individuel » panel shows of a trainee: his hours
 * (base and computed), the modules of his session (exempted or not) and
 * the séances of his planning in order - his session's ones (exempted
 * included, greyed) and the added ones - with his hours and what blocks a
 * change (attendance entered, closed month, cancelled séance).
 */
final class IndividualPlanningView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Enrolment $enrolment, bool $canEdit): array
    {
        $session = TrainingSession::withTrashed()->findOrFail($enrolment->training_session_id);
        $base = SessionModules::minutesOf($enrolment->planned_hours_base ?? $enrolment->planned_hours ?? $session->planned_hours) ?? 0;
        $planned = SessionModules::minutesOf($enrolment->planned_hours ?? $session->planned_hours) ?? 0;
        $adjustments = EnrolmentAdjustment::query()->where('enrolment_id', $enrolment->id)->get();
        $byModule = $adjustments->where('kind', EnrolmentAdjustment::EXEMPT_MODULE)->keyBy('session_module_id');
        $bySeance = $adjustments->whereNotNull('seance_id')->keyBy('seance_id');
        $modules = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->orderBy('id')->get();

        // His séances (at his hours) and the exempted ones of his session, in order.
        $his = collect(IndividualPlanning::seancesOf(collect([$enrolment]))[$enrolment->id] ?? [])->keyBy(fn (PlannedSeance $p) => $p->seance->id);
        $exempted = Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->whereNotIn('id', $his->keys()->all())
            ->when($enrolment->entered_on !== null, fn ($q) => $q->where('date', '>=', $enrolment->entered_on?->toDateString()))
            ->when($enrolment->left_on !== null, fn ($q) => $q->where('date', '<=', $enrolment->left_on?->toDateString()))
            ->get();
        $all = $his->map(fn (PlannedSeance $p) => $p->seance)->values()->merge($exempted)
            ->sortBy(fn (Seance $s) => $s->date->toDateString().' '.$s->starts_at.' '.$s->id)->values();

        $titles = SessionModule::withTrashed()->whereIn('id', $all->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $attended = Attendance::query()->where('enrolment_id', $enrolment->id)->whereIn('seance_id', $all->pluck('id')->all())->pluck('seance_id')->flip();
        $otherSessions = TrainingSession::withTrashed()->whereKey($all->pluck('training_session_id')->unique()->reject(fn ($id) => $id === $session->id)->values()->all())->get();
        $trainings = app(Trainings::class)->findMany($otherSessions->pluck('training_id')->unique()->values()->all());
        $labels = $otherSessions->mapWithKeys(fn (TrainingSession $s) => [$s->id => $s->label($trainings[$s->training_id]->title ?? null)]);
        $closed = AttendanceClosure::query()->whereIn('training_session_id', $all->pluck('training_session_id')->unique()->values()->all())
            ->whereNotNull('closed_at')->get(['training_session_id', 'month'])->mapWithKeys(fn (AttendanceClosure $c) => [$c->training_session_id.'|'.Months::key($c->month) => true]);

        return [
            'enrolment_id' => $enrolment->id,
            'can_edit' => $canEdit,
            'base_minutes' => $base,
            'planned_minutes' => $planned,
            'base' => SessionModules::label($base),
            'planned' => SessionModules::label($planned),
            'has_adjustments' => $adjustments->isNotEmpty(),
            'modules' => $modules->map(fn (SessionModule $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'mode' => $m->dating_mode,
                'planned' => $m->planned_minutes === null ? SessionModules::UNDEFINED : SessionModules::label($m->planned_minutes),
                'exempted' => $byModule->has($m->id),
                'adjustment_id' => $byModule->get($m->id)?->id,
            ])->values()->all(),
            'seances' => $all->map(function (Seance $s) use ($his, $bySeance, $titles, $attended, $labels, $closed): array {
                $planned = $his->get($s->id);
                $adjustment = $bySeance->get($s->id);
                $kind = $planned === null ? 'exempted' : ($planned->added ? 'added' : 'normal');

                return [
                    'id' => $s->id,
                    'date' => $s->date->toDateString(),
                    'start' => $planned->start ?? $s->start(),
                    'end' => $planned->end ?? $s->end(),
                    'seance_start' => $s->start(),
                    'seance_end' => $s->end(),
                    'duration' => SessionModules::label($planned->minutes ?? $s->duration_minutes),
                    'module' => $titles[$s->session_module_id] ?? null,
                    'session_label' => $labels[$s->training_session_id] ?? null,
                    'kind' => $kind,
                    'own_hours' => $adjustment?->hasOwnHours() ?? false,
                    'adjustment_id' => $adjustment?->id,
                    'reason' => $adjustment?->reason,
                    'module_exempted' => $planned === null && $adjustment === null,
                    'has_attendance' => $attended->has($s->id),
                    'closed' => isset($closed[$s->training_session_id.'|'.$s->date->format('Y-m')]),
                ];
            })->values()->all(),
        ];
    }
}
