<?php

namespace App\Modules\Core\Users;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * User provider of the "web" guard. On top of the tenant scope and RLS (only
 * the users of the request's tenant exist), it only ever returns accounts
 * that are active and activated: a deactivated user, or one who has not
 * chosen a password yet, cannot log in, be restored from a session or a
 * "remember me" cookie, or receive a password reset link.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)
            ->whereNull('deactivated_at')
            ->whereNotNull('password');
    }
}
