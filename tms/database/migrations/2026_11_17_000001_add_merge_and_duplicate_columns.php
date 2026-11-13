<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge of duplicate fiches (the absorbed fiche points at the kept one and
 * stays in the trash for good) and the indexes the duplicate detection
 * relies on: name trigrams, case-insensitive e-mails, phones.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['organisations', 'people'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignUuid('merged_into_id')->nullable()->constrained($table);
                $t->jsonb('merged_sources')->default('[]');
                $t->index('merged_into_id');
            });
        }

        DB::statement('create index organisations_name_trgm on organisations using gin (public.immutable_unaccent(lower(name)) gin_trgm_ops) where deleted_at is null');
        DB::statement('create index organisations_email_lower on organisations (tenant_id, lower(email)) where email is not null and deleted_at is null');
        DB::statement("create index organisations_phone_digits on organisations (tenant_id, regexp_replace(phone, '\\D', '', 'g')) where phone is not null and deleted_at is null");

        DB::statement("create index people_name_trgm on people using gin (public.immutable_unaccent(lower(last_name || ' ' || coalesce(first_name, ''))) gin_trgm_ops) where deleted_at is null");
        DB::statement('create index people_email_lower on people (tenant_id, lower(email)) where email is not null and deleted_at is null');
        DB::statement('create index people_email_secondary_lower on people (tenant_id, lower(email_secondary)) where email_secondary is not null and deleted_at is null');
        DB::statement('create index people_phone on people (tenant_id, phone) where phone is not null and deleted_at is null');
        DB::statement('create index people_mobile on people (tenant_id, mobile) where mobile is not null and deleted_at is null');
    }

    public function down(): void
    {
        foreach (['organisations_name_trgm', 'organisations_email_lower', 'organisations_phone_digits', 'people_name_trgm', 'people_email_lower', 'people_email_secondary_lower', 'people_phone', 'people_mobile'] as $index) {
            DB::statement("drop index if exists {$index}");
        }

        foreach (['organisations', 'people'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('merged_into_id');
                $t->dropColumn('merged_sources');
            });
        }
    }
};
