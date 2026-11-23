<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A session held in the premises of one of our companies (« Dans nos locaux »). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->foreignUuid('location_company_id')->nullable()->after('location_text')->constrained('companies');
        });
        DB::statement('alter table training_sessions drop constraint training_sessions_location_check');
        DB::statement("alter table training_sessions add constraint training_sessions_location_check check (
            location_kind in ('address', 'client', 'remote', 'company')
            and ((location_kind = 'company') = (location_company_id is not null)))");
    }

    public function down(): void
    {
        DB::statement('alter table training_sessions drop constraint training_sessions_location_check');
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('location_company_id');
        });
        DB::statement("alter table training_sessions add constraint training_sessions_location_check check (location_kind in ('address', 'client', 'remote'))");
    }
};
