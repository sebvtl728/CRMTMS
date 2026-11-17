<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signed attendance sheets, scanned (0.8.0): attached to one séance
     * (seance_id set) or to a day of a session (seance_id null), the file on
     * the private disk under tenants/<tenant_id>/attendance_sheets/. A proof
     * kept with the attendance, so never cascaded from a séance.
     */
    public function up(): void
    {
        Schema::create('attendance_sheet_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions');
            $table->foreignUuid('seance_id')->nullable()->constrained('seances');
            $table->date('date');
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['training_session_id', 'date']);
            $table->index('seance_id');
        });
        Rls::enable('attendance_sheet_scans');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sheet_scans');
    }
};
