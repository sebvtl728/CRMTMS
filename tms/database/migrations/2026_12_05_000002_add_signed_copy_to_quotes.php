<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signed copy of an accepted quote, sent back by the customer: private
     * file tenants/<tenant_id>/quotes/<quote_id>/signe.<pdf|jpg|png>, its
     * original name (personal: masked in the journal) and its upload time.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('signed_path', 500)->nullable();
            $table->string('signed_name', 255)->nullable();
            $table->timestampTz('signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['signed_path', 'signed_name', 'signed_at']);
        });
    }
};
