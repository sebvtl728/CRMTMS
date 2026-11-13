<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « À anonymiser »: people past the retention period, listed by the
     * nightly job; anonymised once listed for 30 days (ids only).
     */
    public function up(): void
    {
        Schema::create('gdpr_pending', function (Blueprint $table) {
            $table->foreignUuid('person_id')->primary()->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestampTz('listed_at');

            $table->index(['tenant_id', 'listed_at']);
        });

        Rls::enable('gdpr_pending');
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_pending');
    }
};
