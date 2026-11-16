<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Rows of the sessions list, the calendar and the session page: the
 * training (Catalogue\Trainings), the entreprise (Crm\Public\Fiches) and
 * the DAS read in one query each, French formats, effective state of $today.
 */
final class SessionRows
{
    /**
     * @param  iterable<TrainingSession>  $sessions
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $sessions, CarbonImmutable $today): array
    {
        $sessions = (new EloquentCollection(Collection::make($sessions)->all()))->loadMissing('responsible:id,name');
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());
        $organisations = app(Fiches::class)->organisationLabels($sessions->pluck('organisation_id')->filter()->unique()->values()->all());
        $das = ReferenceItem::query()->whereKey($sessions->pluck('das_id')->unique()->values()->all())->pluck('label', 'id')->all();
        $modalities = ReferenceItem::query()->withTrashed()->whereKey($sessions->pluck('modality_id')->unique()->values()->all())->pluck('label', 'id')->all();

        return $sessions->map(fn (TrainingSession $s) => self::row($s, $trainings[$s->training_id] ?? null, $organisations, $das, $modalities, $today))->values()->all();
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
