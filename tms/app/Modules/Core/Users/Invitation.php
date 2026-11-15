<?php

namespace App\Modules\Core\Users;

use App\Modules\Core\Access\RoleCatalogue;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invitation to activate an account of the current organisme.
 *
 * The token is 64 random bytes (128 hex characters) shown once, in the
 * activation link; only its sha256 is stored. Valid 24 hours, single use.
 * The role is kept by name: a role deleted meanwhile makes the invitation
 * unusable (ROLE_GONE).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $name
 * @property string $role name of the invited role (enum value or custom_…)
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $user_id
 * @property string|null $invited_by
 */
class Invitation extends Model
{
    use BelongsToTenant, HasUuids;

    public const VALIDITY_HOURS = 24;

    public const ROLE_GONE = 'Ce rôle n\'existe plus. Demandez une nouvelle invitation.';

    /**
     * Plain token, only available on the instance returned by issue().
     */
    public ?string $plainToken = null;

    protected $fillable = ['email', 'name', 'role', 'token_hash', 'expires_at', 'invited_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Creates an invitation in the current tenant. A pending invitation for the
     * same e-mail is replaced (its link stops working).
     */
    public static function issue(string $email, string $name, RoleModel $role, ?User $invitedBy = null): self
    {
        $email = mb_strtolower(trim($email));
        $plain = bin2hex(random_bytes(64));

        $invitation = DB::transaction(function () use ($email, $name, $role, $plain, $invitedBy): self {
            // The role stays until commit: a concurrent deletion then sees this invitation.
            if (RoleCatalogue::lockedForAssignment($role->name) === null) {
                throw ValidationException::withMessages(['role' => 'Ce rôle n\'existe plus.']);
            }

            static::query()->pending()->where('email', $email)->delete();

            return static::create([
                'email' => $email,
                'name' => $name,
                'role' => $role->name,
                'token_hash' => self::hashToken($plain),
                'expires_at' => now()->addHours(self::VALIDITY_HOURS),
                'invited_by' => $invitedBy?->getKey(),
            ]);
        });
        $invitation->plainToken = $plain;

        return $invitation;
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Pending invitation of the current tenant for this token (accepted ones are
     * excluded: a used link behaves like an unknown one).
     */
    public static function findPendingByToken(string $plain): ?self
    {
        return static::query()->pending()->where('token_hash', self::hashToken($plain))->first();
    }

    /**
     * The invited role in the current organisme, null once deleted.
     */
    public function roleModel(): ?RoleModel
    {
        return RoleModel::named($this->role);
    }

    public function roleLabel(): string
    {
        return $this->roleModel()?->label() ?? 'Rôle supprimé';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
