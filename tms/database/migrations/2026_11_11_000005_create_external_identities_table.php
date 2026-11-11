<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identities from an external provider (future per-tenant OIDC/SSO)
        // linked to a local account. Not used by lot 1 authentication.
        Schema::create('external_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('provider');
            $table->string('subject');
            $table->timestamps();

            $table->unique(['provider', 'subject']);
        });

        Rls::enable('external_identities');
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identities');
    }
};
