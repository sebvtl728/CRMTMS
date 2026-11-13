<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fiches each user opened last (dashboard « dernières fiches
     * consultées »). Only ids: names are read from the fiche when shown, so
     * nothing personal is copied here and an anonymised fiche shows as such.
     */
    public function up(): void
    {
        Schema::create('recent_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 30);
            $table->uuid('subject_id');
            $table->timestampTz('viewed_at', 6);

            $table->unique(['user_id', 'subject_type', 'subject_id']);
            $table->index(['user_id', 'viewed_at']);
            $table->index('tenant_id');
        });

        Rls::enable('recent_views');
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_views');
    }
};
