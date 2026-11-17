<?php

namespace App\Modules\Sessions\Notes;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A note of a training session (table session_notes), written by
 * SessionNotes only. The text is free: never journaled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property string $body ≤ 5 000
 * @property string|null $author_user_id
 * @property Carbon|null $created_at
 */
class SessionNote extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'session_note';

    protected $table = 'session_notes';

    /** @var list<string> */
    protected array $auditIgnore = ['author_user_id'];

    /** @var list<string> */
    protected array $auditPersonal = ['body'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'body'];
}
