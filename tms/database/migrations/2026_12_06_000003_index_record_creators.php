<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Ses fiches » (scope own) = responsible_user_id or created_by: with an
 * index on both columns, PostgreSQL combines them (BitmapOr) instead of
 * reading the whole table. Plain columns compared by = (leakproof): usable
 * under FORCE RLS.
 */
return new class extends Migration
{
    private const CREATED_BY = ['organisations', 'people', 'opportunities', 'quotes', 'activities'];

    public function up(): void
    {
        foreach (self::CREATED_BY as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->index(['tenant_id', 'created_by']));
        }
        // activities only had a partial index (actions to do) on its responsible.
        Schema::table('activities', fn (Blueprint $t) => $t->index(['tenant_id', 'responsible_user_id']));
    }

    public function down(): void
    {
        Schema::table('activities', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'responsible_user_id']));
        foreach (self::CREATED_BY as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'created_by']));
        }
    }
};
