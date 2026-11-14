<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private links of the iCal feed (« Mon agenda »): 32 random bytes shown
     * once to their user, only their sha256 is stored. One active link per
     * user; a regenerated or revoked one keeps its row (revoked_at).
     */
    public function up(): void
    {
        Schema::create('calendar_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 of the token, never the token itself
            $table->timestampTz('created_at', 6)->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('revoked_at', 6)->nullable();
        });

        DB::statement('create unique index calendar_tokens_active_unique on calendar_tokens (user_id) where revoked_at is null');

        Rls::enable('calendar_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_tokens');
    }
};
