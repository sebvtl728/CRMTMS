<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Hand grading of an online test on the candidature (right to change it). */
class OnlineReviewController extends Controller
{
    public function pdf(Candidature $candidature, string $attempt, OnlineAttemptPdf $pdf): Response
    {
        abort_unless(Str::isUuid($attempt), 404);
        $model = OnlineAttempt::query()->whereKey($attempt)->where('candidature_id', $candidature->id)->whereNotNull('submitted_at')->firstOrFail();

        return response($pdf->render($candidature, $model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="test-'.$candidature->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function grade(Request $request, Candidature $candidature, string $attempt, OnlineReview $review): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        abort_unless(Str::isUuid($attempt), 404);
        $scores = $request->input('scores');
        abort_unless(is_array($scores), 422);
        $graded = $review->grade($candidature, OnlineAttempt::query()->findOrFail($attempt), $scores, $user);

        return back()->with('status', $graded->score === null
            ? 'Correction enregistrée : il reste des réponses à noter.'
            : "Correction enregistrée : {$graded->score} / {$graded->max_score}.");
    }
}
