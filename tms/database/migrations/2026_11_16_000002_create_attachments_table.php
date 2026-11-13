<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            // Organisation or Person (no foreign key: polymorphic).
            $table->string('notable_type');
            $table->uuid('notable_id');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            // Private disk, tenants/<tenant_id>/attachments/<uuid>.
            $table->string('path');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);
            $table->softDeletesTz();

            $table->index(['notable_type', 'notable_id', 'created_at']);
        });

        Rls::enable('attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
