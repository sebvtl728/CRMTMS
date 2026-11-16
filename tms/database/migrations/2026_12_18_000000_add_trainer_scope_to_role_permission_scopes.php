<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Scope « Ses séances » (trainer, 0.8.0): offered for the séances and
     * attendance rights of the Sessions module only (Permissions::scopesFor).
     */
    public function up(): void
    {
        DB::statement('alter table role_permission_scopes drop constraint role_permission_scopes_scope_check');
        DB::statement("alter table role_permission_scopes add constraint role_permission_scopes_scope_check check (scope in ('own', 'das', 'all', 'trainer'))");
    }

    public function down(): void
    {
        DB::table('role_permission_scopes')->where('scope', 'trainer')->delete();
        DB::statement('alter table role_permission_scopes drop constraint role_permission_scopes_scope_check');
        DB::statement("alter table role_permission_scopes add constraint role_permission_scopes_scope_check check (scope in ('own', 'das', 'all'))");
    }
};
