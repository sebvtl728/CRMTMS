<?php

namespace Tests\Fixtures;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProbeItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['label'];
}
