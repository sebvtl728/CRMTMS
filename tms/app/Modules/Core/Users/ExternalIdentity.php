<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a local account and an identity of an external provider
 * (future per-organisme OIDC / SSO connector). Unique per (provider, subject).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $provider
 * @property string $subject
 */
class ExternalIdentity extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['provider', 'subject'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
