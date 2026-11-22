<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Custom field type « N° de sécurité sociale » (nir): checked and sensitive. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table custom_field_definitions drop constraint custom_field_definitions_type_check');
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_type_check check (type in ('text', 'textarea', 'number', 'date', 'boolean', 'choice', 'file', 'nir'))");
    }

    public function down(): void
    {
        DB::statement('alter table custom_field_definitions drop constraint custom_field_definitions_type_check');
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_type_check check (type in ('text', 'textarea', 'number', 'date', 'boolean', 'choice', 'file'))");
    }
};
