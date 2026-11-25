<?php

namespace App\Modules\Sessions\Enrolments;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\TraineeFollowUp;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Suivi » of a trainee from the trainees step of a session: his planning,
 * attendance and signatures (TraineeFollowUp, same block as the page of an
 * admitted candidate). 404 for an enrolment of another session, a cancelled
 * or waitlisted one (both bindings already apply the view scope).
 */
final class EnrolmentFollowUpController extends Controller
{
    public function __invoke(Request $request, TrainingSession $trainingSession, Enrolment $enrolment): Response
    {
        abort_unless($enrolment->training_session_id === $trainingSession->id, 404);
        $followUp = TraineeFollowUp::forEnrolment($enrolment, $request->user());
        abort_if($followUp === null, 404);

        return Inertia::render('sessions/sessions/enrolment-follow-up', [
            'trainee' => [
                'id' => $enrolment->person_id,
                'label' => app(Fiches::class)->personLabels([(string) $enrolment->person_id])[$enrolment->person_id]['label'] ?? 'Stagiaire',
            ],
            'back' => '/sessions/'.$trainingSession->id.'?etape=stagiaires',
            'follow_up' => $followUp,
        ]);
    }
}
