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
 *    (no name). Idempotent: running it again lists and mails nothing new.
 * An active import postpones the anonymisations to the next run.
 */
class AnonymiseExpired implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public const DELAY_DAYS = 30;

    public const CHUNK = 50;

    public const SLICE_SECONDS = 40;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public bool $continuation = false)
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
            if (! $this->continuation) {
                $listed = $this->refreshList($tenants->id());
                if ($listed > 0) {
                    $this->notify($listed);
                }
            }
            $continue = $this->anonymiseDue($anonymiser);
        } finally {
            $lock->release();
        }

        if ($continue) {
            static::dispatch(true);
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
     * @return bool true when more people are due (continued by a new job)
     */
    private function anonymiseDue(Anonymiser $anonymiser): bool
    {
        $started = microtime(true);
        $expired = RetentionPolicy::expiredPeople()->select('people.id')->toBase();

        while (true) {
            $ids = GdprPending::query()->where('listed_at', '<=', now()->subDays(self::DELAY_DAYS))
                ->whereIn('person_id', $expired)
                ->orderBy('listed_at')->orderBy('person_id')->limit(self::CHUNK)->pluck('person_id')->all();
            if ($ids === []) {
                return false;
            }

            try {
                $anonymiser->anonymiseAll(Person::withTrashed()->whereIn('id', $ids)->get(), 'retention');
            } catch (AnonymisationPostponed) {
                Log::info('Anonymisations reportées : un import est en cours.');

                return false;
            }

            if (microtime(true) - $started > self::SLICE_SECONDS) {
                return true;
            }
        }
    }

    private function notify(int $count): void
    {
        $plannedAt = now()->addDays(self::DELAY_DAYS);
        $recipients = User::query()->whereNull('deactivated_at')->get()->filter(fn (User $u) => $u->can(Permissions::GDPR));

        foreach ($recipients as $user) {
            try {
                TenantMailer::for()->to($user->email)->send(new GdprPendingMail($count, $plannedAt));
            } catch (Throwable $e) {
                Log::warning('Courriel RGPD non envoyé', ['user_id' => $user->id, 'exception' => $e->getMessage()]);
            }
        }
    }
}
