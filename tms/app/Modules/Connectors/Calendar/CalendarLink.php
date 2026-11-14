<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The Outlook (Microsoft 365) link of a user: his OAuth tokens, encrypted
 * with APP_KEY and never serialised. One per user. « À reconnecter »
 * (RECONNECT) once Microsoft refused to refresh them: tokens are dropped
 * and nothing is pushed until the user connects again.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $provider
 * @property string $status
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property Carbon|null $connected_at
 */
class CalendarLink extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const RECONNECT = 'reconnect';

    /** An access token is renewed when it expires within this many seconds. */
    public const EXPIRY_MARGIN_SECONDS = 120;

    /** Exact instants with their offset. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['user_id'];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public static function for(string $userId): ?self
    {
        return self::query()->where('user_id', $userId)->first();
    }

    /**
     * The active link of $userId when its owner may still receive pushes
     * (active account, CRM access), else null.
     */
    public static function usableFor(?string $userId): ?self
    {
        if ($userId === null) {
            return null;
        }
        $link = self::query()->where('user_id', $userId)->where('status', self::ACTIVE)->first();
        $user = $link?->user;

        return $link !== null && $user !== null && $user->isActive() && $user->can(Permissions::VIEW) ? $link : null;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->refresh_token !== null;
    }

    public function accessTokenFresh(): bool
    {
        return $this->access_token !== null && $this->expires_at !== null
            && $this->expires_at->greaterThan(now()->addSeconds(self::EXPIRY_MARGIN_SECONDS));
    }

    /**
     * Stores the tokens of a token endpoint answer (the refresh token is
     * kept when Microsoft does not rotate it).
     *
     * @param  array<string, mixed>  $tokens
     */
    public function storeTokens(array $tokens): void
    {
        $this->forceFill([
            'status' => self::ACTIVE,
            'access_token' => (string) $tokens['access_token'],
            'refresh_token' => is_string($tokens['refresh_token'] ?? null) && $tokens['refresh_token'] !== '' ? $tokens['refresh_token'] : $this->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 3600))),
        ])->save();
    }

    public function markReconnect(): void
    {
        $this->forceFill(['status' => self::RECONNECT, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null])->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CalendarLinkEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarLinkEvent::class);
    }
}
