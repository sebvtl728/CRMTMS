<?php

return [

    /*
    | Tenant resolution mode:
    |  - "single": self-hosted install for one organisation, always the default tenant;
    |  - "multi": the tenant is looked up from the request host (tenant_domains.host).
    */
    'mode' => env('TMS_MODE', 'single'),

    // Slug of the tenant used in "single" mode (created by the installer / seeder).
    'default_tenant' => env('TMS_DEFAULT_TENANT', 'default'),

    'tenancy' => [

        // Database connection whose session carries app.tenant_id (runtime role tms_app).
        'connection' => 'pgsql',

        /*
        | Tables exempt from the tenant_id + RLS rule (tests/Arch/TenancyArchTest.php).
        | Only framework/platform tables without business data belong here, and
        | only tables that actually exist (the architecture test checks both).
        */
        'unscoped_tables' => [
            'tenants',
            'tenant_domains',
            'migrations',
            'jobs',
            'job_batches',
            'failed_jobs',
            'cache',
            'cache_locks',
            'sessions',
            'password_reset_tokens',
            'users', // TODO Task 4: remove (users gets tenant_id + RLS)
        ],
    ],

];
