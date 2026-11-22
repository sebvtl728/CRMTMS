<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bank details of a company, printed on its invoices (transfer): IBAN checked, BIC, bank name. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('bank_name', 100)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['bank_name', 'iban', 'bic']);
        });
    }
};
