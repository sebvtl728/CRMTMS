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
        // Append-only journal. No foreign key to users (a row must never be
        // updated by a cascade / set null) and none with ON DELETE CASCADE to
        // tenants: a tenant with a journal cannot be deleted.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('user_id')->nullable();
            $table->string('event');
            $table->string('auditable_type')->nullable();
            $table->string('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'event']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'auditable_type', 'auditable_id']);
        });

        Rls::enable('audit_logs');

        DB::statement(<<<'SQL'
            create or replace function audit_logs_immutable() returns trigger language plpgsql as $$
            begin
                raise exception 'audit_logs is append-only';
            end;
            $$
        SQL);
        DB::statement('create trigger audit_logs_immutable before update or delete on audit_logs for each row execute function audit_logs_immutable()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('drop function if exists audit_logs_immutable()');
    }
};
