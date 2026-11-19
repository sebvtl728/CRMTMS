<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Documents (0.10): the organisme's own texts of the training
     * documents, block by block, for the organisme (company_id null) or one
     * company. Without a row, the TMS default text (DocumentTexts).
     */
    public function up(): void
    {
        Schema::create('document_texts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('block', 40);
            $table->text('body');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX document_texts_company_unique ON document_texts (tenant_id, company_id, type, block) WHERE company_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX document_texts_organisme_unique ON document_texts (tenant_id, type, block) WHERE company_id IS NULL');
        Rls::enable('document_texts');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_texts');
    }
};
