<?php

use App\Modules\Catalogue\BpfObjective;
use App\Modules\Catalogue\Nsf;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BPF fields of a training: its objective (cadre F-3) and its NSF group of
 * specialities (cadre F-4, 3 digits). Values checked in the database too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('bpf_objective', 20)->nullable();
            $table->char('nsf_code', 3)->nullable();
        });

        $objectives = implode(', ', array_map(fn (BpfObjective $o) => "'{$o->value}'", BpfObjective::cases()));
        $codes = implode(', ', array_map(fn (int $c) => "'{$c}'", array_keys(Nsf::CODES)));
        DB::statement("alter table trainings add constraint trainings_bpf_objective_check check (bpf_objective is null or bpf_objective in ({$objectives}))");
        DB::statement("alter table trainings add constraint trainings_nsf_code_check check (nsf_code is null or nsf_code in ({$codes}))");
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['bpf_objective', 'nsf_code']);
        });
    }
};
