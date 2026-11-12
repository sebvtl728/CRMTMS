<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business table: one account per (organisme, e-mail). The same e-mail
        // may exist in several tenants; RLS makes each one invisible to the others.
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // null until the invitation is accepted
            $table->boolean('is_platform_admin')->default(false);
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        Rls::enable('users');

        // Reset tokens are keyed by e-mail, which is only unique per tenant:
        // tenant_id is filled from the session setting written by TenantContext
        // (the framework's token repository does not know about tenants), and
        // RLS confines lookups and deletions to the current tenant. Without a
        // tenant context the default is NULL and the insert fails (not null).
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'email']);
        });

        DB::statement("alter table password_reset_tokens alter column tenant_id set default nullif(current_setting('app.tenant_id', true), '')::uuid");
        Rls::enable('password_reset_tokens');

        // Framework table (whitelisted): looked up by session id before any user
        // is known. A session id replayed on another tenant's host resolves no
        // user there, because users is RLS-protected. User::deactivate() purges
        // the rows of a user.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
