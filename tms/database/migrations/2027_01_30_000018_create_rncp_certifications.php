<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RNCP certifications of France compétences' open data (Catalogue\Rncp):
 * public reference data shared by every organisme (no tenant, no personal
 * data), replaced whole by tms:rncp:sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rncp_certifications', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->text('title');
            $table->smallInteger('level')->nullable();
            $table->boolean('active');
            $table->date('end_on')->nullable();
            $table->jsonb('nsf_codes');
            $table->jsonb('blocks');
            $table->timestampTz('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rncp_certifications');
    }
};
