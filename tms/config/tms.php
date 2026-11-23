<?php

/*
| Compose passes unset optional variables as empty strings: an empty value
| means "default", never false. Booleans accept true/false/1/0/yes/no/on/off.
*/
$mode = env('TMS_MODE') ?: 'single';
$bool = static function (string $key, bool $default): bool {
    $value = env($key);

    // filter_var() reads '' as false: an empty value must fall back instead.
    return $value === null || $value === '' ? $default : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
};

return [

    /*
    | Tenant resolution mode:
    |  - "single": self-hosted install for one organisation, always the default tenant;
    |  - "multi": the tenant is looked up from the request host (tenant_domains.host).
    */
    'mode' => $mode,

    // Local sandbox / demo (never on a server): TMS_SANDBOX=true AND APP_ENV=local unlock the « Bac à sable »
    // reset (Core\Platform\Sandbox). The installer and update.sh never write it; production runs APP_ENV=production.
    'sandbox' => $bool('TMS_SANDBOX', false),

    // Connection of the reset (TRUNCATE needs the owner); the tests use their own transactional connection.
    'sandbox_connection' => env('TMS_SANDBOX_CONNECTION') ?: 'pgsql_owner',

    // Slug of the tenant used in "single" mode (created by the installer / seeder).
    'default_tenant' => env('TMS_DEFAULT_TENANT') ?: 'default',

    // Recipient of the operational alerts (failed backups...), see `php artisan tms:alert`.
    'alert_email' => env('TMS_ALERT_EMAIL') ?: null,

    // Folder shared with the host (status of the backups, on-demand requests): inside the storage volume,
    // outside storage/app (never backed up nor restored). Null = storage_path('ops'); no environment
    // setting on purpose (the host scripts always use that folder), tests override it with config().
    'ops_path' => null,

    // Follow the Vite dev server (public/hot) when it runs; false = always serve public/build (e2e stack).
    'vite_hot' => $bool('TMS_VITE_HOT', true),

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
            // RNCP certifications (France compétences open data): public, shared by every organisme.
            'rncp_certifications',
        ],
    ],

    'crm' => [

        // Above this many rows an Excel export is refused (« Affinez les filtres »).
        'export_max_rows' => 50000,
    ],

    'connectors' => [

        // Accept loopback / link-local SMTP hosts (a development mail catcher). Keep false in production.
        'allow_internal_hosts' => $bool('TMS_ALLOW_INTERNAL_SMTP_HOSTS', false),

        // Accept private-network SMTP hosts (RFC 1918, IPv6 ULA): a LAN relay. Allowed by default on a
        // self-hosted install (single mode), refused in multi mode (shared platform).
        'allow_private_hosts' => $bool('TMS_ALLOW_PRIVATE_SMTP_HOSTS', $mode === 'single'),

        // Offer the AI connector's « Serveur sur le réseau local » (an AI server on a private address of the
        // organisme's network). Allowed by default on a self-hosted install (single mode), refused in multi
        // mode (an organisme's admin must not reach the shared platform's internal network).
        'allow_local_ai' => $bool('TMS_ALLOW_LOCAL_AI', $mode === 'single'),

        // End-to-end stack only (compose.e2e.yaml): an offline AI answering a fixed training sheet
        // (tests/Support/E2eAiClient). Ignored in production. Never set it on an installation.
        'e2e_fake_ai' => $bool('TMS_E2E_FAKE_AI', false),
    ],

];
