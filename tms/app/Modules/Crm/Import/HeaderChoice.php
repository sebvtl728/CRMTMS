<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One previous choice of the organisme at the « Colonnes » step (see
 * HeaderChoices). Bookkeeping, never journaled: a header key and a field
 * code, no cell value.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $target
 * @property string $header_key
 * @property string|null $field null: « Ignorer »
 * @property int $uses
 * @property Carbon $last_used_at
 */
class HeaderChoice extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'import_header_choices';

    public $timestamps = false;

    /** @var list<string> */
    protected array $auditIgnore = ['id', 'tenant_id', 'target', 'header_key', 'field', 'uses', 'last_used_at'];

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['uses' => 'integer', 'last_used_at' => 'datetime'];
    }
}
