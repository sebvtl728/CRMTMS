<?php

namespace App\Modules\Sessions\Trainers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The trainers of the organisme: a person of the CRM (reached through
 * Crm\Public\Fiches) declared trainer once, salaried or freelance, active or
 * not, optionally linked to one user account; their unavailabilities.
 * A trainer used somewhere (séances, task 3, declared with registerUsage) is
 * never deleted: it is deactivated.
 */
final class Trainers
{
    public const ALREADY = 'Cette personne est déjà formateur.';

    public const UNKNOWN_PERSON = "Cette personne n'existe pas.";

    public const USER_TAKEN = 'Ce compte est déjà lié à un autre formateur.';

    public const IN_USE = 'Ce formateur a des séances : désactivez-le plutôt que de le supprimer.';

    /** @var array<string, Closure(string): bool> */
    private array $usages = [];

    /**
     * The trainer linked to the account (out of the trash, active or not).
     */
    public static function forUser(User $user): ?Trainer
    {
        return Trainer::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * No unavailability of the trainer overlaps [$from, $to[.
     */
    public static function available(string $trainerId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return ! TrainerUnavailability::query()->where('trainer_id', $trainerId)
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->exists();
    }

    /**
     * Declares the person trainer: a trashed trainer of hers comes back,
     * a live one is refused (validation error on person_id).
     *
     * @throws ValidationException
     */
    public static function declare(string $personId, TrainerStatus $status): Trainer
    {
        if (app(Fiches::class)->person($personId) === null) {
            throw ValidationException::withMessages(['person_id' => self::UNKNOWN_PERSON]);
        }

        return DB::transaction(function () use ($personId, $status): Trainer {
            // Two managers declaring the same person at once: one waits for the other.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['trainer-person:'.$personId]);
            if (Trainer::query()->where('person_id', $personId)->exists()) {
                throw ValidationException::withMessages(['person_id' => self::ALREADY]);
            }
            $trashed = Trainer::onlyTrashed()->where('person_id', $personId)->orderByDesc('deleted_at')->first();
            if ($trashed !== null) {
                $trashed->restore();
                $trashed->update(['status' => $status->value, 'active' => true]);

                return $trashed;
            }

            return Trainer::query()->create(['person_id' => $personId, 'status' => $status->value]);
        });
    }

    public static function update(Trainer $trainer, TrainerStatus $status, bool $active): void
    {
        DB::transaction(fn () => $trainer->update(['status' => $status->value, 'active' => $active]));
    }

    /**
     * Soft delete (and unlink the account), refused (409) for a trainer in use.
     */
    public static function delete(Trainer $trainer): void
    {
        DB::transaction(function () use ($trainer): void {
            $locked = Trainer::query()->whereKey($trainer->getKey())->lockForUpdate()->firstOrFail();
            if (self::inUse($locked->id)) {
                throw new UserFacingHttpException(409, self::IN_USE);
            }
            $locked->update(['user_id' => null]);
            $locked->delete();
        });
    }

    /**
     * Links (or unlinks, null) the account of the organisme; an account is
     * linked to one trainer at most (validation error on user_id).
     *
     * @throws ValidationException
     */
    public static function link(Trainer $trainer, ?User $user): void
    {
        DB::transaction(function () use ($trainer, $user): void {
            if ($user !== null) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['trainer-user:'.$user->getKey()]);
                $other = Trainer::withTrashed()->where('user_id', $user->getKey())->whereKeyNot($trainer->getKey())->exists();
                if ($other) {
                    throw ValidationException::withMessages(['user_id' => self::USER_TAKEN]);
                }
            }
            $trainer->update(['user_id' => $user?->getKey()]);
        });
    }

    /**
     * On the acceptance of an invitation: the account is linked to the
     * trainer whose person has its e-mail, when exactly one trainer has it
     * and neither is linked yet; otherwise nothing (a manager links by hand).
     */
    public static function linkInvited(User $user): void
    {
        $people = app(Fiches::class)->peopleWithEmail($user->email);
        if ($people === [] || self::forUser($user) !== null) {
            return;
        }
        $trainers = Trainer::query()->whereIn('person_id', $people)->get();
        if ($trainers->count() !== 1 || $trainers->first()?->user_id !== null) {
            return;
        }
        self::link($trainers->firstOrFail(), $user);
    }

    public static function addUnavailability(Trainer $trainer, CarbonImmutable $from, CarbonImmutable $to, ?string $reason): TrainerUnavailability
    {
        return DB::transaction(fn () => TrainerUnavailability::query()->create([
            'trainer_id' => $trainer->id, 'starts_at' => $from, 'ends_at' => $to, 'reason' => $reason,
        ]));
    }

    public static function removeUnavailability(TrainerUnavailability $unavailability): void
    {
        DB::transaction(fn () => $unavailability->delete());
    }

    /**
     * Anonymisation of people (Crm\Public\FicheLinks): the reasons of the
     * unavailabilities of their trainers are cleared.
     *
     * @param  list<string>  $personIds
     */
    public static function anonymise(array $personIds): void
    {
        $trainers = Trainer::withTrashed()->whereIn('person_id', $personIds)->pluck('id');
        TrainerUnavailability::query()->whereIn('trainer_id', $trainers)->whereNotNull('reason')
            ->get()->each(fn (TrainerUnavailability $u) => $u->update(['reason' => null]));
    }

    /**
     * Declares what uses a trainer (the séances of task 3).
     *
     * @param  Closure(string): bool  $used  id of the trainer => whether it is used
     */
    public static function registerUsage(string $key, Closure $used): void
    {
        self::registry()->usages[$key] = $used;
    }

    public static function inUse(string $trainerId): bool
    {
        foreach (self::registry()->usages as $used) {
            if ($used($trainerId)) {
                return true;
            }
        }

        return false;
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
