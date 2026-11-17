<?php

namespace App\Modules\Sessions\Sessions;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The kanban's move between « Planifiée » and « Confirmée » (JSON): a
 * session out of the view scope is a 404 at route binding, without the
 * right to update it a 403, a session no longer planned nor confirmed a 409
 * (SessionWriter::setStatus).
 */
class SessionStatusController extends Controller
{
    public function __invoke(Request $request, TrainingSession $trainingSession, SessionWriter $writer): JsonResponse
    {
        Gate::authorize('update', $trainingSession);
        $validated = $request->validate(
            ['status' => ['required', 'string', Rule::in(array_map(fn (SessionStatus $s) => $s->value, SessionStatus::SETTABLE))]],
            ['status.required' => 'Choisissez un état.', 'status.in' => 'Une session ne passe ainsi qu\'en « Planifiée » ou « Confirmée ».'],
        );

        $session = $writer->setStatus($trainingSession, SessionStatus::from($validated['status']));
        $status = $session->effectiveStatus(TrainingSession::today());

        return response()->json(['status' => $status->value, 'status_label' => $status->label()]);
    }
}
