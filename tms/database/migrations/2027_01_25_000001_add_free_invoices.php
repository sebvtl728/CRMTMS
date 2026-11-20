<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free invoices (spec 2026-11-20 facture libre et co-traitance): an
     * invoice created by hand, without dossier (origin « free »), with the
     * buyer's references (engagement number, service code: Chorus Pro) and
     * lines that may name a co-contractor of the grouping (cotraitant_id:
     * an organisation fiche, no foreign key: Finance never names the CRM's
     * tables).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('origin', 10)->default('dossier');
            $table->string('buyer_reference', 60)->nullable();
            $table->string('service_code', 60)->nullable();
        });
        DB::statement('alter table invoices alter column dossier_id drop not null');
        DB::statement("alter table invoices add constraint invoices_origin_check check (origin in ('dossier', 'free'))");
        DB::statement("alter table invoices add constraint invoices_origin_dossier_check check ((origin = 'dossier') = (dossier_id is not null))");

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->uuid('cotraitant_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('invoices')->where('origin', 'free')->exists()) {
            throw new RuntimeException('Des factures libres existent : retour arrière impossible.');
        }
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('cotraitant_id');
        });
        DB::statement('alter table invoices drop constraint if exists invoices_origin_dossier_check');
        DB::statement('alter table invoices drop constraint if exists invoices_origin_check');
        DB::statement('alter table invoices alter column dossier_id set not null');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['origin', 'buyer_reference', 'service_code']);
        });
    }
};
