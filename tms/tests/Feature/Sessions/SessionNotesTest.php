<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Notes\SessionNote;
use App\Modules\Sessions\Notes\SessionNotes;
use App\Modules\Sessions\Notes\SessionTask;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->session = plSession();
    $this->manager = plManager();
});

function snTask(TrainingSession $session, array $attributes = []): SessionTask
{
    return SessionTask::query()->create(['training_session_id' => $session->id, 'title' => 'Réserver la salle', ...$attributes]);
}

function snNote(TrainingSession $session, User $author, string $body = 'Prévoir le matériel'): SessionNote
{
    return app(SessionNotes::class)->addNote($session, $author, $body);
}

it('creates, edits, toggles and deletes a task', function () {
    $other = userWithPermissions([Permissions::SESSIONS_VIEW]);
    $base = "/sessions/{$this->session->id}/tasks";

    $this->actingAs($this->manager)->post($base, ['title' => '  Réserver la salle ', 'due_on' => '2026-09-05', 'assignee_user_id' => $other->id])->assertRedirect();
    $task = SessionTask::query()->sole();
    expect($task->title)->toBe('Réserver la salle')->and($task->due_on->toDateString())->toBe('2026-09-05')
        ->and($task->assignee_user_id)->toBe($other->id)->and($task->created_by)->toBe($this->manager->id);

    $this->put("$base/{$task->id}", ['title' => 'Réserver la salle B'])->assertRedirect();
    expect($task->fresh()->title)->toBe('Réserver la salle B')->and($task->fresh()->due_on)->toBeNull()->and($task->fresh()->assignee_user_id)->toBeNull();

    $this->post("$base/{$task->id}/toggle", ['done' => true])->assertRedirect();
    expect($task->fresh()->done_at)->not->toBeNull()->and($task->fresh()->done_by)->toBe($this->manager->id);
    $this->post("$base/{$task->id}/toggle", ['done' => false])->assertRedirect();
    expect($task->fresh()->done_at)->toBeNull()->and($task->fresh()->done_by)->toBeNull();

    $this->delete("$base/{$task->id}")->assertRedirect();
    expect(SessionTask::query()->count())->toBe(0)->and(SessionTask::withTrashed()->count())->toBe(1);
});

it('validates a task in French', function () {
    $base = "/sessions/{$this->session->id}/tasks";
    $inactive = User::factory()->create(['deactivated_at' => now()]);
    $this->actingAs($this->manager);

    $this->post($base, ['title' => ''])->assertSessionHasErrors(['title' => 'Indiquez l\'intitulé de la tâche.']);
    $this->post($base, ['title' => str_repeat('a', 201)])->assertSessionHasErrors(['title' => 'L\'intitulé ne doit pas dépasser 200 caractères.']);
    $this->post($base, ['title' => 'x', 'assignee_user_id' => $inactive->id])->assertSessionHasErrors(['assignee_user_id' => 'Utilisateur introuvable.']);
    $this->post($base, ['title' => 'x', 'assignee_user_id' => (string) Str::uuid()])->assertSessionHasErrors(['assignee_user_id' => 'Utilisateur introuvable.']);
    $this->post($base, ['title' => 'x', 'assignee_user_id' => 'pas-un-uuid'])->assertSessionHasErrors(['assignee_user_id' => 'Utilisateur introuvable.']);
    $this->post($base, ['title' => 'x', 'due_on' => '31/02/2026'])->assertSessionHasErrors(['due_on' => 'Date invalide.']);
    expect(SessionTask::query()->count())->toBe(0);
});

it('refuses an assignee of another organisme', function () {
    $foreign = userIn(Tenant::factory()->create());
    $this->actingAs($this->manager)->post("/sessions/{$this->session->id}/tasks", ['title' => 'x', 'assignee_user_id' => $foreign->id])
        ->assertSessionHasErrors(['assignee_user_id' => 'Utilisateur introuvable.']);
});

it('creates, edits and deletes a note; only its author edits it', function () {
    $base = "/sessions/{$this->session->id}/notes";
    $this->actingAs($this->manager)->post($base, ['body' => ' Prévoir le matériel '])->assertRedirect();
    $note = SessionNote::query()->sole();
    expect($note->body)->toBe('Prévoir le matériel')->and($note->author_user_id)->toBe($this->manager->id);

    $this->put("$base/{$note->id}", ['body' => 'Matériel prévu'])->assertRedirect();
    expect($note->fresh()->body)->toBe('Matériel prévu');

    // Another user with the update right: no edit (403), but may delete.
    $colleague = plManager();
    $this->actingAs($colleague)->put("$base/{$note->id}", ['body' => 'Autre'])->assertForbidden();
    expect($note->fresh()->body)->toBe('Matériel prévu');
    $this->delete("$base/{$note->id}")->assertRedirect();
    expect(SessionNote::query()->count())->toBe(0);
});

it('validates a note in French', function () {
    $base = "/sessions/{$this->session->id}/notes";
    $this->actingAs($this->manager);
    $this->post($base, ['body' => ''])->assertSessionHasErrors(['body' => 'Indiquez le texte de la note.']);
    $this->post($base, ['body' => str_repeat('a', 5001)])->assertSessionHasErrors(['body' => 'La note ne doit pas dépasser 5 000 caractères.']);
    $this->post($base, ['body' => str_repeat('a', 5000)])->assertRedirect();
});

