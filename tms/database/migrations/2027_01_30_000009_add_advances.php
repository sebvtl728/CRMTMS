<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Advances (acomptes): a funding may ask a percent of its amount from a day
 * on, before the training is over; an invoice says it is an advance
 * (« Facture d'acompte »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundings', function (Blueprint $table): void {
            $table->smallInteger('advance_percent')->nullable()->after('pays_directly');
            $table->date('advance_on')->nullable()->after('advance_percent');
        });
        DB::statement('alter table fundings add constraint fundings_advance_check check (
            (advance_percent is null and advance_on is null)
            or (advance_percent between 1 and 100 and advance_on is not null and pays_directly))');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_advance')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('is_advance');
        });
        DB::statement('alter table fundings drop constraint fundings_advance_check');
        Schema::table('fundings', function (Blueprint $table): void {
            $table->dropColumn(['advance_percent', 'advance_on']);
        });
    }
};
