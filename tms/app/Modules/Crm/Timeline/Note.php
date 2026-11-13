<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Free-text note on an entreprise or a person. Creating one records a
 * contact (last_contact_at). The text may hold personal data: the journal
 * keeps the fact that it changed, never its content.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $notable_type
 * @property string $notable_id
 * @property string $body
 * @property string|null $author_id
 * @property Carbon $created_at
 * @property Carbon|null $deleted_at
 */
class Note extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MAX_LENGTH = 10000;

    /** @var list<string> */
    protected array $auditPersonal = ['body'];

    /** @var list<string> */
    protected $fillable = ['body'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (Note $note): void {
            $note->author_id ??= Auth::id();
        });
        static::created(fn (Note $note) => LastContact::record($note->notable_type, $note->notable_id, $note->created_at));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
