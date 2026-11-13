<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One fiche created or updated by one import (see ImportStamps). Written in
 * bulk, never journaled (bookkeeping, no personal data).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $batch_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $stamped_at
 * @property bool $created
 * @property bool $batch_undone
 * @property Carbon|null $trashed_by_undo_at
 */
class ImportStamp extends Model
{
    use Auditable, BelongsToTenant;

    public $timestamps = false;

    /** @var list<string> */
    protected array $auditIgnore = [
        'id', 'tenant_id', 'batch_id', 'subject_type', 'subject_id', 'stamped_at', 'created', 'batch_undone', 'trashed_by_undo_at',
    ];

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created' => 'boolean', 'batch_undone' => 'boolean', 'trashed_by_undo_at' => 'datetime'];
    }
}
