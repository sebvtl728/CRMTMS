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
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->char('siret', 14)->nullable();
            $table->string('naf_code', 6)->nullable();
            $table->foreignUuid('legal_form_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('sector_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('establishment_type_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('company_size_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('origin_id')->nullable()->constrained('reference_items');
            $table->string('status')->default('prospect');
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('FR');
            $table->text('notes_summary')->nullable();
            $table->timestampTz('last_contact_at')->nullable();
            $table->string('source_system', 50)->nullable();
            $table->string('source_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'responsible_user_id']);
        });

        DB::statement("alter table organisations add constraint organisations_status_check check (status in ('prospect','client','inactif'))");
        DB::statement("alter table organisations add constraint organisations_siret_check check (siret is null or siret ~ '^[0-9]{14}$')");

        // Stored, accent- and case-insensitive text for the trigram search.
        DB::statement("alter table organisations add column search_vector text generated always as (public.immutable_unaccent(lower(coalesce(name, '') || ' ' || coalesce(city, '') || ' ' || coalesce(siret, '')))) stored");
        DB::statement('create index organisations_search_vector_trgm on organisations using gin (search_vector gin_trgm_ops)');

        DB::statement('create unique index organisations_siret_unique on organisations (tenant_id, siret) where siret is not null and deleted_at is null');
        DB::statement('create unique index organisations_source_unique on organisations (tenant_id, source_system, source_id) where source_system is not null and source_id is not null');

        Rls::enable('organisations');

        Schema::create('organisation_das', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->primary(['organisation_id', 'das_id']);
            $table->index('das_id');
        });
        Rls::enable('organisation_das');

        // DAS followed by a user with the role Responsable DAS (see CrmVisibility).
        Schema::create('user_das', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->primary(['user_id', 'das_id']);
            $table->index('das_id');
        });
        Rls::enable('user_das');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_das');
        Schema::dropIfExists('organisation_das');
        Schema::dropIfExists('organisations');
    }
};
