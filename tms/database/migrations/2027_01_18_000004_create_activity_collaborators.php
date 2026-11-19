<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collaborators of an action or a meeting: users who take part besides
     * its responsible (they see it, in « À faire » and the planning too).
     */
    public function up(): void
    {
        Schema::create('activity_collaborators', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['activity_id', 'user_id']);
            $table->index('user_id');
        });
        Rls::enable('activity_collaborators');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_collaborators');
    }
};
