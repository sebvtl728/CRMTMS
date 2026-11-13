<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // « simulating »: the simulation runs in the background (wizard polls it).
        DB::statement('alter table import_batches drop constraint import_batches_status_check');
        DB::statement("alter table import_batches add constraint import_batches_status_check check (status in ('uploaded','simulating','simulated','running','done','failed','undoing','undone'))");
    }

    public function down(): void
    {
        DB::statement("update import_batches set status = 'uploaded' where status = 'simulating'");
        DB::statement('alter table import_batches drop constraint import_batches_status_check');
        DB::statement("alter table import_batches add constraint import_batches_status_check check (status in ('uploaded','simulated','running','done','failed','undoing','undone'))");
    }
};
