<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests run as the restricted runtime role (tms_app), which cannot alter the
 * schema, so migrate:fresh goes through the owner connection (pgsql_owner).
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as private baseMigrateFreshUsing;
    }

    protected function migrateFreshUsing()
    {
        return array_merge($this->baseMigrateFreshUsing(), ['--database' => 'pgsql_owner']);
    }
}
