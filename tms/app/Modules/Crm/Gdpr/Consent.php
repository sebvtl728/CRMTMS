<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * One consent given or withdrawn by a person on a channel. History: rows are
 * appended, never changed (only the anonymisation clears `source`, in SQL).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property ConsentChannel $channel
 * @property bool $granted
 * @property string|null $source
 * @property string|null $recorded_by
 * @property Carbon $recorded_at
 */
class Consent extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public $timestamps = false;

    /**
     * Free text: may name someone, the journal keeps only the field name.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['source'];

    /** @var list<string> */
    protected $fillable = ['person_id', 'channel', 'granted', 'source'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (Consent $consent): void {
            $consent->recorded_by ??= Auth::id();
            $consent->recorded_at ??= now();
        });
        static::updating(fn () => throw new LogicException('Un consentement enregistré ne se modifie pas : ajoutez-en un nouveau.'));
        static::deleting(fn () => throw new LogicException('Un consentement enregistré ne se supprime pas.'));
    }

    protected function casts(): array
    {
        return ['channel' => ConsentChannel::class, 'granted' => 'boolean', 'recorded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
