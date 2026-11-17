import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatDay, type Option } from '@/lib/crm';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

export interface TaskRow {
    id: string;
    title: string;
    due_on: string | null;
    /** Open task whose Paris due day has passed (server side). */
    overdue: boolean;
    assignee_user_id: string | null;
    assignee: string | null;
    done: boolean;
    done_at: string | null;
    done_by: string | null;
}

export interface NoteRow {
    id: string;
    body: string;
    author: string | null;
    created_at: string | null;
    can_edit: boolean;
    can_delete: boolean;
}

export interface NotesPanel {
    tasks: TaskRow[];
    notes: NoteRow[];
    can_write: boolean;
    assignees: Option[];
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

function TaskForm({ sessionId, assignees, task, onDone }: { sessionId: string; assignees: Option[]; task?: TaskRow; onDone: () => void }) {
    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: task?.title ?? '',
        due_on: task?.due_on ?? '',
        assignee_user_id: task?.assignee_user_id ?? '',
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        };
        if (task) put(route('sessions.tasks.update', [sessionId, task.id]), options);
        else post(route('sessions.tasks.store', sessionId), options);
    };

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-lg border p-3" aria-label={task ? 'Modifier la tâche' : 'Ajouter une tâche'}>
            <div className="grid gap-1">
                <Label htmlFor={`task-title-${task?.id ?? 'new'}`}>Intitulé</Label>
                <Input id={`task-title-${task?.id ?? 'new'}`} value={data.title} maxLength={200} onChange={(e) => setData('title', e.target.value)} />
                <InputError message={errors.title} />
            </div>
            <div className="grid grid-cols-2 gap-2">
                <div className="grid gap-1">
                    <Label htmlFor={`task-due-${task?.id ?? 'new'}`}>Échéance</Label>
                    <Input id={`task-due-${task?.id ?? 'new'}`} type="date" value={data.due_on} onChange={(e) => setData('due_on', e.target.value)} />
                    <InputError message={errors.due_on} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`task-assignee-${task?.id ?? 'new'}`}>Assignée à</Label>
                    <select
                        id={`task-assignee-${task?.id ?? 'new'}`}
                        className={selectClass}
                        value={data.assignee_user_id}
                        onChange={(e) => setData('assignee_user_id', e.target.value)}
                    >
                        <option value="">Personne</option>
                        {assignees.map((u) => (
                            <option key={u.value} value={u.value}>
                                {u.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.assignee_user_id} />
                </div>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    Annuler
                </Button>
                <Button type="submit" size="sm" disabled={processing}>
                    Enregistrer
                </Button>
            </div>
        </form>
    );
}

function TaskItem({ sessionId, task, canWrite, assignees }: { sessionId: string; task: TaskRow; canWrite: boolean; assignees: Option[] }) {
    const [editing, setEditing] = useState(false);
    if (editing) return <TaskForm sessionId={sessionId} assignees={assignees} task={task} onDone={() => setEditing(false)} />;

    const toggle = (done: boolean) => router.post(route('sessions.tasks.toggle', [sessionId, task.id]), { done }, { preserveScroll: true });
    const remove = () => {
        if (window.confirm('Supprimer cette tâche ?')) router.delete(route('sessions.tasks.destroy', [sessionId, task.id]), { preserveScroll: true });
    };

    return (
        <li className="flex items-start gap-2 text-sm">
            <Checkbox
                id={`task-${task.id}`}
                className="mt-0.5"
                checked={task.done}
                disabled={!canWrite}
                onCheckedChange={(v) => toggle(v === true)}
                aria-label={`Tâche faite : ${task.title}`}
            />
            <div className="min-w-0 flex-1">
                <label htmlFor={`task-${task.id}`} className={task.done ? 'text-muted-foreground line-through' : ''}>
                    {task.title}
                </label>
                <p className="text-muted-foreground text-xs">
                    {task.due_on && (
                        <span className={task.overdue ? 'text-destructive font-medium' : ''}>
                            {task.overdue ? 'En retard : ' : 'Pour le '}
                            {formatDay(task.due_on)}
                        </span>
                    )}
                    {task.due_on && task.assignee && ' · '}
                    {task.assignee}
                    {task.done && ` · Faite${task.done_by ? ` par ${task.done_by}` : ''} le ${formatDateTime(task.done_at)}`}
                </p>
            </div>
            {canWrite && (
                <div className="flex shrink-0 gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        onClick={() => setEditing(true)}
                        aria-label={`Modifier la tâche : ${task.title}`}
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        onClick={remove}
                        aria-label={`Supprimer la tâche : ${task.title}`}
                    >
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}
        </li>
    );
}

function NoteForm({ sessionId, note, onDone }: { sessionId: string; note?: NoteRow; onDone: () => void }) {
    const { data, setData, post, put, processing, errors, reset } = useForm({ body: note?.body ?? '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        };
        if (note) put(route('sessions.notes.update', [sessionId, note.id]), options);
        else post(route('sessions.notes.store', sessionId), options);
    };
    const id = `note-body-${note?.id ?? 'new'}`;

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-lg border p-3" aria-label={note ? 'Modifier la note' : 'Ajouter une note'}>
            <Label htmlFor={id}>Note</Label>
            <textarea
                id={id}
                rows={4}
                maxLength={5000}
                value={data.body}
                onChange={(e) => setData('body', e.target.value)}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            />
            <InputError message={errors.body} />
            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onDone}>
                    Annuler
                </Button>
                <Button type="submit" size="sm" disabled={processing}>
                    Enregistrer
                </Button>
            </div>
        </form>
    );
}

function NoteItem({ sessionId, note }: { sessionId: string; note: NoteRow }) {
    const [editing, setEditing] = useState(false);
    if (editing) return <NoteForm sessionId={sessionId} note={note} onDone={() => setEditing(false)} />;

    const remove = () => {
        if (window.confirm('Supprimer cette note ?')) router.delete(route('sessions.notes.destroy', [sessionId, note.id]), { preserveScroll: true });
    };

    return (
        <li className="grid gap-1 rounded-lg border p-3 text-sm">
            <p className="whitespace-pre-wrap">{note.body}</p>
            <div className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
                <span>
                    {note.author ?? '—'} · {formatDateTime(note.created_at)}
                </span>
                <span className="flex gap-1">
                    {note.can_edit && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() => setEditing(true)}
                            aria-label="Modifier la note"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                    )}
                    {note.can_delete && (
                        <Button type="button" variant="ghost" size="icon" className="h-7 w-7" onClick={remove} aria-label="Supprimer la note">
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    )}
                </span>
            </div>
        </li>
    );
}

