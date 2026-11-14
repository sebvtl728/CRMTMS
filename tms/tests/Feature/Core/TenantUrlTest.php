<?php

use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Tenancy\TenantUrl;

it('builds links on the organisme first domain in multi mode, else on APP_URL', function () {
    config(['app.url' => 'https://tms.example.test/']);
    expect(TenantUrl::root())->toBe('https://tms.example.test')
        ->and(TenantUrl::route('crm.todo'))->toBe('https://tms.example.test/crm/todo');

    config(['tms.mode' => 'multi']);
    expect(TenantUrl::root())->toBe('https://tms.example.test');
    TenantDomain::factory()->for(tenant())->create(['host' => 'Zeta.Example.Test']);
    TenantDomain::factory()->for(tenant())->create(['host' => 'cap.example.test']);
    expect(TenantUrl::root())->toBe('https://cap.example.test')
        ->and(TenantUrl::route('crm.todo'))->toBe('https://cap.example.test/crm/todo')
        ->and(TenantUrl::root(domain: 'autre.example.test'))->toBe('https://autre.example.test');
});
