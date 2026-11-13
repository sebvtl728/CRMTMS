<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * History of the consents of a person, per channel: rows are appended,
     * never changed (the latest one per channel is the current state).
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->boolean('granted');
            // Free text (« Formulaire salon »): cleared by the anonymisation.
            $table->string('source')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at', 6);

            $table->index(['person_id', 'recorded_at']);
        });

        DB::statement("alter table consents add constraint consents_channel_check check (channel in ('email','sms','phone','prospecting'))");

        Rls::enable('consents');
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
