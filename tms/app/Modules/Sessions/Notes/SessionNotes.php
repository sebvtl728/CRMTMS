<?php

namespace App\Modules\Sessions\Notes;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tasks and notes of a session (spec § 4.5). Every write needs the right to
 * change the session (SessionPolicy::update) on a session that is not
 * cancelled; a note is changed by its author only, deleted by its author or
 * by whoever may change the session.
 */
final class SessionNotes
{
    public const CANCELLED = 'Cette session est annulée : ses tâches et notes ne changent plus.';

    /**
     * @param  array<string, mixed>  $data  title, due_on?, assignee_user_id?
     */
    public function addTask(TrainingSession $session, User $user, array $data): SessionTask
    {
        $this->authorize($session, $user);
        $values = $this->validatedTask($data);

        $task = (new SessionTask)->fill([...$values, 'training_session_id' => $session->id]);
        $task->created_by = $user->id;
        $task->save();

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTask(SessionTask $task, TrainingSession $session, User $user, array $data): SessionTask
    {
        $this->authorize($session, $user);
        $task->update($this->validatedTask($data));

        return $task;
    }

    public function toggleTask(SessionTask $task, TrainingSession $session, User $user, bool $done): SessionTask
    {
        $this->authorize($session, $user);
        $task->forceFill($done ? ['done_at' => now(), 'done_by' => $user->id] : ['done_at' => null, 'done_by' => null])->save();

        return $task;
    }

    public function deleteTask(SessionTask $task, TrainingSession $session, User $user): void
    {
        $this->authorize($session, $user);
        $task->delete();
    }

    public function addNote(TrainingSession $session, User $user, string $body): SessionNote
    {
        $this->authorize($session, $user);

        $note = (new SessionNote)->fill(['training_session_id' => $session->id, 'body' => $this->validatedBody($body)]);
        $note->author_user_id = $user->id;
        $note->save();

        return $note;
    }

    public function updateNote(SessionNote $note, TrainingSession $session, User $user, string $body): SessionNote
    {
        $this->authorize($session, $user);
        abort_unless($note->author_user_id === $user->id, 403);
        $note->update(['body' => $this->validatedBody($body)]);

        return $note;
    }

    public function deleteNote(SessionNote $note, TrainingSession $session, User $user): void
    {
        $this->authorize($session, $user);
        $note->delete();
    }

    /**
     * @return array{tasks: list<array<string, mixed>>, notes: list<array<string, mixed>>, can_write: bool}
     */
    public function panel(TrainingSession $session, User $user): array
    {
        $today = TrainingSession::today()->toDateString();
        $tasks = SessionTask::query()->where('training_session_id', $session->id)
            ->orderByRaw('done_at is not null')->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('created_at')->orderBy('id')->get();
        $notes = SessionNote::query()->where('training_session_id', $session->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $ids = $tasks->pluck('assignee_user_id')->merge($tasks->pluck('done_by'))->merge($notes->pluck('author_user_id'))->filter()->unique()->all();
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $writable = $this->canWrite($session, $user);

        return [
            'tasks' => $tasks->map(fn (SessionTask $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'due_on' => $t->due_on?->toDateString(),
                'overdue' => $t->done_at === null && $t->due_on !== null && $t->due_on->toDateString() < $today,
                'assignee_user_id' => $t->assignee_user_id,
                'assignee' => $t->assignee_user_id === null ? null : $names[$t->assignee_user_id] ?? null,
                'done' => $t->done_at !== null,
                'done_at' => $t->done_at?->toIso8601String(),
                'done_by' => $t->done_by === null ? null : $names[$t->done_by] ?? null,
            ])->values()->all(),
            'notes' => $notes->map(fn (SessionNote $n): array => [
                'id' => $n->id,
                'body' => $n->body,
                'author' => $n->author_user_id === null ? null : $names[$n->author_user_id] ?? null,
                'created_at' => $n->created_at?->toIso8601String(),
                'can_edit' => $writable && $n->author_user_id === $user->id,
                'can_delete' => $writable,
            ])->values()->all(),
            'can_write' => $writable,
            'assignees' => $writable ? User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u): array => ['value' => $u->id, 'label' => $u->name])->all() : [],
        ];
    }

    public function canWrite(TrainingSession $session, User $user): bool
    {
        return ! $session->isCancelled() && $user->can('update', $session);
    }

    private function authorize(TrainingSession $session, User $user): void
    {
        abort_unless($user->can('update', $session), 403);
        if ($session->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, due_on: ?string, assignee_user_id: ?string}
     */
    private function validatedTask(array $data): array
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:200'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'assignee_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ], [
            'title.required' => 'Indiquez l\'intitulé de la tâche.',
            'title.string' => 'Indiquez l\'intitulé de la tâche.',
            'title.max' => 'L\'intitulé ne doit pas dépasser 200 caractères.',
            'due_on.date_format' => 'Date invalide.',
            'assignee_user_id.uuid' => 'Utilisateur introuvable.',
            'assignee_user_id.exists' => 'Utilisateur introuvable.',
        ])->validate();

        return [
            'title' => trim((string) $validated['title']),
            'due_on' => $validated['due_on'] ?? null,
            'assignee_user_id' => $validated['assignee_user_id'] ?? null,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function validatedBody(string $body): string
    {
        $validated = Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:5000']], [
            'body.required' => 'Indiquez le texte de la note.',
            'body.max' => 'La note ne doit pas dépasser 5 000 caractères.',
        ])->validate();

        return trim((string) $validated['body']);
    }
}
