<?php

namespace App\Modules\Core\Platform;

use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The local sandbox / demo (spec 2026-11-20 bac à sable): reset to working
 * data erased, or to a brand-new organisme. Exists only when TMS_SANDBOX is
 * on AND the app runs locally (never on a server: APP_ENV=production there,
 * and the installer never writes TMS_SANDBOX). The organisme and its default
 * company become fictitious (demonstrations to other organismes).
 */
final class Sandbox
{
    public const WORK = 'work';

    public const ALL = 'all';

    public const NAME = 'Organisme de démonstration';

    public const CONFIRMATION = 'RÉINITIALISER';

    /**
     * Tables kept by « Effacer les données de travail »: accounts and rights,
     * the organisme, its settings and the technical tables. Every other table
     * (a future one too) is working data, erased.
     */
    public const KEPT = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
        'tenants', 'tenant_domains', 'users', 'user_das', 'external_identities',
        'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions',
        'role_permission_scopes', 'role_permission_defaults_applied',
        'reference_items', 'mail_templates', 'document_texts', 'connector_configs', 'finance_settings',
        'custom_field_definitions', 'import_profiles', 'rooms', 'closure_periods', 'companies',
        'calendar_tokens', 'calendar_links',
    ];

    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('tms.sandbox') === true;
    }

    /** @return list<string> the tables « Effacer les données de travail » empties */
    public static function erasedTables(): array
    {
        $tables = array_map(fn (object $t) => (string) $t->table_name, self::db()->select(
            "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name"));

        return array_values(array_diff($tables, self::KEPT));
    }

    /**
     * Kept tables holding a foreign key to an erased one (must stay empty:
     * TRUNCATE without CASCADE would refuse, never empty them silently).
     *
     * @return list<string> « kept → erased »
     */
    public static function keptTablesReferencingErased(): array
    {
        $erased = self::erasedTables();
        $rows = self::db()->select("select tc.table_name as kept, ccu.table_name as erased
            from information_schema.table_constraints tc
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public'");
        $out = [];
        foreach ($rows as $row) {
            if (in_array($row->kept, self::KEPT, true) && in_array($row->erased, $erased, true)) {
                $out[] = "{$row->kept} → {$row->erased}";
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Resets the sandbox; $by stays (and stays signed in) in both modes.
     *
     * @throws RuntimeException outside a sandbox
     */
    public function reset(string $mode, User $by): Tenant
    {
        if (! self::enabled()) {
            throw new RuntimeException('Réinitialisation refusée : ce TMS n\'est pas un bac à sable.');
        }
        $old = app(TenantContext::class)->current();
        $tenant = $mode === self::ALL ? $this->startOver($old, $by) : $this->eraseWork($old);
        Storage::disk(TenantStorage::DISK)->deleteDirectory('tenants/'.$old->id);
        Cache::flush();

        return $tenant;
    }

    private function eraseWork(Tenant $tenant): Tenant
    {
        // TRUNCATE (owner) ignores RLS; the rest runs on the application connection, in the organisme's context.
        self::db()->statement('TRUNCATE '.implode(', ', array_map(fn (string $t) => '"'.$t.'"', self::erasedTables())));
        app(TenantContext::class)->set($tenant);
        DB::transaction(function () use ($tenant): void {
            $default = Company::query()->where('is_default', true)->value('id') ?? Company::query()->orderBy('created_at')->value('id');
            $others = Company::query()->where('id', '!=', $default)->pluck('id')->all();
            DB::table('document_texts')->whereIn('company_id', $others)->delete();
            DB::table('connector_configs')->whereIn('company_id', $others)->update(['company_id' => null]);
            Company::query()->whereKey($others)->get()->each(fn (Company $c) => $c->forceDelete());
            $tenant->forceFill(['name' => self::NAME])->save();
        });
        self::fictitiousCompany();

        return $tenant->refresh();
    }

    private function startOver(Tenant $old, User $by): Tenant
    {
        // Read in the current organisme's context (RLS), before everything goes.
        $account = (array) DB::table('users')->where('id', $by->id)->first();
        self::db()->statement('TRUNCATE "tenants" CASCADE');

        [$tenant] = app(TenantProvisioner::class)->create(self::NAME, $old->slug);
        CapFormationReferenceSeeder::run($tenant);
        app(TenantContext::class)->set($tenant);
        // The administrator who asked, as he was (same id: his session stays valid), inserted in the new organisme's context.
        DB::table('users')->insert([...$account, 'tenant_id' => $tenant->id]);
        User::query()->findOrFail($by->id)->assignRole(Role::AdminOrganisme->value);
        PermissionSync::run();
        self::fictitiousCompany();

        return $tenant;
    }

    /** The default company of the current organisme, fictitious (no real identifier, no logo nor signature). */
    private static function fictitiousCompany(): void
    {
        $company = Company::query()->where('is_default', true)->first() ?? Company::query()->firstOrFail();
        $company->forceFill([
            'name' => self::NAME, 'legal_form' => LegalForm::Sas, 'scop_underlying_form' => null, 'capital_amount' => null,
            'siret' => null, 'rcs_city' => null, 'vat_number' => null, 'nda_number' => null,
            'address_line1' => '1 rue de l\'Exemple', 'address_line2' => null, 'address_postal_code' => '75000', 'address_city' => 'Paris',
            'email' => null, 'phone' => null, 'logo_path' => null, 'signature_path' => null,
            'quote_terms' => null, 'quote_footer' => null, 'invoice_footer' => null, 'is_default' => true,
        ])->save();
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection((string) config('tms.sandbox_connection'));
    }
}
