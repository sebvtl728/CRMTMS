<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-mails of invoices and credit notes (0.9): one row per sending, kept
     * as the history of « Envoyer » and « Renvoyer ». A validated invoice
     * is frozen by trigger (only status, sent_at, email_status and
     * email_error_code may change), so the recipient, the subject and the
     * message live here, never on the invoice: pending → sent | failed
     * (the only change a row gets, written by SendInvoice).
     */
    public function up(): void
    {
        Schema::create('invoice_sendings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->index()->constrained('invoices');
            $table->string('email', 254);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 10)->default('pending');
            $table->string('error_code', 40)->nullable();
            $table->text('error')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
        });
        DB::statement("alter table invoice_sendings add constraint invoice_sendings_status_check check (status in ('pending', 'sent', 'failed'))");
        Rls::enable('invoice_sendings');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sendings');
    }
};
