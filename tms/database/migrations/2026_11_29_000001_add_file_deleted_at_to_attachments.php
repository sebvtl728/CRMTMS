<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention: the file of an attachment whose fiche has been in the trash for
 * more than 12 months is deleted from the disk (AttachmentPurge); the row
 * stays, shown as « Fichier supprimé ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->timestampTz('file_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('file_deleted_at');
        });
    }
};
