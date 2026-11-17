<?php

namespace App\Modules\Sessions\Notes;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tasks and notes of a session (spec § 4.5): writes need the update right on
 * the session (SessionNotes); a task or note of another session is a 404.
 */
class SessionNoteController extends Controller
{
    public function __construct(private readonly SessionNotes $notes) {}

    public function storeTask(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        $this->notes->addTask($trainingSession, $this->user($request), $request->only(['title', 'due_on', 'assignee_user_id']));

        return back();
    }

    public function updateTask(Request $request, TrainingSession $trainingSession, SessionTask $sessionTask): RedirectResponse
    {
        abort_unless($sessionTask->training_session_id === $trainingSession->id, 404);
        $this->notes->updateTask($sessionTask, $trainingSession, $this->user($request), $request->only(['title', 'due_on', 'assignee_user_id']));

        return back();
    }

    public function toggleTask(Request $request, TrainingSession $trainingSession, SessionTask $sessionTask): RedirectResponse
    {
        abort_unless($sessionTask->training_session_id === $trainingSession->id, 404);
        $this->notes->toggleTask($sessionTask, $trainingSession, $this->user($request), $request->boolean('done'));

        return back();
    }

    public function destroyTask(Request $request, TrainingSession $trainingSession, SessionTask $sessionTask): RedirectResponse
    {
        abort_unless($sessionTask->training_session_id === $trainingSession->id, 404);
        $this->notes->deleteTask($sessionTask, $trainingSession, $this->user($request));

        return back();
    }

    public function storeNote(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        $this->notes->addNote($trainingSession, $this->user($request), self::body($request));

        return back();
    }

    public function updateNote(Request $request, TrainingSession $trainingSession, SessionNote $sessionNote): RedirectResponse
    {
        abort_unless($sessionNote->training_session_id === $trainingSession->id, 404);
        $this->notes->updateNote($sessionNote, $trainingSession, $this->user($request), self::body($request));

        return back();
    }

    public function destroyNote(Request $request, TrainingSession $trainingSession, SessionNote $sessionNote): RedirectResponse
    {
        abort_unless($sessionNote->training_session_id === $trainingSession->id, 404);
        $this->notes->deleteNote($sessionNote, $trainingSession, $this->user($request));

        return back();
    }

    private static function body(Request $request): string
    {
        $body = $request->input('body');

        return is_string($body) ? $body : '';
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
