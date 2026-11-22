<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Summary\SessionChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Rows of the sessions list, the calendar and the session page: the
 * training (Catalogue\Trainings), the entreprise (Crm\Public\Fiches) and
 * the DAS read in one query each, French formats, effective state of $today.
 * With $progressFor (the kanban's cards): the hours planned and scheduled,
 * the trainees holding a place and the « Synthèse » cards to do or in alert
 * that this user sees, again in grouped queries.
 */
final class SessionRows
{
    /**
     * @param  iterable<TrainingSession>  $sessions
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $sessions, CarbonImmutable $today, ?User $progressFor = null): array
    {
        $sessions = (new EloquentCollection(Collection::make($sessions)->all()))->loadMissing('responsible:id,name');
        $progress = $progressFor === null ? [] : self::progress($sessions, $progressFor, $today);
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());
        $organisations = app(Fiches::class)->organisationLabels($sessions->pluck('organisation_id')->filter()->unique()->values()->all());
        $das = ReferenceItem::query()->whereKey($sessions->pluck('das_id')->unique()->values()->all())->pluck('label', 'id')->all();
        $modalities = ReferenceItem::query()->withTrashed()->whereKey($sessions->pluck('modality_id')->unique()->values()->all())->pluck('label', 'id')->all();

        return $sessions->map(fn (TrainingSession $s) => [
            ...self::row($s, $trainings[$s->training_id] ?? null, $organisations, $das, $modalities, $today),
            ...($progress[$s->id] ?? []),
        ])->values()->all();
    }

    /**
     * Per session: minutes planned (Σ of the modules' planned hours) and
     * scheduled (spec § 6.1: a séance module counts its planned séances, an
     * interval module its planned hours, never both), trainees holding a
     * place, pending cards of the « Synthèse » for $user.
     *
     * @param  EloquentCollection<int, TrainingSession>  $sessions
     * @return array<string, array{planned_minutes: int, scheduled_minutes: int, hours_label: string, enrolled: int, pending: int}>
     */
    private static function progress(EloquentCollection $sessions, User $user, CarbonImmutable $today): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        $ids = $sessions->pluck('id')->all();
        $modules = SessionModule::query()->whereIn('training_session_id', $ids)
            ->get(['id', 'training_session_id', 'planned_minutes', 'dating_mode'])->groupBy('training_session_id');
        /** @var array<string, int|string> $seanceMinutes */
        $seanceMinutes = Seance::query()->whereIn('training_session_id', $ids)->where('status', Seance::PLANNED)
            ->groupBy('session_module_id')->selectRaw('session_module_id, sum(duration_minutes) as minutes')
            ->pluck('minutes', 'session_module_id')->all();
        /** @var array<string, int|string> $enrolled */
        $enrolled = Enrolment::query()->whereIn('training_session_id', $ids)
            ->whereIn('status', array_map(fn (EnrolmentStatus $s) => $s->value, EnrolmentStatus::HOLDING_A_PLACE))
            ->groupBy('training_session_id')->selectRaw('training_session_id, count(*) as n')
            ->pluck('n', 'training_session_id')->all();
        $checklists = SessionChecklist::forMany($sessions, $user, $today);

        $out = [];
        foreach ($sessions as $session) {
            $planned = 0;
            $scheduled = 0;
            foreach ($modules[$session->id] ?? [] as $module) {
                $planned += $module->planned_minutes ?? 0;
                $scheduled += $module->dating_mode === DatingMode::Seances->value
                    ? (int) ($seanceMinutes[$module->id] ?? 0)
                    : ($module->planned_minutes ?? 0);
            }
            $out[$session->id] = [
                'planned_minutes' => $planned,
                'scheduled_minutes' => $scheduled,
                'hours_label' => SessionModules::label($scheduled).' / '.SessionModules::label($planned),
                'enrolled' => (int) ($enrolled[$session->id] ?? 0),
                'pending' => SessionChecklist::pending($checklists[$session->id] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(TrainingSession $session, CarbonImmutable $today): array
    {
        return self::rows([$session], $today)[0];
    }

    /**
     * « 21.00 » → « 21,00 » (no float).
     */
    public static function hours(?string $hours): ?string
    {
        return $hours === null ? null : str_replace('.', ',', $hours);
    }

    /**
     * @param  array<string, array{label: string, trashed: bool}>  $organisations
     * @param  array<string, string>  $das
     * @param  array<string, string>  $modalities
     * @return array<string, mixed>
     */
    private static function row(TrainingSession $s, ?TrainingData $training, array $organisations, array $das, array $modalities, CarbonImmutable $today): array
    {
        $status = $s->effectiveStatus($today);
        $organisation = $s->organisation_id === null ? null : ($organisations[$s->organisation_id] ?? null);

        return [
            'id' => $s->id,
            'number' => $s->number,
            'color' => $s->color,
            'color' => $s->color,
            'title' => $s->title,
            'label' => $s->label($training?->title),
            'training' => $training === null ? null : ['id' => $training->id, 'code' => $training->code, 'title' => $training->title, 'is_active' => $training->is_active],
            'das' => $das[$s->das_id] ?? null,
            'kind' => $s->kind,
            'kind_label' => SessionKind::from($s->kind)->label(),
            'modality' => $modalities[$s->modality_id] ?? null,
            'organisation' => $organisation === null ? null : ['id' => $s->organisation_id, 'label' => $organisation['label'], 'trashed' => $organisation['trashed']],
            'starts_on' => $s->starts_on->toDateString(),
            'ends_on' => $s->ends_on->toDateString(),
            'planned_hours' => self::hours($s->planned_hours),
            'location_kind' => $s->location_kind,
            'location_label' => SessionLocation::from($s->location_kind)->label(),
            'location_text' => $s->location_text,
            'capacity' => $s->capacity,
            'responsible' => $s->responsible?->name,
            'status' => $status->value,
            'status_label' => $status->label(),
            'trashed' => $s->trashed(),
        ];
    }
}
