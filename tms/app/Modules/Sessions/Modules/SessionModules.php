<?php

namespace App\Modules\Sessions\Modules;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes the modules of a session: copy of the training's modules at
 * creation (Catalogue\Trainings::modules, decimal hours → whole minutes,
 * rounded half up), add / change / delete, dating mode. Every write runs
 * under the lock of the session and recomputes its planned_hours = Σ of its
 * modules' minutes (brick/math, never a float) in the same transaction.
 * Trainees planned above the new hours keep theirs: a warning, never a
 * silent change. A module with séances or hours entered (hasActivity) is
 * neither deleted nor switched to the other mode (409).
 */
final class SessionModules
{
    public const HAS_ACTIVITY = 'Ce module a des séances ou des heures saisies : il ne se supprime pas.';

    public const MODE_LOCKED = 'Ce module a des séances ou des heures saisies : son mode de datation ne change plus.';

    public const TRAINING_LOCKED = 'Des séances ou des heures sont saisies sur les modules de cette session : sa formation ne change plus.';

    /** Shown for a module without planned hours (counted 0 in sums). */
    public const UNDEFINED = 'à définir';

    /** planned_hours is numeric(6,2): 9 999,99 h at most. */
    public const MAX_MINUTES = 599_999;

    public function __construct(private readonly Trainings $trainings) {}

    /**
     * The modules of the session's training, copied on the (new) session;
     * a training without module gives one module of its title at the
     * session's hours. Within the caller's transaction.
     */
    public function copyFromTraining(TrainingSession $session): void
    {
        $modules = $this->trainings->modules($session->training_id);
        $rows = $modules === []
            ? [[
                'title' => mb_substr($this->trainings->find($session->training_id)->title ?? $session->number, 0, 200),
                'planned_minutes' => self::minutesOf($session->planned_hours),
                'modality_id' => $session->modality_id,
            ]]
            : array_map(fn (array $m) => [
                'title' => mb_substr($m['title'], 0, 200),
                'planned_minutes' => self::minutesOf($m['duration_hours']),
                'modality_id' => $m['modality_id'],
            ], $modules);

        foreach ($rows as $i => $row) {
            SessionModule::query()->create([
                ...$row,
                'training_session_id' => $session->id,
                'position' => $i + 1,
                'dating_mode' => DatingMode::Interval->value,
                'starts_on' => $session->starts_on->toDateString(),
                'ends_on' => $session->ends_on->toDateString(),
            ]);
        }
        self::recompute($session);
    }

    /**
     * The training of the (locked) session changed: its modules are those of
     * the new training, unless one of them has séances or hours (409).
     */
    public function replaceFromTraining(TrainingSession $session): void
    {
        $modules = SessionModule::query()->where('training_session_id', $session->id)->lockForUpdate()->get();
        if ($modules->contains(fn (SessionModule $m) => $m->hasActivity())) {
            throw new UserFacingHttpException(409, self::TRAINING_LOCKED);
        }
        $modules->each(fn (SessionModule $m) => $m->delete());
        $this->copyFromTraining($session);
    }

    /**
     * « Reprendre les modules de la formation » (preview): the modules the
     * training would give against the session's, and whether one of them
     * has séances or hours entered (then locked).
     *
     * @return array{modules: list<array{title: string, planned: string}>, total: string, current: list<array{title: string, planned: string}>, locked: bool}
     */
    public function catalogueComparison(TrainingSession $session): array
    {
        $catalogue = $this->trainings->modules($session->training_id);
        $modules = $catalogue === []
            ? [['title' => $this->trainings->find($session->training_id)->title ?? $session->number, 'minutes' => self::minutesOf($session->planned_hours)]]
            : array_map(fn (array $m) => ['title' => $m['title'], 'minutes' => self::minutesOf($m['duration_hours'])], $catalogue);
        $current = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->orderBy('id')->get();
        $plannedLabel = fn (?int $minutes): string => $minutes === null ? self::UNDEFINED : self::label($minutes);

        return [
            'modules' => array_map(fn (array $m) => ['title' => $m['title'], 'planned' => $plannedLabel($m['minutes'])], $modules),
            'total' => self::label((int) array_sum(array_column($modules, 'minutes'))),
            'current' => $current->map(fn (SessionModule $m) => ['title' => $m->title, 'planned' => $plannedLabel($m->planned_minutes)])->values()->all(),
            'locked' => $current->contains(fn (SessionModule $m) => $m->hasActivity()),
        ];
    }

