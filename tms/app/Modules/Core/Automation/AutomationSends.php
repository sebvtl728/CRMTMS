<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The unique claim of an automatic send: called in the transaction that
 * queues it, true only for the first claim of (kind, subject, occurrence).
 */
final class AutomationSends
{
    public const CONVOCATION = 'convocation';

    public const DAY_BEFORE = 'day_before';

    public const SIGNATURE_REMINDER = 'signature_reminder';

    public static function claim(string $kind, string $subjectId, string $occurrence): bool
    {
        return DB::table('automation_sends')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->id(),
            'kind' => $kind,
            'subject_id' => $subjectId,
            'occurrence' => $occurrence,
            'sent_at' => CarbonImmutable::now(),
        ]) === 1;
    }
}
