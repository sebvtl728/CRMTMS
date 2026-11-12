<?php

use App\Modules\Core\Audit\MorphDataMigration;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\DB;

it('keeps the permissions of a user whose role row still has the legacy class name', function () {
    $user = admin(twoFactor: true);
    expect($user->can('users.manage'))->toBeTrue();

    DB::table('model_has_roles')->where('model_id', $user->id)->update(['model_type' => 'App\Modules\Core\Users\User']);
    $legacy = User::query()->findOrFail($user->id);
    expect($legacy->can('users.manage'))->toBeFalse();

    MorphDataMigration::run(DB::connection(), skip: ['audit_logs']);

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->value('model_type'))->toBe('user')
        ->and(User::query()->findOrFail($user->id)->can('users.manage'))->toBeTrue()
        ->and(DB::selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBe(tenant()->id);
});
