<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The placement periods (stages en entreprise) of a session: common dates
 * within the session's, a label; per trainee (enrolment of the session) a
 * host entreprise of the CRM (Crm\Public\Fiches, out of the trash when
 * chosen; a host put in the trash later stays shown « (supprimée) ») and
 * own dates that replace the common ones for him (spec § 4 bis 13).
 */
final class PlacementPeriods
{
    /**
     * @param  array<string, mixed>  $data  starts_on, ends_on, label
     *
     * @throws ValidationException
     */
    public static function save(TrainingSession $session, array $data, ?PlacementPeriod $period = null): PlacementPeriod
    {
        Seances::assertSessionOpen($session);
        $data = self::trimmed($data, ['starts_on', 'ends_on', 'label']);
        $start = $session->starts_on->toDateString();
        $end = $session->ends_on->toDateString();
        $validator = Validator::make($data, [
            'starts_on' => ['bail', 'required', 'date_format:Y-m-d'],
            'ends_on' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'label' => ['required', 'string', 'max:120'],
        ], [
            'starts_on.required' => 'Indiquez la date de début.',
            'starts_on.*' => 'Date de début invalide.',
            'ends_on.required' => 'Indiquez la date de fin.',
            'ends_on.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'ends_on.*' => 'Date de fin invalide.',
            'label.required' => 'Indiquez l\'intitulé du stage.',
            'label.*' => 'L\'intitulé ne doit pas dépasser 120 caractères.',
        ]);
        $validator->after(function ($v) use ($data, $start, $end): void {
            $outside = 'Le stage doit rester dans les dates de la session (du '.Conflicts::french($start).' au '.Conflicts::french($end).').';
            foreach (['starts_on', 'ends_on'] as $key) {
                if (! $v->errors()->has($key) && is_string($data[$key]) && ($data[$key] < $start || $data[$key] > $end)) {
                    $v->errors()->add($key, $outside);
                }
            }
        });
        $validator->validate();

        return DB::transaction(function () use ($session, $data, $period): PlacementPeriod {
            $period ??= new PlacementPeriod(['training_session_id' => $session->id]);
            $period->fill(['starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'label' => $data['label']])->save();

            return $period;
        });
    }

    public static function delete(PlacementPeriod $period): void
    {
        self::assertOpen($period);
        DB::transaction(function () use ($period): void {
            $period->hosts()->get()->each(fn (PlacementPeriodHost $h) => $h->delete());
            $period->delete();
        });
    }

    /**
     * The placement of one trainee: host entreprise (optional) and own
     * dates (both or none, any day: they replace the common ones).
     *
     * @param  array<string, mixed>  $data  organisation_id, starts_on, ends_on
     *
     * @throws ValidationException
     */
    public static function setHost(PlacementPeriod $period, Enrolment $enrolment, array $data): PlacementPeriodHost
    {
        self::assertOpen($period);
        $data = self::trimmed($data, ['organisation_id', 'starts_on', 'ends_on']);
        $current = PlacementPeriodHost::query()->where('placement_period_id', $period->id)->where('enrolment_id', $enrolment->id)->first();
        $validator = Validator::make($data, [
            'organisation_id' => ['bail', 'nullable', 'uuid'],
            'starts_on' => ['bail', 'nullable', 'required_with:ends_on', 'date_format:Y-m-d'],
            'ends_on' => ['bail', 'nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'organisation_id.*' => 'Entreprise introuvable.',
            'starts_on.required_with' => 'Indiquez la date de début du stage du stagiaire.',
            'starts_on.*' => 'Date de début invalide.',
            'ends_on.required_with' => 'Indiquez la date de fin du stage du stagiaire.',
            'ends_on.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'ends_on.*' => 'Date de fin invalide.',
        ]);
        $validator->after(function ($v) use ($data, $current): void {
            $id = $data['organisation_id'];
            // The host kept as is may be in the trash meanwhile; a new one must be live.
            if (! $v->errors()->has('organisation_id') && is_string($id) && $id !== $current?->organisation_id
                && app(Fiches::class)->organisation($id) === null) {
                $v->errors()->add('organisation_id', 'Entreprise introuvable.');
            }
        });
        $validator->validate();

        return DB::transaction(function () use ($period, $enrolment, $data, $current): PlacementPeriodHost {
            $host = $current ?? new PlacementPeriodHost(['placement_period_id' => $period->id, 'enrolment_id' => $enrolment->id]);
            $host->fill(['organisation_id' => $data['organisation_id'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on']])->save();

            return $host;
        });
    }

    public static function removeHost(PlacementPeriod $period, Enrolment $enrolment): void
    {
        self::assertOpen($period);
        DB::transaction(fn () => PlacementPeriodHost::query()->where('placement_period_id', $period->id)
            ->where('enrolment_id', $enrolment->id)->get()->each(fn (PlacementPeriodHost $h) => $h->delete()));
    }

    /**
     * The dates of the placement for one trainee: his own, else the common ones.
     *
     * @return array{from: string, to: string}
     */
    public static function datesFor(PlacementPeriod $period, string $enrolmentId): array
    {
        $host = $period->relationLoaded('hosts')
            ? $period->hosts->firstWhere('enrolment_id', $enrolmentId)
            : PlacementPeriodHost::query()->where('placement_period_id', $period->id)->where('enrolment_id', $enrolmentId)->first();

        return $host !== null && $host->starts_on !== null && $host->ends_on !== null
            ? ['from' => $host->starts_on->toDateString(), 'to' => $host->ends_on->toDateString()]
            : ['from' => $period->starts_on->toDateString(), 'to' => $period->ends_on->toDateString()];
    }

    /**
     * The placements of the « Planning » tab: each period with its trainees
     * (enrolments of the session but cancelled and waitlisted ones), their
     * host and dates.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(TrainingSession $session): array
    {
        $periods = PlacementPeriod::query()->with('hosts')->where('training_session_id', $session->id)->orderBy('starts_on')->orderBy('id')->get();
        if ($periods->isEmpty()) {
            return [];
        }
        $enrolments = Enrolment::query()->where('training_session_id', $session->id)
            ->whereNotIn('status', EnrolmentStatus::values([EnrolmentStatus::Cancelled, EnrolmentStatus::Waitlisted]))
            ->get(['id', 'person_id', 'status']);
        $fiches = app(Fiches::class);
        $people = $fiches->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->all());
        $hosts = $fiches->organisationLabels($periods->flatMap(fn (PlacementPeriod $p) => $p->hosts)->pluck('organisation_id')->filter()->map(fn ($id) => (string) $id)->all());

        return $periods->map(fn (PlacementPeriod $p) => [
            'id' => $p->id,
            'label' => $p->label,
            'starts_on' => $p->starts_on->toDateString(),
            'ends_on' => $p->ends_on->toDateString(),
            'trainees' => $enrolments->map(function (Enrolment $e) use ($p, $people, $hosts): array {
                $host = $p->hosts->firstWhere('enrolment_id', $e->id);
                $organisation = $host?->organisation_id === null ? null : ($hosts[$host->organisation_id] ?? null);
                $dates = self::datesFor($p, $e->id);

                return [
                    'enrolment_id' => $e->id,
                    'trainee' => $people[$e->person_id]['label'] ?? 'Personne introuvable',
                    'host' => $organisation === null ? null : [
                        'id' => $host->organisation_id,
                        'label' => $organisation['label'].($organisation['trashed'] ? ' (supprimée)' : ''),
                        'trashed' => $organisation['trashed'],
                    ],
                    'from' => $dates['from'],
                    'to' => $dates['to'],
                    'own_dates' => $host?->starts_on !== null,
                ];
            })->sortBy('trainee')->values()->all(),
        ])->values()->all();
    }

    /** A placement of a cancelled session no longer changes (409). */
    private static function assertOpen(PlacementPeriod $period): void
    {
        Seances::assertSessionOpen(TrainingSession::withTrashed()->findOrFail($period->training_session_id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function trimmed(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
