<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The daily summary of one user's overdue and today's actions (queued, the
 * tenant travels with the payload). Opt-in; nothing is sent to a deactivated
 * account, to someone who may no longer see the CRM, or when there is nothing
 * to do. The mail holds titles, fiche names and times, never a report.
 */
class DailyDigest implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public string $userId)
    {
        $this->captureTenant();
    }

    /**
     * Queues one summary per opted-in, active user of every organisme.
     * Returns the number queued.
     */
    public static function dispatchAll(): int
    {
        $count = 0;
        ForEachTenant::run(function () use (&$count): void {
            User::query()->where('daily_digest', true)->whereNull('deactivated_at')->orderBy('id')
                ->each(function (User $user) use (&$count): void {
                    if ($user->can(Permissions::VIEW)) {
                        self::dispatch($user->id);
                        $count++;
                    }
                });
        });

        return $count;
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null || ! $user->daily_digest || ! $user->isActive() || ! $user->can(Permissions::VIEW)) {
            return;
        }

        $todo = TodoQuery::for($user, CarbonImmutable::now());
        if ($todo['overdue']->isEmpty() && $todo['today']->isEmpty()) {
            return;
        }

        TenantMailer::for()->to($user->email)->send(new DailyDigestMail($user->name, $todo['overdue'], $todo['today']));
    }
}
