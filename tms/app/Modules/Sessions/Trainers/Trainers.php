<?php

namespace App\Modules\Sessions\Trainers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The trainers of the organisme: a user account of the TMS (picked, or
 * given the Formateur role) or a trainer without account (name, e-mail),
 * each backed by a person of the CRM created or reused behind the scenes
 * (Crm\Public\Fiches::personForTrainer, never a search of the CRM);
 * salaried or freelance, active or not; their unavailabilities.
 * A trainer used somewhere (séances, task 3, declared with registerUsage) is
 * never deleted: it is deactivated.
 */
final class Trainers
{
    public const ALREADY = 'Cette personne est déjà formateur.';

    public const UNKNOWN_PERSON = "Cette personne n'existe pas.";

    public const USER_TAKEN = 'Ce compte est déjà lié à un autre formateur.';

    public const EMAIL_TAKEN = 'Un formateur a déjà cet e-mail.';

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

    /**
     * A trainer without account: the person is reused when exactly one has
     * the e-mail, created otherwise; refused (person.email) when a trainer
     * already has that e-mail. A later invitation with the e-mail links him.
     *
     * @throws ValidationException
     */
    public static function declarePerson(string $lastName, ?string $firstName, ?string $email, TrainerStatus $status): Trainer
    {
        return DB::transaction(function () use ($lastName, $firstName, $email, $status): Trainer {
            if ($email !== null && trim($email) !== '' && self::withEmail($email)->isNotEmpty()) {
                throw ValidationException::withMessages(['person.email' => self::EMAIL_TAKEN]);
            }
            $personId = app(Fiches::class)->personForTrainer($lastName, $firstName, $email);
            try {
                return self::declare($personId, $status);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['person.email' => self::EMAIL_TAKEN]);
            }
        });
    }

    /**
     * Makes the account trainer (« Ajouter un formateur »): refused (user_id)
     * when it already has one; otherwise as ensureForUser, with $status.
     *
     * @throws ValidationException
     */
    public static function declareUser(User $user, TrainerStatus $status): Trainer
    {
        return DB::transaction(function () use ($user, $status): Trainer {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['trainer-user:'.$user->getKey()]);
            if (self::forUser($user) !== null) {
                throw ValidationException::withMessages(['user_id' => self::USER_TAKEN]);
            }
            $trainer = self::ensureForUser($user, $status);
            if ($trainer === null) {
                throw ValidationException::withMessages(['user_id' => self::EMAIL_TAKEN]);
            }
            if ($trainer->status !== $status->value) {
                $trainer->update(['status' => $status->value]);
            }

            return $trainer;
        });
    }

    /**
     * The trainer of an account, created when missing (role Formateur given,
     * « Ajouter un formateur »): the one trainer having its e-mail and no
     * account is linked; none: a trainer is created on the person having
     * the e-mail (exactly one) or a new person named after the account;
     * several trainers with the e-mail, or one linked to another account:
     * nothing (a manager links by hand), null.
     */
    public static function ensureForUser(User $user, TrainerStatus $status = TrainerStatus::Employee): ?Trainer
    {
        return DB::transaction(function () use ($user, $status): ?Trainer {
            $existing = self::forUser($user);
            if ($existing !== null) {
                return $existing;
            }
            $same = self::withEmail($user->email);
            if ($same->count() > 1) {
                return null;
            }
            if ($same->count() === 1) {
                $trainer = $same->firstOrFail();
                if ($trainer->user_id !== null) {
                    return null;
                }
                self::link($trainer, $user);

                return $trainer;
            }
            [$first, $last] = self::splitName($user->name);
            $trainer = self::declare(app(Fiches::class)->personForTrainer($last, $first, $user->email), $status);
            self::link($trainer, $user);

            return $trainer->refresh();
        });
    }

    /**
     * Trainers (out of the trash) whose person has this primary e-mail.
     *
     * @return Collection<int, Trainer>
     */
    private static function withEmail(string $email): Collection
    {
        $people = app(Fiches::class)->peopleWithEmail($email);

        return $people === [] ? collect() : Trainer::query()->whereIn('person_id', $people)->get();
    }

    /**
     * « Léa Marie Durand » → first name « Léa Marie », last name « Durand »;
     * a single word is the last name.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function splitName(string $name): array
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) <= 1) {
            return [null, $words[0] ?? $name];
        }
        $last = array_pop($words);

        return [implode(' ', $words), $last];
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
        if (self::forUser($user) !== null) {
            return;
        }
        $trainers = self::withEmail($user->email);
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
