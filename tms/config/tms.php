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

    // Recipient of the operational alerts (failed backups...), see `php artisan tms:alert`.
    'alert_email' => env('TMS_ALERT_EMAIL'),

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
            // spatie permission catalogue: global names, no tenant data (the
            // role matrix, role_has_permissions, is tenant-scoped under RLS).
            'permissions',
        ],
    ],

    'connectors' => [

        // Accept loopback / link-local SMTP hosts (a development mail catcher). Keep false in production.
        'allow_internal_hosts' => (bool) env('TMS_ALLOW_INTERNAL_SMTP_HOSTS', false),

        // Accept private-network SMTP hosts (RFC 1918, IPv6 ULA): a LAN relay. Allowed by default on a
        // self-hosted install (single mode), refused in multi mode (shared platform).
        'allow_private_hosts' => (bool) env('TMS_ALLOW_PRIVATE_SMTP_HOSTS', env('TMS_MODE', 'single') === 'single'),
    ],

];
