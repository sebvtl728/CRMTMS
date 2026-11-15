<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom roles: a label (null = the label of the system role), the « double
 * authentification obligatoire » switch and the system flag. The six roles
 * of every existing organisme become system roles; 2FA stays mandatory for
 * admin_organisme and direction (also enforced in RoleModel::requires2fa()).
 * Data written per tenant (FORCE RLS).
 */
return new class extends Migration
{
    private const SYSTEM = ['admin_organisme', 'direction', 'commercial', 'charge_relations', 'assistant', 'responsable_das'];

    private const MANDATORY_2FA = ['admin_organisme', 'direction'];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('name');
            $table->boolean('requires_2fa')->default(false)->after('guard_name');
            $table->boolean('system')->default(false)->after('requires_2fa');
        });

        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            DB::table('roles')->whereIn('name', self::SYSTEM)->update(['system' => true]);
            DB::table('roles')->whereIn('name', self::MANDATORY_2FA)->update(['requires_2fa' => true]);
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'requires_2fa', 'system']);
        });
    }
};
