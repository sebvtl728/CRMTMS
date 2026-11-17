<?php

namespace App\Modules\Sessions\Planning;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\Events\SeanceChanged;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes the séances one by one: create, change (reschedule), cancel with a
 * reason, delete. Only on a module dated in séances (409 otherwise), within
 * the dates of the session, one séance per module, day and start. Each
 * write runs under the lock of its module (the generator takes it too).
 *
 * A séance with attendance (task 4, registerAttendance) is never deleted:
 * cancelled with a reason. Moving its day or hours, or cancelling it, runs
 * the onScheduleChange hooks in the same transaction (the attendance is
 * reviewed atomically), then dispatches SeanceChanged after commit (a
 * notification only). Each write holds the session row shared first, then
 * locks the module and the séance (order session → module → séance).
 * Every write on a day goes through assertMonthOpen() first: the one guard
 * of a closed month (task 4 wires Closures::isClosed there, 409).
 * Alerts (Conflicts) never block a write.
 */
final class Seances
{
    public const INTERVAL = 'Ce module est daté par un intervalle : il n\'a pas de séances. Passez-le en séances d\'abord.';

    public const HAS_ATTENDANCE = 'Des présences sont saisies sur cette séance : elle ne se supprime pas. Annulez-la avec un motif.';

    public const ALREADY_CANCELLED = 'Cette séance est déjà annulée.';

    public const CANCELLED = 'Une séance annulée ne se modifie plus.';

    public const DUPLICATE = 'Une séance de ce module commence déjà à cette heure ce jour-là.';

    /** @var array<string, Closure(string): bool> séance id => attendance entered */
    private array $attendance = [];

    /** @var (Closure(TrainingSession, string): void)|null */
    private ?Closure $monthGuard = null;

    /** @var array<string, Closure(Seance, string): void> */
    private array $scheduleHooks = [];

    /**
     * @param  array<string, mixed>  $data  session_module_id, date, starts_at, ends_at, break_minutes, trainer_id, room_id, place_text, note
     *
     * @throws ValidationException
     */
    public function create(TrainingSession $session, array $data): Seance
    {
        self::assertSessionOpen($session);
        $values = self::validated($session, $data, null);

        return DB::transaction(function () use ($session, $values): Seance {
            self::holdSession($session->id);
            $module = self::lockModule($values['session_module_id']);
            self::assertMonthOpen($session, $values['date']);
            self::assertFree($values, null);
            $seance = self::insert(new Seance([...$values, 'training_session_id' => $session->id, 'session_module_id' => $module->id]));

            return $seance;
        });
    }

