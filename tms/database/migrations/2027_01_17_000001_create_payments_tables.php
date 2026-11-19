<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments and reminders (0.9.1, module Finance):
     *
     *  - payments: money received from a payer (the recipient of invoices)
     *    for one issuing company; never on the invoice, frozen by trigger;
     *  - payment_allocations: the part of a payment settling one invoice
     *    (once per pair); the rest of the payment is « not allocated »;
     *  - invoice_reminder_pauses: invoices whose reminders are suspended
     *    (dispute, payment plan agreed), one row per invoice;
     *  - invoice_sendings.purpose / reminder_level: a reminder is a sending
     *    of the invoice with its level (1 to 3);
     *  - finance_settings.reminder_days / auto_reminders: the organisme's
     *    reminder levels (days after the due date) and automatic sending.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->string('payer_type', 20);
            $table->uuid('payer_id');
            $table->date('received_on');
            $table->bigInteger('amount_cents');
            $table->foreignUuid('method_id')->constrained('reference_items');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payer_type', 'payer_id']);
            $table->index('received_on');
        });
        DB::statement('alter table payments add constraint payments_amount_check check (amount_cents > 0)');
        DB::statement("alter table payments add constraint payments_payer_type_check check (payer_type in ('organisation', 'person'))");
        Rls::enable('payments');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->index()->constrained('invoices');
            $table->bigInteger('amount_cents');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['payment_id', 'invoice_id']);
        });
        DB::statement('alter table payment_allocations add constraint payment_allocations_amount_check check (amount_cents > 0)');
        Rls::enable('payment_allocations');

        Schema::create('invoice_reminder_pauses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices');
            $table->text('reason')->nullable();
            $table->foreignUuid('paused_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Rls::enable('invoice_reminder_pauses');

        Schema::table('invoice_sendings', function (Blueprint $table) {
            $table->string('purpose', 10)->default('invoice');
            $table->smallInteger('reminder_level')->nullable();
        });
        DB::statement("alter table invoice_sendings add constraint invoice_sendings_purpose_check check (purpose in ('invoice', 'reminder'))");
        DB::statement("alter table invoice_sendings add constraint invoice_sendings_level_check check ((purpose = 'reminder') = (reminder_level is not null) and (reminder_level is null or reminder_level between 1 and 3))");

        Schema::table('finance_settings', function (Blueprint $table) {
            $table->jsonb('reminder_days')->default(DB::raw("'[7, 30, 60]'::jsonb"));
            $table->boolean('auto_reminders')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->dropColumn(['reminder_days', 'auto_reminders']);
        });
        DB::statement('alter table invoice_sendings drop constraint if exists invoice_sendings_level_check');
        DB::statement('alter table invoice_sendings drop constraint if exists invoice_sendings_purpose_check');
        Schema::table('invoice_sendings', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'reminder_level']);
        });
        Schema::dropIfExists('invoice_reminder_pauses');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