/**
 * « Tâches et notes » panel of the Synthèse (spec § 4.5): open tasks by due
 * day, done ones folded; notes with author and date. Read-only without the
 * right to change the session (or once cancelled).
 */
export function SessionNotesPanel({ sessionId, panel }: { sessionId: string; panel: NotesPanel }) {
    const [addingTask, setAddingTask] = useState(false);
    const [addingNote, setAddingNote] = useState(false);
    const open = panel.tasks.filter((t) => !t.done);
    const done = panel.tasks.filter((t) => t.done);

    return (
        <div className="grid gap-6">
            <section aria-labelledby="session-tasks" className="grid gap-2 rounded-xl border p-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 id="session-tasks" className="text-sm font-medium">
                        Tâches
                    </h3>
                    {panel.can_write && !addingTask && (
                        <Button type="button" variant="outline" size="sm" onClick={() => setAddingTask(true)}>
                            Ajouter une tâche
                        </Button>
                    )}
                </div>
                {addingTask && <TaskForm sessionId={sessionId} assignees={panel.assignees} onDone={() => setAddingTask(false)} />}
                {panel.tasks.length === 0 && !addingTask && <p className="text-muted-foreground text-sm">Aucune tâche.</p>}
                {open.length > 0 && (
                    <ul className="grid gap-2">
                        {open.map((t) => (
                            <TaskItem key={t.id} sessionId={sessionId} task={t} canWrite={panel.can_write} assignees={panel.assignees} />
                        ))}
                    </ul>
                )}
                {done.length > 0 && (
                    <details className="text-sm">
                        <summary className="text-muted-foreground cursor-pointer">Tâches faites ({done.length})</summary>
                        <ul className="mt-2 grid gap-2">
                            {done.map((t) => (
                                <TaskItem key={t.id} sessionId={sessionId} task={t} canWrite={panel.can_write} assignees={panel.assignees} />
                            ))}
                        </ul>
                    </details>
                )}
            </section>

            <section aria-labelledby="session-notes" className="grid gap-2 rounded-xl border p-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 id="session-notes" className="text-sm font-medium">
                        Notes
                    </h3>
                    {panel.can_write && !addingNote && (
                        <Button type="button" variant="outline" size="sm" onClick={() => setAddingNote(true)}>
                            Ajouter une note
                        </Button>
                    )}
                </div>
                {addingNote && <NoteForm sessionId={sessionId} onDone={() => setAddingNote(false)} />}
                {panel.notes.length === 0 && !addingNote && <p className="text-muted-foreground text-sm">Aucune note.</p>}
                {panel.notes.length > 0 && (
                    <ul className="grid gap-2">
                        {panel.notes.map((n) => (
                            <NoteItem key={n.id} sessionId={sessionId} note={n} />
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