    /**
     * Changes a séance from (partial) input: missing keys keep their value.
     * A new day or new hours on a closed month are refused (assertMonthOpen,
     * old and new day); they dispatch SeanceChanged (rescheduled).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function reschedule(Seance $seance, array $data): Seance
    {
        $session = TrainingSession::withTrashed()->findOrFail($seance->training_session_id);
        self::assertSessionOpen($session);
        if ($seance->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
        $current = [
            'session_module_id' => $seance->session_module_id,
            'date' => $seance->date->toDateString(),
            'starts_at' => $seance->start(),
            'ends_at' => $seance->end(),
            'break_minutes' => $seance->break_minutes,
            'trainer_id' => $seance->trainer_id,
            'room_id' => $seance->room_id,
            'place_text' => $seance->place_text,
            'note' => $seance->note,
        ];
        $values = self::validated($session, array_replace($current, array_intersect_key($data, $current)), $seance);

        return DB::transaction(function () use ($seance, $session, $values): Seance {
            self::holdSession($session->id);
            // Moving to another module: both modules locked, in id order (no deadlock).
            $ids = array_unique([$seance->session_module_id, $values['session_module_id']]);
            sort($ids);
            SessionModule::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            self::lockModule($values['session_module_id']);
            $locked = Seance::query()->lockForUpdate()->findOrFail($seance->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            $moved = $values['date'] !== $locked->date->toDateString() || $values['starts_at'] !== $locked->start()
                || $values['ends_at'] !== $locked->end() || $values['break_minutes'] !== $locked->break_minutes;
            if ($moved) {
                self::assertMonthOpen($session, $locked->date->toDateString());
                self::assertMonthOpen($session, $values['date']);
            }
            self::assertFree($values, $locked->id);
            $locked->fill($values);
            $locked = self::insert($locked);
            if ($moved) {
                // The attendance is reviewed in this transaction, under the séance's lock.
                self::runScheduleHooks($locked, SeanceChanged::RESCHEDULED);
                SeanceChanged::dispatch($locked->id, SeanceChanged::RESCHEDULED);
            }
            $seance->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Cancels with a reason (≤ 300): the séance stays, struck through; its
     * attendance falls to 0 minute (Attendances::review, in the same transaction).
     *
     * @throws ValidationException
     */
    public function cancel(Seance $seance, string $reason): Seance
    {
        if ($seance->isCancelled()) {
            throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
        }
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'max:300']], [
            'reason.required' => 'Indiquez le motif de l\'annulation.',
            'reason.max' => 'Le motif ne doit pas dépasser 300 caractères.',
        ])->validate();
        $session = TrainingSession::withTrashed()->findOrFail($seance->training_session_id);

        return DB::transaction(function () use ($seance, $session, $reason): Seance {
            self::holdSession($session->id);
            $locked = Seance::query()->lockForUpdate()->findOrFail($seance->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
            }
            self::assertMonthOpen($session, $locked->date->toDateString());
            $locked->status = Seance::CANCELLED;
            $locked->cancel_reason = $reason;
            $locked->save();
            self::runScheduleHooks($locked, SeanceChanged::CANCELLED);
            SeanceChanged::dispatch($locked->id, SeanceChanged::CANCELLED);
            $seance->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Deletes (trash) a séance without attendance; with attendance: 409.
     */
    public function delete(Seance $seance): void
    {
        $session = TrainingSession::withTrashed()->findOrFail($seance->training_session_id);
        DB::transaction(function () use ($seance, $session): void {
            self::holdSession($session->id);
            $locked = Seance::query()->lockForUpdate()->findOrFail($seance->id);
            self::assertMonthOpen($session, $locked->date->toDateString());
            if (self::hasAttendance($locked->id)) {
                throw new UserFacingHttpException(409, self::HAS_ATTENDANCE);
            }
            $locked->delete();
        });
    }

    /**
     * The guard of a closed month of the session (spec § 4 bis 5): creating,
     * moving, cancelling or deleting a séance of a closed month is refused.
     * Task 4 wires Closures::isClosed here (guardMonthsWith) and throws 409.
     */
    public static function assertMonthOpen(TrainingSession $session, string $date): void
    {
        $guard = self::registry()->monthGuard;
        if ($guard !== null) {
            $guard($session, $date);
        }
    }

    /**
     * Whether the month of $date is open for the séances of the session
     * (the guard does not refuse it).
     */
    public static function isMonthOpen(TrainingSession $session, string $date): bool
    {
        try {
            self::assertMonthOpen($session, $date);
        } catch (UserFacingHttpException) {
            return false;
        }

        return true;
    }

    /**
     * @param  (Closure(TrainingSession, string): void)|null  $guard  throws to refuse
     */
    public static function guardMonthsWith(?Closure $guard): void
    {
        self::registry()->monthGuard = $guard;
    }

    /**
     * Declares what follows a change of hours or a cancellation of a séance
     * (the attendance review): run inside the writing transaction, under the
     * séance's lock, with the séance as saved and SeanceChanged::RESCHEDULED
     * or ::CANCELLED. A hook that throws rolls the change back. Null removes it.
     *
     * @param  (Closure(Seance, string): void)|null  $hook
     */
    public static function onScheduleChange(string $key, ?Closure $hook): void
    {
        if ($hook === null) {
            unset(self::registry()->scheduleHooks[$key]);
        } else {
            self::registry()->scheduleHooks[$key] = $hook;
        }
    }

    private static function runScheduleHooks(Seance $locked, string $kind): void
    {
        foreach (self::registry()->scheduleHooks as $hook) {
            $hook($locked, $kind);
        }
    }

    /**
     * The session row held shared for the whole write (Closures::close takes
     * it FOR UPDATE): the closed-month check and the write are atomic. Lock
     * order everywhere: session → module(s) → séance → attendance.
     */
    private static function holdSession(string $sessionId): void
    {
        TrainingSession::withTrashed()->whereKey($sessionId)->sharedLock()->firstOrFail();
    }

    /**
     * Declares what tells whether a séance has attendance (task 4).
     *
     * @param  Closure(string): bool  $has  id of the séance => whether attendance is entered
     */
    public static function registerAttendance(string $key, Closure $has): void
    {
        self::registry()->attendance[$key] = $has;
    }

    public static function hasAttendance(string $seanceId): bool
    {
        foreach (self::registry()->attendance as $has) {
            if ($has($seanceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The columns to write, from untrusted input.
     *
     * @param  array<string, mixed>  $data
     * @return array{session_module_id: string, date: string, starts_at: string, ends_at: string, break_minutes: int, trainer_id: ?string, room_id: ?string, place_text: ?string, note: ?string}
     *
     * @throws ValidationException
     */
    public static function validated(TrainingSession $session, array $data, ?Seance $current): array
    {
        foreach (['session_module_id', 'date', 'starts_at', 'ends_at', 'break_minutes', 'trainer_id', 'room_id', 'place_text', 'note'] as $key) {
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
        $data['break_minutes'] ??= '0';
        foreach (['starts_at', 'ends_at'] as $key) {
            // « 9:00 » or « 09:00:00 » as « 09:00 ».
            if (is_string($data[$key]) && preg_match('/^(\d{1,2}):([0-5]\d)(:00)?$/', $data[$key], $m) === 1) {
                $data[$key] = str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
            }
        }
        $tenant = app(TenantContext::class)->id();
        $sessionStart = $session->starts_on->toDateString();
        $sessionEnd = $session->ends_on->toDateString();

        $validator = Validator::make($data, [
            'session_module_id' => ['bail', 'required', 'uuid', Rule::exists('session_modules', 'id')
                ->where('tenant_id', $tenant)->where('training_session_id', $session->id)->whereNull('deleted_at')],
            'date' => ['bail', 'required', 'date_format:Y-m-d'],
            'starts_at' => ['bail', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'ends_at' => ['bail', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'break_minutes' => ['bail', 'integer', 'between:0,1439'],
            'trainer_id' => ['bail', 'nullable', 'uuid', Rule::exists('trainers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('active', true)->when($current?->trainer_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'room_id' => ['bail', 'nullable', 'uuid', Rule::exists('rooms', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('active', true)->when($current?->room_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'place_text' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'session_module_id.required' => 'Choisissez le module.',
            'session_module_id.*' => 'Module introuvable.',
            'date.required' => 'Indiquez la date de la séance.',
            'date.*' => 'Date invalide.',
            'starts_at.required' => 'Indiquez l\'heure de début.',
            'starts_at.*' => 'Heure invalide (ex. 09:00).',
            'ends_at.required' => 'Indiquez l\'heure de fin.',
            'ends_at.*' => 'Heure invalide (ex. 09:00).',
            'break_minutes.*' => 'La pause doit être un nombre entier de minutes.',
            'trainer_id.*' => 'Formateur introuvable.',
            'room_id.*' => 'Salle introuvable.',
            'place_text.*' => 'Le lieu ne doit pas dépasser 300 caractères.',
            'note.*' => 'La note ne doit pas dépasser 1 000 caractères.',
        ]);
        $validator->after(function ($v) use ($data, $sessionStart, $sessionEnd): void {
            $errors = $v->errors();
            if (! $errors->has('date') && ($data['date'] < $sessionStart || $data['date'] > $sessionEnd)) {
                $errors->add('date', 'La séance doit rester dans les dates de la session (du '.Conflicts::french($sessionStart).' au '.Conflicts::french($sessionEnd).').');
            }
            if ($errors->hasAny(['starts_at', 'ends_at'])) {
                return;
            }
            $length = self::minutesBetween((string) $data['starts_at'], (string) $data['ends_at']);
            if ($length <= 0) {
                $errors->add('ends_at', 'L\'heure de fin doit suivre l\'heure de début.');
            } elseif (! $errors->has('break_minutes') && (int) $data['break_minutes'] >= $length) {
                $errors->add('break_minutes', 'La pause doit être plus courte que la séance.');
            }
        });
        $validator->validate();

        $module = SessionModule::query()->findOrFail($data['session_module_id']);
        if ($module->dating_mode !== DatingMode::Seances->value) {
            throw new UserFacingHttpException(409, self::INTERVAL);
        }

        return [
            'session_module_id' => (string) $data['session_module_id'],
            'date' => (string) $data['date'],
            'starts_at' => (string) $data['starts_at'],
            'ends_at' => (string) $data['ends_at'],
            'break_minutes' => (int) $data['break_minutes'],
            'trainer_id' => $data['trainer_id'],
            'room_id' => $data['room_id'],
            'place_text' => $data['place_text'],
            'note' => $data['note'],
        ];
    }

    /** « 09:00 » → « 12:30 »: 210. */
    public static function minutesBetween(string $start, string $end): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $start));
        [$h2, $m2] = array_map('intval', explode(':', $end));

        return ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
    }

    /**
     * Locks the module of a séance being written (the generator and
     * SessionModules lock it too): 409 when it is no longer dated in séances.
     */
    public static function lockModule(string $moduleId): SessionModule
    {
        $module = SessionModule::query()->lockForUpdate()->findOrFail($moduleId);
        if ($module->dating_mode !== DatingMode::Seances->value) {
            throw new UserFacingHttpException(409, self::INTERVAL);
        }

        return $module;
    }

    public static function assertSessionOpen(TrainingSession $session): void
    {
        if ($session->isCancelled()) {
            throw new UserFacingHttpException(409, SessionWriter::CANCELLED);
        }
    }

    /**
     * Saves and reads back the duration computed by the database. A
     * duplicate slipping in despite the lock is a validation error.
     *
     * @throws ValidationException
     */
    public static function insert(Seance $seance): Seance
    {
        try {
            DB::transaction(fn () => $seance->save());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['starts_at' => self::DUPLICATE]);
            }
            throw $e;
        }

        return $seance->refresh();
    }

    /**
     * One séance per module, day and start (out of the trash).
     *
     * @param  array{session_module_id: string, date: string, starts_at: string}  $values
     *
     * @throws ValidationException
     */
    private static function assertFree(array $values, ?string $exceptId): void
    {
        $taken = Seance::query()->where('session_module_id', $values['session_module_id'])->where('date', $values['date'])
            ->where('starts_at', $values['starts_at'])->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['starts_at' => self::DUPLICATE]);
        }
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        return $app->make(self::class);
    }
}
