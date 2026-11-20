<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payouts to the co-contractors of a grouping (spec 2026-11-20 facture
     * libre et co-traitance): what the mandataire paid back to a
     * co-contractor for the lines it delivered on an invoice. Never written
     * on the invoice (frozen). cotraitant_id: an organisation fiche (no
     * foreign key: Finance never names the CRM's tables).
     */
    public function up(): void
    {
        Schema::create('cotraitant_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->index()->constrained('invoices');
            $table->uuid('cotraitant_id')->index();
            $table->bigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('alter table cotraitant_payouts add constraint cotraitant_payouts_amount_check check (amount_cents > 0)');
        Rls::enable('cotraitant_payouts');
    }

    public function down(): void
    {
        Schema::dropIfExists('cotraitant_payouts');
    }
};
