<?php

namespace App\Modules\Core\Platform;

use App\Modules\Core\Access\RoleSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantDomain;
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
     * @param  list<string>  $domains
     *
     * @throws InvalidArgumentException when the slug or a domain is unusable
     */
    public function create(string $name, ?string $slug = null, array $domains = []): Tenant
    {
        $slug = Str::slug($slug !== null && $slug !== '' ? $slug : $name);
        $hosts = array_values(array_unique(array_map(fn (string $h) => mb_strtolower(trim($h)), array_filter($domains))));

        if ($slug === '') {
            throw new InvalidArgumentException('Le nom de l\'organisme ne permet pas de construire un identifiant (slug).');
        }
        if (Tenant::query()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException("Un organisme avec l'identifiant « {$slug} » existe déjà.");
        }
        foreach ($hosts as $host) {
            if (TenantDomain::query()->where('host', $host)->exists()) {
                throw new InvalidArgumentException("Le domaine « {$host} » est déjà utilisé.");
            }
        }

        $tenant = DB::transaction(function () use ($name, $slug, $hosts): Tenant {
            $tenant = Tenant::create(['name' => $name, 'slug' => $slug]);
            foreach ($hosts as $host) {
                $tenant->domains()->create(['host' => $host]);
            }

            return $tenant;
        });

        RoleSeeder::seedFor($tenant);

        return $tenant->load('domains');
    }
}