it('lets a viewer see the panel but not write', function () {
    $task = snTask($this->session);
    $note = snNote($this->session, $this->manager);
    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW]);

    $this->actingAs($viewer)->get("/sessions/{$this->session->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('notes_panel.can_write', false)
        ->where('notes_panel.tasks.0.title', 'Réserver la salle')
        ->where('notes_panel.notes.0.body', 'Prévoir le matériel')
        ->where('notes_panel.notes.0.can_delete', false)
        ->where('notes_panel.assignees', []));

    $base = "/sessions/{$this->session->id}";
    $this->post("$base/tasks", ['title' => 'x'])->assertForbidden();
    $this->put("$base/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
    $this->post("$base/tasks/{$task->id}/toggle", ['done' => true])->assertForbidden();
    $this->delete("$base/tasks/{$task->id}")->assertForbidden();
    $this->post("$base/notes", ['body' => 'x'])->assertForbidden();
    $this->delete("$base/notes/{$note->id}")->assertForbidden();
    expect(SessionTask::query()->count())->toBe(1)->and(SessionNote::query()->count())->toBe(1);
});

it('keeps a cancelled session read-only', function () {
    $task = snTask($this->session);
    $this->actingAs($this->manager);
    app(SessionWriter::class)->cancel($this->session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);

    $this->get("/sessions/{$this->session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('notes_panel.can_write', false)->has('notes_panel.tasks', 1));
    $this->post("/sessions/{$this->session->id}/tasks", ['title' => 'x'])->assertStatus(409);
    $this->post("/sessions/{$this->session->id}/tasks/{$task->id}/toggle", ['done' => true])->assertStatus(409);
    expect(SessionTask::query()->count())->toBe(1);
});

it('answers 404 for a task or note of another session or organisme', function () {
    $other = plSession('2026-10-01', '2026-10-30');
    $task = snTask($other);
    $note = snNote($other, $this->manager);
    $base = "/sessions/{$this->session->id}";
    $this->actingAs($this->manager);

    $this->put("$base/tasks/{$task->id}", ['title' => 'x'])->assertNotFound();
    $this->post("$base/tasks/{$task->id}/toggle", ['done' => true])->assertNotFound();
    $this->delete("$base/tasks/{$task->id}")->assertNotFound();
    $this->put("$base/notes/{$note->id}", ['body' => 'x'])->assertNotFound();
    $this->delete("$base/notes/{$note->id}")->assertNotFound();
    $this->put("$base/tasks/not-a-uuid", ['title' => 'x'])->assertNotFound();

    // Another organisme: invisible (RLS) whatever the id.
    $foreign = Tenant::factory()->create();
    app(TenantContext::class)->run($foreign, function () use ($task) {
        expect(SessionTask::query()->count())->toBe(0)->and(SessionTask::query()->find($task->id))->toBeNull();
    });
});

it('orders open tasks by due day (no day last), done ones after, and flags overdue', function () {
    $this->travelTo(now('Europe/Paris')->setDate(2026, 9, 10)->setTime(12, 0));
    snTask($this->session, ['title' => 'Sans échéance']);
    snTask($this->session, ['title' => 'Tard', 'due_on' => '2026-09-20']);
    snTask($this->session, ['title' => 'Aujourd\'hui', 'due_on' => '2026-09-10']);
    snTask($this->session, ['title' => 'En retard', 'due_on' => '2026-09-01']);
    snTask($this->session, ['title' => 'Faite', 'due_on' => '2026-09-02'])->forceFill(['done_at' => now()])->save();

    $panel = app(SessionNotes::class)->panel($this->session, $this->manager);
    expect(collect($panel['tasks'])->pluck('title')->all())->toBe(['En retard', 'Aujourd\'hui', 'Tard', 'Sans échéance', 'Faite'])
        ->and(collect($panel['tasks'])->pluck('overdue')->all())->toBe([true, false, false, false, false])
        ->and($panel['can_write'])->toBeTrue()
        ->and(collect($panel['assignees'])->pluck('value')->all())->toContain($this->manager->id);
});

it('lists only active users as assignees', function () {
    $inactive = User::factory()->create(['deactivated_at' => now()]);
    $ids = collect(app(SessionNotes::class)->panel($this->session, $this->manager)['assignees'])->pluck('value');
    expect($ids)->toContain($this->manager->id)->not->toContain($inactive->id);
});

it('never journals the text of a task or a note', function () {
    $this->actingAs($this->manager);
    $task = app(SessionNotes::class)->addTask($this->session, $this->manager, ['title' => 'Secret-tâche-42']);
    app(SessionNotes::class)->updateTask($task, $this->session, $this->manager, ['title' => 'Secret-tâche-43']);
    $note = snNote($this->session, $this->manager, 'Secret-note-42');
    app(SessionNotes::class)->updateNote($note, $this->session, $this->manager, 'Secret-note-43');

    expect(DB::table('audit_logs')->whereIn('auditable_type', ['session_task', 'session_note'])->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_logs')->where('new_values', 'like', '%Secret-%')->orWhere('old_values', 'like', '%Secret-%')->exists())->toBeFalse();
});
