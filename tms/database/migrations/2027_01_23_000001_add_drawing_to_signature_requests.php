<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 0.10.1: the signer draws a signature by hand. The frozen source of the
     * document (its exact HTML, Core\Documents\PdfSource) is fingerprinted at
     * the request, re-rendered at signing with the drawing in its box; the
     * drawing (PNG) is archived and fingerprinted. Null source = a document
     * issued before 0.10.1: the drawing appears on the proof page only.
     */
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('source_path')->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->string('drawing_path')->nullable();
            $table->char('drawing_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['source_path', 'source_sha256', 'drawing_path', 'drawing_sha256']);
        });
    }
};
