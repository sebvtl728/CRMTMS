<?php

namespace App\Modules\Core\Automation;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The automations run every morning in each organisme (tms:automation:daily):
 * each module declares its own in its boot. One failing automation is logged
 * (key and exception class only) and never stops the others.
 */
final class DailyAutomations
{
    /** @var array<string, Closure(CarbonImmutable): int> */
    private static array $automations = [];

    /** @param  Closure(CarbonImmutable): int  $run  the number of sends queued */
    public static function register(string $key, Closure $run): void
    {
        self::$automations[$key] = $run;
    }

    /** @return array<string, int|null> the sends queued per automation, null when it failed */
    public static function runAll(CarbonImmutable $today): array
    {
        $done = [];
        foreach (self::$automations as $key => $run) {
            try {
                $done[$key] = $run($today);
            } catch (Throwable $e) {
                Log::error('Automation failed.', ['automation' => $key, 'error' => $e::class]);
                $done[$key] = null;
            }
        }

        return $done;
    }

    /** For the tests. */
    public static function flush(): void
    {
        self::$automations = [];
    }
}
