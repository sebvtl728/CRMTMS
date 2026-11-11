<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * The spatie team is always the current tenant: it is read from TenantContext
 * on every use, so it follows set/run/clear everywhere (HTTP requests and
 * their terminate, queue jobs, console) without any synchronisation to forget.
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return app(TenantContext::class)->current()?->id;
    }

    /**
     * @param  int|string|Model|null  $id
     */
    public function setPermissionsTeamId($id): void
    {
        $id = $id instanceof Model ? $id->getKey() : $id;

        if ($id !== $this->getPermissionsTeamId()) {
            throw new LogicException('L\'équipe des permissions suit l\'organisme courant : utilisez TenantContext.');
        }
    }
}
