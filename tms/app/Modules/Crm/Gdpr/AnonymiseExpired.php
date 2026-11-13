<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly retention run of one organisme (tenant in the payload):
 * 1. the list « À anonymiser » follows the retention rule: people who are no
 *    longer expired (new contact, client, kept...) leave it, newly expired
 *    ones join it (listed_at = now);
 * 2. the people listed for DELAY_DAYS and still expired are anonymised, in
 *    slices (the job re-queues itself to continue);
 * 3. the gdpr managers (crm.gdpr) get an e-mail with the number newly listed
 *    and the number postponed (no name). Idempotent: running it again lists
 *    nothing new.
 * A live import (not stuck) postpones the anonymisations to the next run;
 * a person whose anonymisation fails is logged and skipped.
 */
class AnonymiseExpired implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public const DELAY_DAYS = 30;

    public const CHUNK = 50;

    public const SLICE_SECONDS = 40;

    public int $tries = 1;

    public int $timeout = 85;

    /**
     * @param  list<string>  $skip  people whose anonymisation failed earlier in this run
     */
    public function __construct(public bool $continuation = false, public array $skip = [])
    {
        $this->captureTenant();
    }

    public function handle(Anonymiser $anonymiser, TenantContext $tenants): void
    {
        $lock = Cache::lock('crm-gdpr:'.$tenants->id(), $this->timeout + 30);
        if (! $lock->get()) {
            return;
        }

        $continue = false;
        try {
            $listed = $this->continuation ? 0 : $this->refreshList($tenants->id());
            [$continue, $postponed] = $this->anonymiseDue($anonymiser);
            if (! $this->continuation && ($listed > 0 || $postponed > 0)) {
                $this->notify($listed, $postponed);
            }
        } finally {
            $lock->release();
        }

        if ($continue) {
            static::dispatch(true, $this->skip);
        }
    }

    /**
     * @return int number of people newly listed
     */
    private function refreshList(string $tenantId): int
    {
        $expired = RetentionPolicy::expiredPeople()->select('people.id')->toBase();

        DB::table('gdpr_pending')->whereNotIn('person_id', $expired)->delete();

        return DB::affectingStatement(
            'insert into gdpr_pending (tenant_id, person_id, listed_at) select ?, e.id, ? from ('.$expired->toSql().') e on conflict (person_id) do nothing',
            [$tenantId, now(), ...$expired->getBindings()],
        );
    }

    /**
     * A person whose anonymisation fails is logged and skipped for the rest
     * of the run (retried the next night); the others go on.
     *
     * @return array{0: bool, 1: int} more people due (continued by a new job), anonymisations postponed by a live import
     */
    private function anonymiseDue(Anonymiser $anonymiser): array
    {
        $started = microtime(true);
        $expired = RetentionPolicy::expiredPeople()->select('people.id')->toBase();
        $due = fn () => GdprPending::query()->where('listed_at', '<=', now()->subDays(self::DELAY_DAYS))
            ->whereIn('person_id', $expired)
            ->when($this->skip !== [], fn ($q) => $q->whereNotIn('person_id', $this->skip));

        while (true) {
            $ids = $due()->orderBy('listed_at')->orderBy('person_id')->limit(self::CHUNK)->pluck('person_id')->all();
            if ($ids === []) {
                return [false, 0];
            }

            try {
                $people = Person::withTrashed()->whereIn('id', $ids)->get();
                try {
                    $anonymiser->anonymiseAll($people, 'retention');
                } catch (AnonymisationPostponed $e) {
                    throw $e;
                } catch (Throwable) {
                    // One by one, to find and skip the failing person(s).
                    foreach ($people as $person) {
                        try {
                            $anonymiser->anonymiseAll([$person], 'retention');
                        } catch (AnonymisationPostponed $e) {
                            throw $e;
                        } catch (Throwable $e) {
                            $this->skip[] = $person->id;
                            Log::error('Anonymisation impossible, personne ignorée jusqu\'à la prochaine exécution', ['person_id' => $person->id, 'exception' => $e]);
                        }
                    }
                }
            } catch (AnonymisationPostponed) {
                $postponed = $due()->count();
                Log::warning('Anonymisations reportées : un import est en cours.', ['count' => $postponed]);

                return [false, $postponed];
            }

            if (microtime(true) - $started > self::SLICE_SECONDS) {
                return [true, 0];
            }
        }
    }

    private function notify(int $count, int $postponed): void
    {
        $plannedAt = now()->addDays(self::DELAY_DAYS);
        $recipients = User::query()->whereNull('deactivated_at')->get()->filter(fn (User $u) => $u->can(Permissions::GDPR));

        foreach ($recipients as $user) {
            try {
                TenantMailer::for()->to($user->email)->send(new GdprPendingMail($count, $plannedAt, $postponed));
            } catch (Throwable $e) {
                Log::warning('Courriel RGPD non envoyé', ['user_id' => $user->id, 'exception' => $e->getMessage()]);
            }
        }
    }
}
