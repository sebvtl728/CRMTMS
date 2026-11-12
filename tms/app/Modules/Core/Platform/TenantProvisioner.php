<?php

namespace App\Modules\Core\Platform;

use App\Modules\Core\Access\RoleSeeder;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantDomain;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates an organisme (tenant) with its domains and default roles.
 * Shared by tms:tenant:create and tms:install.
 */
final class TenantProvisioner
{
    /**
     * Creates tenant, domains, roles and the placeholder default company (named
     * after the tenant, legal form « autre », no SIRET yet: completed in the
     * administration) in ONE transaction. $within (optional)
     * runs inside that transaction, in the new tenant's context, so callers can
     * add tenant-scoped steps (admin, invitation...): any failure leaves nothing.
     *
     * @param  list<string>  $domains
     * @param  (callable(Tenant): mixed)|null  $within
     * @return array{0: Tenant, 1: mixed}
     *
     * @throws InvalidArgumentException when the slug or a domain is unusable
     */
    public function create(string $name, ?string $slug = null, array $domains = [], ?callable $within = null, bool $vatExempt = false): array
    {
        $slug = Str::slug($slug !== null && $slug !== '' ? $slug : $name);
        $hosts = array_values(array_unique(array_map(fn (string $h) => mb_strtolower(trim($h)), array_filter($domains))));

        if ($slug === '') {
            throw new InvalidArgumentException('Le nom de l\'organisme ne permet pas de construire un identifiant (slug).');
        }
        foreach ($hosts as $host) {
            self::assertHostname($host);
        }
        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("Un organisme avec l'identifiant « {$slug} » existe déjà.");
        }
        foreach ($hosts as $host) {
            if (TenantDomain::query()->where('host', $host)->exists()) {
                throw new InvalidArgumentException("Le domaine « {$host} » est déjà utilisé.");
            }
        }

        try {
            return DB::transaction(function () use ($name, $slug, $hosts, $within, $vatExempt): array {
                $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);
                foreach ($hosts as $host) {
                    $tenant->domains()->create(['host' => $host]);
                }

                RoleSeeder::seedFor($tenant);
                $result = app(TenantContext::class)->run($tenant, function () use ($tenant, $within, $vatExempt) {
                    Company::create([
                        'name' => $tenant->name,
                        'legal_form' => LegalForm::Autre,
                        'vat_regime' => $vatExempt ? VatRegime::Exempt : VatRegime::Subject,
                        'is_default' => true,
                    ]);

                    return $within !== null ? $within($tenant) : null;
                });

                return [$tenant->load('domains'), $result];
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent creation between the checks above and the insert.
            throw new InvalidArgumentException("Cet identifiant (« {$slug} ») ou l'un de ces domaines est déjà utilisé.");
        }
    }

    /**
     * Bare host name only (no scheme, port, path or spaces): either "localhost"
     * or a dotted name whose labels are letters, digits and inner hyphens.
     */
    public static function assertHostname(string $host): void
    {
        $valid = strlen($host) <= 253
            && ($host === 'localhost'
                || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1);

        if (! $valid) {
            throw new InvalidArgumentException("Domaine invalide : « {$host} ». Indiquez un nom d'hôte seul, sans schéma, port ni chemin (ex. cap.example.org).");
        }
    }
}
