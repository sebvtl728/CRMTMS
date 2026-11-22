<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Qualiopi registers: complaints (indicator 31) and improvement actions
 * (indicator 32), with their reference lists (complaint_channel,
 * improvement_source) seeded for the organismes that already exist, per
 * organisme (FORCE RLS), literal values (as of 0.15).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const VALUES = [
        'complaint_channel' => ['E-mail', 'Téléphone', 'Courrier', 'Questionnaire', 'Oral', 'Autre'],
        'improvement_source' => ['Réclamation', 'Questionnaire', 'Audit', 'Interne', 'Veille'],
    ];

    public function up(): void
    {
        Schema::create('quality_complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->date('received_on');
            $table->foreignUuid('channel_id')->constrained('reference_items');
            $table->string('complainant', 200);
            $table->string('subject', 200);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('response')->nullable();
            $table->date('responded_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'received_on']);
        });
        DB::statement("alter table quality_complaints add constraint quality_complaints_status_check check (status in ('open', 'processing', 'closed'))");
        DB::statement("alter table quality_complaints add constraint quality_complaints_closed_check check (status <> 'closed' or (response is not null and responded_on is not null))");
        Rls::enable('quality_complaints');

        Schema::create('quality_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignUuid('source_id')->constrained('reference_items');
            $table->foreignUuid('complaint_id')->nullable()->constrained('quality_complaints')->nullOnDelete();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('planned');
            $table->date('done_on')->nullable();
            $table->text('result')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("alter table quality_actions add constraint quality_actions_status_check check (status in ('planned', 'in_progress', 'done', 'abandoned'))");
        DB::statement("alter table quality_actions add constraint quality_actions_done_check check (status <> 'done' or (done_on is not null and result is not null))");
        Rls::enable('quality_actions');

        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            foreach (self::VALUES as $list => $labels) {
                foreach ($labels as $i => $label) {
                    $code = Str::slug($label);
                    if (DB::table('reference_items')->where('list', $list)->where('code', $code)->exists()) {
                        continue;
                    }
                    DB::table('reference_items')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'list' => $list, 'code' => $code, 'label' => $label,
                        'position' => $i + 1, 'is_system' => false, 'is_active' => true, 'meta' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_actions');
        Schema::dropIfExists('quality_complaints');
    }
};
