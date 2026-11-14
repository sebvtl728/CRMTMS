<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a company prints on its quotes: logo (file on the private disk,
     * TenantStorage zone « companies »), number prefix, validity, terms and
     * footer. Existing companies get the defaults.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('quote_prefix', 10)->default('DEV');
            $table->smallInteger('quote_validity_days')->default(30);
            $table->text('quote_terms')->nullable();
            $table->text('quote_footer')->nullable();
        });

        DB::statement("alter table companies add constraint companies_quote_prefix_check check (quote_prefix ~ '^[A-Z0-9]{1,10}$')");
        DB::statement('alter table companies add constraint companies_quote_validity_check check (quote_validity_days between 1 and 365)');
    }

    public function down(): void
    {
        DB::statement('alter table companies drop constraint if exists companies_quote_prefix_check');
        DB::statement('alter table companies drop constraint if exists companies_quote_validity_check');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'quote_prefix', 'quote_validity_days', 'quote_terms', 'quote_footer']);
        });
    }
};