    /**
     * Replaces the modules of the session by its training's (409 once a
     * module has séances or hours entered, or the session is cancelled).
     */
    public function resetFromTraining(TrainingSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $this->replaceFromTraining(self::lockSession($session));
        });
    }

    /**
     * Adds a module at the end ($module null) or changes one, from untrusted
     * input: title, planned_hours (« 3,5 »), modality_id, dating_mode (on
     * creation only: switchMode() otherwise), starts_on, ends_on (within the
     * session's; an interval module without dates takes the session's).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function save(TrainingSession $session, array $data, ?SessionModule $module = null): SessionModule
    {
        if ($session->isCancelled()) {
            throw new UserFacingHttpException(409, SessionWriter::CANCELLED);
        }
        $values = $this->validated($session, $data, $module);

        return DB::transaction(function () use ($session, $values, $module): SessionModule {
            $locked = self::lockSession($session);
            if ($module === null) {
                $module = new SessionModule([
                    ...$values,
                    'training_session_id' => $locked->id,
                    'position' => (int) SessionModule::query()->where('training_session_id', $locked->id)->max('position') + 1,
                ]);
            } else {
                $module = SessionModule::query()->lockForUpdate()->findOrFail($module->id);
                $module->fill($values);
            }
            $others = (int) SessionModule::query()->where('training_session_id', $locked->id)
                ->when($module->exists, fn ($q) => $q->whereKeyNot($module->id))->sum('planned_minutes');
            if ($others + (int) $module->planned_minutes > self::MAX_MINUTES) {
                throw ValidationException::withMessages(['planned_hours' => 'Le total des modules ne peut pas dépasser 9 999 h.']);
            }
            $module->save();
            self::recompute($locked);
            $session->setRawAttributes($locked->getAttributes(), true);

            return $module;
        });
    }

    /**
     * Deletes (trash) a module without séances nor hours entered, else 409.
     */
    public function delete(SessionModule $module): void
    {
        DB::transaction(function () use ($module): void {
            $session = self::lockSession($module->session()->withTrashed()->firstOrFail());
            $locked = SessionModule::query()->lockForUpdate()->findOrFail($module->id);
            if ($locked->hasActivity()) {
                throw new UserFacingHttpException(409, self::HAS_ACTIVITY);
            }
            $locked->delete();
            self::recompute($session);
        });
        $module->refresh();
    }

    /**
     * interval ↔ seances, only without séances nor hours entered (409); an
     * interval module without dates takes the session's.
     *
     * @throws ValidationException
     */
    public function switchMode(SessionModule $module, string $mode): void
    {
        $target = DatingMode::tryFrom($mode);
        if ($target === null) {
            throw ValidationException::withMessages(['dating_mode' => 'Mode de datation inconnu.']);
        }
        DB::transaction(function () use ($module, $target): void {
            $session = self::lockSession($module->session()->withTrashed()->firstOrFail());
            $locked = SessionModule::query()->lockForUpdate()->findOrFail($module->id);
            if ($locked->dating_mode === $target->value) {
                return;
            }
            if ($locked->hasActivity()) {
                throw new UserFacingHttpException(409, self::MODE_LOCKED);
            }
            $locked->dating_mode = $target->value;
            if ($target === DatingMode::Interval) {
                $locked->starts_on ??= $session->starts_on;
                $locked->ends_on ??= $session->ends_on;
            }
            $locked->save();
        });
        $module->refresh();
    }

    /**
     * planned_hours of the (locked) session = Σ of its modules' minutes
     * (null when none has hours), saved (journaled) when it changes.
     */
    public static function recompute(TrainingSession $session): void
    {
        $minutes = SessionModule::query()->where('training_session_id', $session->id)->whereNotNull('planned_minutes')->pluck('planned_minutes');
        $session->planned_hours = $minutes->isEmpty() ? null : self::hoursDecimal((int) $minutes->sum());
        if ($session->isDirty('planned_hours')) {
            $session->save();
        }
    }

    /**
     * The modules of the page « Planning », in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(TrainingSession $session): array
    {
        $modules = SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->orderBy('id')->get();
        $modalities = ReferenceItem::query()->whereIn('id', $modules->pluck('modality_id')->filter()->unique()->values())->pluck('label', 'id');
        $scheduled = self::scheduledMinutes($session);
        // Modules with activity, in one query (SessionModule::hasActivity()'s rule).
        $active = array_flip(Seance::query()->whereIn('session_module_id', $modules->pluck('id')->all())->distinct()->pluck('session_module_id')->all());

        return $modules->map(fn (SessionModule $m) => [
            'id' => $m->id,
            'position' => $m->position,
            'title' => $m->title,
            'planned_minutes' => $m->planned_minutes,
            'planned' => $m->planned_minutes === null ? self::UNDEFINED : self::label($m->planned_minutes),
            'scheduled_minutes' => $scheduled[$m->id] ?? 0,
            'scheduled' => self::label($scheduled[$m->id] ?? 0),
            'planned_hours' => $m->planned_minutes === null ? '' : self::input($m->planned_minutes),
            'modality_id' => $m->modality_id,
            'modality' => $m->modality_id === null ? null : ($modalities[$m->modality_id] ?? null),
            'dating_mode' => $m->dating_mode,
            'dating_mode_label' => DatingMode::from($m->dating_mode)->label(),
            'starts_on' => $m->starts_on?->toDateString(),
            'ends_on' => $m->ends_on?->toDateString(),
            'out_of_range' => $m->outOfRange($session),
            'has_activity' => isset($active[$m->id]),
        ])->values()->all();
    }

    /**
     * What the « Planning » tab warns about: trainees planned above the
     * session's hours, interval modules outside its dates.
     *
     * @return list<string>
     */
    public static function warnings(TrainingSession $session): array
    {
        $warnings = [];
        $scheduled = self::scheduledMinutes($session);
        if (($enrolments = self::enrolmentWarning($session)) !== null) {
            $warnings[] = $enrolments;
        }
        SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->orderBy('id')->get()
            ->each(function (SessionModule $m) use ($session, $scheduled, &$warnings): void {
                if ($m->outOfRange($session)) {
                    $warnings[] = "Le module « {$m->title} » sort des dates de la session.";
                }
                if ($m->planned_minutes === null) {
                    $warnings[] = "Le module « {$m->title} » n'a pas d'heures prévues.";
                } elseif (($minutes = $scheduled[$m->id] ?? 0) > 0 && $minutes !== $m->planned_minutes) {
                    $warnings[] = "Le module « {$m->title} » : ".self::label($minutes).' planifiées pour '.self::label($m->planned_minutes).' prévues.';
                }
            });

        return $warnings;
    }

    /**
     * Trainees (cancelled enrolments aside) planned above the session's hours.
     */
    public static function enrolmentWarning(TrainingSession $session): ?string
    {
        if ($session->planned_hours === null) {
            return null;
        }
        $count = Enrolment::query()->where('training_session_id', $session->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value)
            ->where('planned_hours', '>', (string) $session->planned_hours)->count();
        $hours = SessionRows::hours($session->planned_hours);

        return match (true) {
            $count === 0 => null,
            $count === 1 => "1 stagiaire a des heures prévues supérieures à celles de la session ({$hours} h) : vérifiez son inscription.",
            default => "{$count} stagiaires ont des heures prévues supérieures à celles de la session ({$hours} h) : vérifiez leurs inscriptions.",
        };
    }

    /**
     * Minutes of the planned séances (cancelled ones aside) per module of the session.
     *
     * @return array<string, int>
     */
    public static function scheduledMinutes(TrainingSession $session): array
    {
        return Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->groupBy('session_module_id')->selectRaw('session_module_id, sum(duration_minutes) as minutes')
            ->pluck('minutes', 'session_module_id')->map(fn ($m) => (int) $m)->all();
    }

    /** Σ of the modules' minutes as a label (« 24 h 30 »), null when none has hours. */
    public static function totalLabel(TrainingSession $session): ?string
    {
        $minutes = SessionModule::query()->where('training_session_id', $session->id)->whereNotNull('planned_minutes')->pluck('planned_minutes');

        return $minutes->isEmpty() ? null : self::label((int) $minutes->sum());
    }

    /** « 10.33 » h → 620 min (half up); null, 0 or invalid → null. */
    public static function minutesOf(?string $hours): ?int
    {
        if ($hours === null || preg_match('/^\d+(\.\d+)?$/', $hours) !== 1) {
            return null;
        }
        $minutes = BigDecimal::of($hours)->multipliedBy(60)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $minutes > 0 ? $minutes : null;
    }

    /** 1470 → « 24.50 » (numeric(6,2), half up). */
    public static function hoursDecimal(int $minutes): string
    {
        return (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp);
    }

    /** 1470 → « 24 h 30 », 420 → « 7 h ». */
    public static function label(int $minutes): string
    {
        $rest = $minutes % 60;

        return intdiv($minutes, 60).' h'.($rest === 0 ? '' : ' '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT));
    }

    /** 210 → « 3,5 » (as typed in the form). */
    public static function input(int $minutes): string
    {
        return str_replace('.', ',', (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp)->stripTrailingZeros());
    }

    private static function lockSession(TrainingSession $session): TrainingSession
    {
        $locked = TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
        if ($locked->isCancelled()) {
            throw new UserFacingHttpException(409, SessionWriter::CANCELLED);
        }

        return $locked;
    }

    /**
     * The columns to write, from untrusted input.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(TrainingSession $session, array $data, ?SessionModule $current): array
    {
        foreach (['title', 'planned_hours', 'modality_id', 'dating_mode', 'starts_on', 'ends_on'] as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $data[$key] = $value;
        }
        if (is_string($data['planned_hours'])) {
            $data['planned_hours'] = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $data['planned_hours']);
        }
        if ($current !== null) {
            $data['dating_mode'] = $current->dating_mode;
        }

        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:200'],
            'planned_hours' => ['bail', 'required', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'modality_id' => ['bail', 'nullable', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', app(TenantContext::class)->id())
                ->where('list', ReferenceList::TrainingModality->value)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->modality_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'dating_mode' => ['required', Rule::enum(DatingMode::class)],
            'starts_on' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'title.required' => 'Indiquez l\'intitulé du module.',
            'title.*' => 'L\'intitulé ne doit pas dépasser 200 caractères.',
            'planned_hours.required' => 'Indiquez les heures prévues du module.',
            'planned_hours.regex' => 'Heures prévues invalides (ex. 7 ou 3,5).',
            'modality_id.*' => 'Modalité de formation inconnue.',
            'dating_mode.*' => 'Mode de datation inconnu.',
            'starts_on.date_format' => 'Date de début invalide.',
            'ends_on.date_format' => 'Date de fin invalide.',
            'ends_on.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ]);

        $sessionStart = $session->starts_on->toDateString();
        $sessionEnd = $session->ends_on->toDateString();
        $interval = ($data['dating_mode'] ?? null) === DatingMode::Interval->value;
        if ($interval) {
            $data['starts_on'] ??= $sessionStart;
            $data['ends_on'] ??= $sessionEnd;
            $validator->setData($data);
        }
        $validator->after(function ($v) use ($data, $sessionStart, $sessionEnd): void {
            if (! $v->errors()->has('planned_hours') && self::minutesOf($data['planned_hours']) === null) {
                $v->errors()->add('planned_hours', 'Les heures prévues doivent être supérieures à 0.');
            }
            if ($v->errors()->hasAny(['starts_on', 'ends_on'])) {
                return;
            }
            $outside = 'Les dates du module doivent rester dans celles de la session (du '
                .self::french($sessionStart).' au '.self::french($sessionEnd).').';
            foreach (['starts_on', 'ends_on'] as $key) {
                if (is_string($data[$key]) && ($data[$key] < $sessionStart || $data[$key] > $sessionEnd)) {
                    $v->errors()->add($key, $outside);

                    return;
                }
            }
        });
        $validator->validate();

        return [
            'title' => $data['title'],
            'planned_minutes' => self::minutesOf($data['planned_hours']),
            'modality_id' => $data['modality_id'],
            'dating_mode' => $data['dating_mode'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
        ];
    }

    /** « 2026-12-07 » → « 07/12/2026 ». */
    private static function french(string $date): string
    {
        [$y, $m, $d] = explode('-', $date);

        return "{$d}/{$m}/{$y}";
    }
}
