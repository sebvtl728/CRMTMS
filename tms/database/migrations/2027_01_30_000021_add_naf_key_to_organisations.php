<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalised APE (NAF) code of an entreprise, for the list filter: no dot,
 * no space, upper case (« 56.10a » → « 5610A »). A stored generated column
 * compared by range, because under row-level security an expression index
 * on upper()/replace() is never used. Collation C so that a prefix range
 * (« 56 » ≤ key < « 57 ») follows the byte order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table organisations add column naf_key text collate \"C\" generated always as (nullif(upper(replace(replace(naf_code, '.', ''), ' ', '')), '')) stored");
        DB::statement('create index organisations_naf_key on organisations (tenant_id, naf_key) where naf_key is not null and deleted_at is null');
        DB::statement('analyze organisations');
    }

    public function down(): void
    {
        DB::statement('drop index if exists organisations_naf_key');
        DB::statement('alter table organisations drop column if exists naf_key');
    }
};
