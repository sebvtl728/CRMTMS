<?php

namespace App\Modules\Sessions\Notes;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A task of a training session (table session_tasks), written by
 * SessionNotes only. The title is free text: never journaled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property string $title ≤ 200
 * @property CarbonImmutable|null $due_on Paris day
 * @property string|null $assignee_user_id
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class SessionTask extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'session_task';

    protected $table = 'session_tasks';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'done_by'];

    /** @var list<string> */
    protected array $auditPersonal = ['title'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'title', 'due_on', 'assignee_user_id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'done_at' => 'datetime',
        ];
    }
}
