<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;

/**
 * An enrolment changes with sessions.sessions.update on its session, in the
 * scope of that right (spec lot 2a § 9: « Une inscription se modifie avec le
 * droit Sessions et inscriptions »). An enrolment whose session is outside
 * the view scope is already a 404 at route binding.
 */
class EnrolmentPolicy
{
    public function update(User $user, Enrolment $enrolment): bool
    {
        $session = $enrolment->session;

        return $session instanceof TrainingSession && ! $session->trashed()
            && SessionsVisibility::allows($user, Permissions::SESSIONS_UPDATE, $session);
    }
}
