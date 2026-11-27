<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Custom fields of the trainings (Administration › Champs personnalisés,
 * entity « training »): the values keyed by the id of the field, as the
 * candidatures. trainings already has its tenant_id and its RLS policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table trainings add column custom_values jsonb not null default '{}'::jsonb");
        DB::statement("alter table trainings add constraint trainings_custom_values_object check (jsonb_typeof(custom_values) = 'object')");
    }

    public function down(): void
    {
        DB::statement('alter table trainings drop column custom_values');
    }
};
