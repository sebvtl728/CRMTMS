<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The brochure versions joined to the e-mail of a quote when it was sent:
     * a reminder or « Renvoyer » joins exactly these, even after the
     * brochure was replaced or trashed. A version is never erased, so the
     * foreign key restricts.
     */
    public function up(): void
    {
        Schema::create('quote_brochures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignUuid('brochure_version_id')->constrained('brochure_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['quote_id', 'brochure_version_id']);
            $table->index('brochure_version_id');
        });

        Rls::enable('quote_brochures');
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_brochures');
    }
};
