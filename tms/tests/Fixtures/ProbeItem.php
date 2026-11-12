<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $label
 */
class ProbeItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['label'];
}
