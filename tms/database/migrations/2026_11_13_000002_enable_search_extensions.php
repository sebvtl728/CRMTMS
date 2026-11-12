<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accent- and typo-tolerant search (CRM): pg_trgm (similarity, trigram
 * indexes) and unaccent, both trusted extensions in PostgreSQL 17, so the
 * owner role (which owns the database) can create them.
 *
 * unaccent() is only STABLE (it depends on the search_path to find its
 * dictionary): immutable_unaccent() pins the dictionary and the schema, so it
 * can be used in generated columns and index expressions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm with schema public');
        DB::statement('create extension if not exists unaccent with schema public');

        DB::statement(<<<'SQL'
            create or replace function public.immutable_unaccent(text) returns text
                language sql immutable parallel safe strict
                as $$ select public.unaccent('public.unaccent'::regdictionary, $1) $$
            SQL);

        DB::statement('revoke all on function public.immutable_unaccent(text) from public');
        DB::statement('grant execute on function public.immutable_unaccent(text) to tms_app');
    }

    public function down(): void
    {
        DB::statement('drop function if exists public.immutable_unaccent(text)');
        // The extensions stay: other objects may depend on them.
    }
};
