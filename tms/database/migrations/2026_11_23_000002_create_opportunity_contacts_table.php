<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interlocuteurs of an opportunity: people affiliated to its entreprise,
     * or the particulier himself.
     */
    public function up(): void
    {
        Schema::create('opportunity_contacts', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->primary(['opportunity_id', 'person_id']);
            $table->index('person_id');
        });

        Rls::enable('opportunity_contacts');
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_contacts');
    }
};
