<?php

namespace App\Modules\Crm\Affiliations;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\People\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Link between a person and an entreprise (job title, decision role, work
 * contact). Active while ended_on is null; at most one active link per pair.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $organisation_id
 * @property string|null $job_title
 * @property string|null $decision_role_id
 * @property string|null $work_email
 * @property string|null $work_phone
 * @property bool $is_referent
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $deleted_at
 */
class Affiliation extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * Personal data: the journal keeps the field name, never the value.
     *
     * @var list<string>
     */
    public const PERSONAL_FIELDS = ['work_email', 'work_phone'];

    /** @var list<string> */
    protected array $auditPersonal = self::PERSONAL_FIELDS;

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = [
        'person_id', 'organisation_id', 'job_title', 'decision_role_id', 'work_email', 'work_phone', 'is_referent', 'started_on', 'ended_on',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['is_referent' => false];

    protected static function booted(): void
    {
        static::creating(function (Affiliation $affiliation): void {
            $affiliation->created_by ??= Auth::id();
            $affiliation->updated_by ??= Auth::id();
        });
        static::saving(function (Affiliation $affiliation): void {
            if ($affiliation->isDirty('work_phone') && is_string($affiliation->work_phone)) {
                $affiliation->work_phone = PhoneNumber::normalise($affiliation->work_phone);
            }
        });
        static::updating(function (Affiliation $affiliation): void {
            if (Auth::id() !== null) {
                $affiliation->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_referent' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    /**
     * Soft-deletes the affiliations of a person / entreprise that has just
     * been deleted: people are never deleted with their entreprise.
     *
     * @param  'person_id'|'organisation_id'  $column
     */
    public static function cascadeDelete(string $column, Model $owner): void
    {
        static::query()->where($column, $owner->getKey())->get()->each->delete();
    }

    /**
     * Restores the affiliations that were deleted together with $owner (not
     * those removed on their own earlier). Called while $owner is restored.
     *
     * @param  'person_id'|'organisation_id'  $column
     */
    public static function cascadeRestore(string $column, Model $owner): void
    {
        $deletedAt = $owner->getAttribute('deleted_at');

        if (! $deletedAt instanceof Carbon) {
            return;
        }

        // Timestamps are stored to the second.
        static::onlyTrashed()->where($column, $owner->getKey())->where('deleted_at', '>=', $deletedAt->copy()->startOfSecond())->get()->each->restore();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function decisionRole(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'decision_role_id');
    }

    public function isActive(): bool
    {
        return $this->ended_on === null && $this->deleted_at === null;
    }
}
