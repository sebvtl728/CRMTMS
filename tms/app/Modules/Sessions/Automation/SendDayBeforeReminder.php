<?php

namespace App\Modules\Sessions\Automation;

use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one day-before reminder (address and text in the encrypted payload
 * only). Three tries; a failure is logged without the server's text nor the
 * address (a missed reminder is never caught up).
 */
class SendDayBeforeReminder implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public string $to, public string $mailSubject, public string $text)
    {
        $this->captureTenant();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        TenantMailer::for(null)->to($this->to)->send(new DayBeforeMail($this->mailSubject, $this->text));
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Day-before reminder failed.', ['error' => $e::class]);
    }
}
