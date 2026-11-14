<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quote (devis) of an opportunity, issued by a company of the organisme.
     * The account (entreprise or particulier) is copied from the opportunity;
     * the recipient is an interlocuteur of the entreprise or the particulier.
     * Amounts are stored in euros (numeric(12,2)), computed in integer cents
     * by QuoteTotals. Only a draft is changed; the recipient, the VAT regime
     * and the PDF are frozen when it is sent.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('opportunity_id')->constrained('opportunities');
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('person_id')->nullable()->constrained('people');
            $table->foreignUuid('contact_person_id')->nullable()->constrained('people');
            $table->string('status', 20)->default('draft');
            $table->date('issued_on');
            $table->date('valid_until');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('reminded_at')->nullable();
            $table->date('accepted_on')->nullable();
            $table->date('refused_on')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->uuid('replaced_by_id')->nullable();
            $table->boolean('vat_exempt')->default(false);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_vat', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->jsonb('recipient_snapshot')->nullable();
            $table->string('pdf_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index('opportunity_id');
            $table->index('organisation_id');
            $table->index('person_id');
            $table->index('contact_person_id');
            $table->index(['tenant_id', 'issued_on']);
            $table->index(['tenant_id', 'status']);
        });

        // Self reference: added once the primary key exists.
        Schema::table('quotes', fn (Blueprint $table) => $table->foreign('replaced_by_id')->references('id')->on('quotes'));

        DB::statement("alter table quotes add constraint quotes_status_check check (status in ('draft', 'sent', 'reminded', 'accepted', 'refused', 'expired'))");
        DB::statement('alter table quotes add constraint quotes_account_check check ((organisation_id is null) <> (person_id is null))');
        DB::statement('alter table quotes add constraint quotes_validity_check check (valid_until >= issued_on)');
        DB::statement('alter table quotes add constraint quotes_totals_check check (total_ht >= 0 and total_vat >= 0 and total_ttc = total_ht + total_vat)');

        Rls::enable('quotes');
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
