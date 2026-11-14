<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Last quote number given per company and Paris calendar year
     * (QuoteNumbering). One row per (company, year), incremented by an atomic
     * upsert in the transaction that creates the quote: its row lock
     * serialises concurrent creations, a rollback gives the number back.
     */
    public function up(): void
    {
        Schema::create('quote_sequences', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->smallInteger('year');
            $table->integer('last_number');
            $table->timestamps();

            $table->primary(['company_id', 'year']);
        });

        DB::statement('alter table quote_sequences add constraint quote_sequences_last_number_check check (last_number > 0)');
        DB::statement('alter table quote_sequences add constraint quote_sequences_year_check check (year between 2000 and 2999)');

        Rls::enable('quote_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_sequences');
    }
};
