<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Connectors\Ai\AiInvalidResponse;
use App\Modules\Connectors\Ai\AiUnavailable;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantAwareJob;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Drafts an online test from the text of a paper one with the AI, on the
 * long-running queue « imports »: a local AI may take several minutes
 * (AI_TIMEOUT_SECONDS). One try; the test shows « Import en cours » until
 * done, then its draft, or the failure with a fixed French reason.
 */
class ImportOnlineTestFromPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public const AI_TIMEOUT_SECONDS = 600;

    public const FAILED = 'L\'import n\'a pas abouti. Réessayez, ou créez le test à la main.';

    public int $tries = 1;

    public int $timeout = 660;

    public function __construct(public string $testId, public string $text)
    {
        $this->captureTenant();
    }

    public function handle(OnlineTestFromPdf $import, OnlineTests $tests): void
    {
        $test = OnlineTest::query()->find($this->testId);
        if ($test === null || $test->import_status !== OnlineTest::IMPORTING) {
            return;
        }
        try {
            $draft = $import->draft($this->text);
        } catch (AiUnavailable|AiInvalidResponse $e) {
            $test->forceFill(['import_status' => OnlineTest::IMPORT_FAILED, 'import_error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return;
        }
        $content = OnlineTestContent::validate($draft['content']);
        $test->forceFill(['title' => $draft['title'], 'draft_content' => $content, 'import_status' => null, 'import_error' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->run($tenant, fn () => OnlineTest::query()->whereKey($this->testId)->where('import_status', OnlineTest::IMPORTING)
            ->update(['import_status' => OnlineTest::IMPORT_FAILED, 'import_error' => self::FAILED]));
    }
}
