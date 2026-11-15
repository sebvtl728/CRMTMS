<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\LoggingJob;

/**
 * @return ArrayObject<int, MessageLogged> filled as messages are logged
 */
function captureLogs(): ArrayObject
{
    $logged = new ArrayObject;
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $logged->append($e));

    return $logged;
}

it('log context carries tenant id during a request and a job', function () {
    $logged = captureLogs();
    Route::middleware('web')->get('/_test/log-context', function () {
        Log::info('during request');

        return 'ok';
    });

    $this->get('/_test/log-context')->assertOk();

    $default = Tenant::query()->where('slug', config('tms.default_tenant'))->sole();
    $request = collect($logged)->firstWhere('message', 'during request');
    expect($request->context['tenant_id'] ?? null)->toBe($default->id);

    // Request terminated: the tenant is gone from the shared log context.
    expect(Log::sharedContext())->not->toHaveKey('tenant_id');

    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function () {
        // Statement form: the PendingDispatch is pushed (destructed) inside the context.
        LoggingJob::dispatch()->onConnection('database');
    });
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 0]);

    $job = collect($logged)->firstWhere('message', 'logging job');
    expect($job->context['tenant_id'] ?? null)->toBe($other->id)
        ->and(Log::sharedContext())->not->toHaveKey('tenant_id');

    Log::info('after');
    expect(collect($logged)->firstWhere('message', 'after')->context)->not->toHaveKey('tenant_id');
});

it('follows TenantContext::run and restores the previous tenant', function () {
    $logged = captureLogs();
    $a = inTenant();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->run($b, fn () => Log::info('in b'));
    Log::info('back in a');

    expect(collect($logged)->firstWhere('message', 'in b')->context['tenant_id'])->toBe($b->id)
        ->and(collect($logged)->firstWhere('message', 'back in a')->context['tenant_id'])->toBe($a->id);
});
