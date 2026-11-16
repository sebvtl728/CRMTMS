<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the responsible of an action that it was handed to him (« Confier
 * à… »), through the organisme's mailer. Reads the CURRENT action: nothing
 * is sent once it is done, removed, or given back to the one who handed it,
 * nor to a deactivated account or one that may no longer see the actions.
 * A failure is retried (three tries); without a worker (sync queue) it is
 * only logged, with ids: the action is already saved.
 */
class SendActivityAssigned implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public string $activityId, public string $byUserId, public ?string $message = null)
    {
        $this->captureTenant();
    }

    /**
     * @return list<int> seconds before the 2nd and the 3rd (last) try
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $activity = Activity::query()->with(['type', 'organisation', 'person', 'opportunity.organisation', 'opportunity.person'])->find($this->activityId);
        if ($activity === null || $activity->done_at !== null || $activity->responsible_user_id === null || $activity->responsible_user_id === $this->byUserId) {
            return;
        }

        $responsible = User::query()->find($activity->responsible_user_id);
        if ($responsible === null || ! $responsible->isActive() || ! $responsible->can(Permissions::ACTIVITIES_VIEW)) {
            return;
        }
        $by = User::query()->find($this->byUserId);

        try {
            TenantMailer::for()->to($responsible->email)->send(new ActivityAssignedMail($activity, $by->name ?? 'Un collègue', $this->message));
        } catch (Throwable $e) {
            if (! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
                throw $e;
            }
            Log::warning('E-mail « Action confiée » non envoyé', ['activity_id' => $activity->id, 'exception' => $e::class]);
        }
    }
}
