<?php

namespace App\Modules\Questionnaires\Answering;

use App\Http\Controllers\Controller;

/** The public pages of a questionnaire (no account). */
class SurveyAnswerController extends Controller
{
    public function show(string $token): never
    {
        abort(404);
    }
}
