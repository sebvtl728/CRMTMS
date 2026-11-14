<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-mail templates changed by an organisme (« Modèles d'e-mails »); no
     * row for a key = the default of the code.
     */
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Rls::enable('mail_templates');
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
