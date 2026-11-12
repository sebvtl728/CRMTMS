<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            // Organisation or Person (no foreign key: polymorphic).
            $table->string('notable_type');
            $table->uuid('notable_id');
            $table->text('body');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Microseconds: the timeline pages on the creation instant.
            $table->timestamps(6);
            $table->softDeletes();

            $table->index(['notable_type', 'notable_id', 'created_at']);
        });

        Rls::enable('notes');
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
