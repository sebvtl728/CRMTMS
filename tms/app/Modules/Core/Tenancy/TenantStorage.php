<?php

namespace App\Modules\Core\Tenancy;

use InvalidArgumentException;

/**
 * Paths of the current tenant's files on the private disk: every file of an
 * organisme lives under tenants/<tenant_id>/<area>/, so one tenant can never
 * address another's files and a tenant's data can be exported or purged as a
 * whole.
 */
final class TenantStorage
{
    public const DISK = 'local';

    public const AREAS = ['imports', 'attachments', 'exports', 'gdpr', 'companies', 'quotes', 'brochures', 'attendance_sheets'];

    /**
     * @throws MissingTenantContext outside a tenant context
     * @throws InvalidArgumentException on an unknown area or an escaping path
     */
    public static function path(string $area, string $relative = ''): string
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new InvalidArgumentException("Zone de stockage inconnue : « {$area} ».");
        }

        $base = 'tenants/'.app(TenantContext::class)->id().'/'.$area;
        $relative = trim($relative, '/');

        if ($relative === '') {
            return $base;
        }

        if (str_contains($relative, "\0") || in_array('..', explode('/', str_replace('\\', '/', $relative)), true)) {
            throw new InvalidArgumentException('Chemin de fichier invalide.');
        }

        return $base.'/'.$relative;
    }
}
