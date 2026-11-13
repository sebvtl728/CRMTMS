<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Writes one log line, so a test can inspect the log context of a job. */
class LoggingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Log::info('logging job');
    }
}
