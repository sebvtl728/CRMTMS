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
        // Identity fields are nullable: tms:install / tms:tenant:create create a
        // placeholder default company (name only); the admin form requires the SIRET.
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_form')->default('autre');
            $table->string('scop_underlying_form')->nullable();
            $table->decimal('capital_amount', 14, 2)->nullable();
            $table->boolean('capital_variable')->default(false);
            $table->char('siret', 14)->nullable();
            $table->string('rcs_city')->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->string('vat_regime')->default('subject');
            $table->string('nda_number', 30)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_postal_code', 10)->nullable();
            $table->string('address_city')->nullable();
            $table->char('address_country', 2)->default('FR');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("alter table companies add constraint companies_legal_form_check check (legal_form in ('sarl','sas','sasu','sa','eurl','ei','scop','scic','association','collectivite','etablissement_public','autre'))");
        DB::statement("alter table companies add constraint companies_vat_regime_check check (vat_regime in ('exempt','subject'))");
        DB::statement("alter table companies add constraint companies_scop_underlying_check check (scop_underlying_form is null or (scop_underlying_form in ('sarl','sa','sas') and legal_form in ('scop','scic')))");
        DB::statement("alter table companies add constraint companies_siret_check check (siret is null or siret ~ '^[0-9]{14}$')");
        // One default company per tenant.
        DB::statement('create unique index companies_one_default_per_tenant on companies (tenant_id) where is_default and deleted_at is null');

        Rls::enable('companies');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
