<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lines of a quote, in order. A line may come from a training of the
     * catalogue (its DAS copied, for the turnover by DAS) or be free (fees).
     * total_ht is the rounded line amount (QuoteTotals). A training or a DAS
     * used by a line (of a trashed quote too) cannot be deleted.
     */
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);
            $table->foreignUuid('training_id')->nullable()->constrained('trainings')->restrictOnDelete();
            $table->foreignUuid('das_id')->nullable()->constrained('reference_items');
            $table->string('label', 300);
            $table->text('description')->nullable();
            $table->decimal('quantity', 8, 2);
            $table->string('unit', 20);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('vat_rate', 4, 2);
            $table->decimal('total_ht', 12, 2);
            $table->timestamps();

            $table->index('quote_id');
            $table->index('training_id');
            $table->index('das_id');
        });

        DB::statement('alter table quote_lines add constraint quote_lines_quantity_check check (quantity > 0)');
        DB::statement("alter table quote_lines add constraint quote_lines_unit_check check (unit in ('stagiaire', 'jour', 'heure', 'forfait', 'session'))");
        DB::statement('alter table quote_lines add constraint quote_lines_amounts_check check (unit_price >= 0 and total_ht >= 0)');
        DB::statement('alter table quote_lines add constraint quote_lines_discount_check check (discount_percent between 0 and 100)');
        DB::statement('alter table quote_lines add constraint quote_lines_vat_rate_check check (vat_rate in (20, 10, 5.5, 2.1, 0))');

        Rls::enable('quote_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};
