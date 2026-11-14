<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Private link of a user's iCal feed (« Mon agenda »).
 *
 * The token is 32 random bytes (64 hex characters) shown once; only its
 * sha256 is stored. The link carries no tenant: the request host resolves
 * the organisme (ResolveTenant) and RLS hides the tokens of every other
 * one. One active token per user (partial unique index): issuing a new one
 * revokes the previous. Generation and revocation are journaled, never the
 * token nor its hash.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $token_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class CalendarToken extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** A use is recorded at most once per this many seconds. */
    public const TOUCH_EVERY_SECONDS = 60;

    /** Exact instants with their offset. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['user_id', 'token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * A new link for $user (the previous one stops working). Returns the
     * plain token, the only time it exists.
     */
    public static function issue(User $user): string
    {
        $plain = bin2hex(random_bytes(32));

        DB::transaction(function () use ($user, $plain) {
            // Two concurrent generations for one user wait for each other.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $replaced = self::revokeActive($user);

            self::query()->create(['user_id' => $user->getKey(), 'token_hash' => self::hashToken($plain)]);

            app(AuditRecorder::class)->record('calendar.token_created', $user, [], ['regenerated' => $replaced]);
        });

        return $plain;
    }

    /**
     * Revokes the active link of $user, if any.
     */
    public static function revoke(User $user): void
    {
        DB::transaction(function () use ($user) {
            if (self::revokeActive($user)) {
                app(AuditRecorder::class)->record('calendar.token_revoked', $user);
            }
        });
    }

    /**
     * The active token of the current organisme matching $plain, or null.
     */
    public static function findActive(string $plain): ?self
    {
        $hash = self::hashToken($plain);
        $token = self::query()->where('token_hash', $hash)->whereNull('revoked_at')->first();

        return $token !== null && hash_equals($token->token_hash, $hash) ? $token : null;
    }

    public static function activeFor(User $user): ?self
    {
        return self::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->first();
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Records the use of the link, at most once a minute (no write per hit).
     */
    public function touchUse(): void
    {
        $now = now();
        if ($this->last_used_at !== null && $this->last_used_at->greaterThan($now->copy()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            return;
        }

        $this->forceFill(['last_used_at' => $now])->saveQuietly();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private static function revokeActive(User $user): bool
    {
        return self::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]) > 0;
    }
}
