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
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('civility', 5)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('email_secondary')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('FR');
            $table->date('birth_date')->nullable();
            $table->boolean('is_individual')->default(false);
            $table->text('project')->nullable();
            $table->foreignUuid('professional_situation_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('funding_mode_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('origin_id')->nullable()->constrained('reference_items');
            $table->string('status')->default('prospect');
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_contact_at')->nullable();
            $table->string('source_system', 50)->nullable();
            $table->string('source_id')->nullable();
            $table->timestampTz('anonymised_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'responsible_user_id']);
            $table->index(['tenant_id', 'last_name', 'first_name']);
        });

        DB::statement("alter table people add constraint people_status_check check (status in ('prospect','client','inactif'))");
        DB::statement("alter table people add constraint people_civility_check check (civility is null or civility in ('M.','Mme'))");

        // Name, e-mails and both phone forms (+33612345678 and 0612345678), accent- and case-insensitive.
        DB::statement(<<<'SQL'
            alter table people add column search_vector text generated always as (
                public.immutable_unaccent(lower(
                    coalesce(first_name, '') || ' ' || coalesce(last_name, '') || ' '
                    || coalesce(email, '') || ' ' || coalesce(email_secondary, '') || ' '
                    || coalesce(phone, '') || ' ' || regexp_replace(coalesce(phone, ''), '^\+33', '0') || ' '
                    || coalesce(mobile, '') || ' ' || regexp_replace(coalesce(mobile, ''), '^\+33', '0')
                ))
            ) stored
            SQL);
        DB::statement('create index people_search_vector_trgm on people using gin (search_vector gin_trgm_ops)');
        DB::statement('create unique index people_source_unique on people (tenant_id, source_system, source_id) where source_system is not null and source_id is not null');

        Rls::enable('people');

        // Optional DAS of a person: lets a Responsable DAS see the individuals he follows.
        Schema::create('person_das', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->primary(['person_id', 'das_id']);
            $table->index('das_id');
        });
        Rls::enable('person_das');
    }

    public function down(): void
    {
        Schema::dropIfExists('person_das');
        Schema::dropIfExists('people');
    }
};
