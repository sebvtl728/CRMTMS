<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately broken: maps a tenant table (probe_items) without
 * BelongsToTenant. Used to prove that tests/Arch/ModelsArchTest.php fails.
 */
class UnscopedProbeItem extends Model
{
    protected $table = 'probe_items';
}
