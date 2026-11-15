<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Revenue\Period;
use App\Modules\Crm\Revenue\RevenueTarget;
use App\Modules\Crm\Revenue\RevenueTargets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('sets, replaces and clears a target', function () {
    $this->actingAs(direction());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $month = Period::of('month', '2026-11');
    $targets = app(RevenueTargets::class);

    $targets->set($das->id, $month, 123456);
    $row = RevenueTarget::query()->sole();
    expect($row->amount)->toBe('1234.56')
        ->and($row->period_type)->toBe('month')
        ->and($row->period_start->toDateString())->toBe('2026-11-01')
        ->and($row->created_by)->not->toBeNull();

    $targets->set($das->id, $month, 200000);
    expect(RevenueTarget::query()->count())->toBe(1)->and(RevenueTarget::query()->sole()->amount)->toBe('2000.00');

    // Another period type is another target.
    $targets->set($das->id, Period::of('quarter', '2026-T4'), 500000);
    expect(RevenueTarget::query()->count())->toBe(2);

    $targets->set($das->id, $month, null);
    expect(RevenueTarget::query()->count())->toBe(1);
    $targets->set($das->id, Period::of('quarter', '2026-T4'), 0);
    expect(RevenueTarget::query()->count())->toBe(0);
    $targets->set($das->id, $month, 0); // nothing to clear: no error
    expect(AuditLog::query()->where('auditable_type', 'revenue_target')->count())->toBeGreaterThanOrEqual(4);
});

it('requires revenue.manage', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $this->actingAs(commercial()); // revenue.view only
    expect(fn () => app(RevenueTargets::class)->set($das->id, Period::of('year', '2026'), 100))
        ->toThrow(HttpException::class);
    expect(RevenueTarget::query()->count())->toBe(0);
});

it('refuses a negative amount, an unknown DAS or a value of another list', function () {
    $this->actingAs(direction());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $stage = pipelineStage('Nouveau');
    $targets = app(RevenueTargets::class);

    expect(fn () => $targets->set($das->id, Period::of('year', '2026'), -1))->toThrow(ValidationException::class)
        ->and(fn () => $targets->set($stage->id, Period::of('year', '2026'), 100))->toThrow(ValidationException::class)
        ->and(fn () => $targets->set((string) Str::uuid(), Period::of('year', '2026'), 100))->toThrow(ValidationException::class);
});

it('the DAS of a target cannot be deleted from the reference list', function () {
    $this->actingAs(direction());
    $das = crmItem(ReferenceList::Das, 'Métiers supports');
    app(RevenueTargets::class)->set($das->id, Period::of('year', '2026'), 100000);

    expect($das->isInUse())->toBeTrue()
        ->and(crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->isInUse())->toBeFalse();
});

it('targets are tenant isolated', function () {
    $this->actingAs(direction());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    app(RevenueTargets::class)->set($das->id, Period::of('year', '2026'), 100000);

    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function () {
        expect(RevenueTarget::query()->count())->toBe(0);
    });
    expect(RevenueTarget::query()->count())->toBe(1);
});

it('saves as an update when a concurrent first save created the target meanwhile', function () {
    $this->actingAs(direction());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $month = Period::of('month', '2026-11');

    // The other request inserts the same target between our read and our insert.
    $raced = false;
    RevenueTarget::creating(function () use (&$raced, $das): void {
        if ($raced) {
            return;
        }
        $raced = true;
        DB::table('revenue_targets')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => tenant()->id, 'das_id' => $das->id, 'period_type' => 'month',
            'period_start' => '2026-11-01', 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    app(RevenueTargets::class)->set($das->id, $month, 250000);

    expect(RevenueTarget::query()->count())->toBe(1)
        ->and(RevenueTarget::query()->sole()->amount)->toBe('2500.00');
});
