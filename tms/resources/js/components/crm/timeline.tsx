import { ACTIVITY_SAVED_EVENT, completeActivity } from '@/components/crm/quick-action';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/crm';
import { Link, router } from '@inertiajs/react';
import { Check, ClipboardCheck, FileText, Handshake, History, LoaderCircle, Paperclip, StickyNote, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

const MAX_NOTE = 10000;
const MAX_FILE_BYTES = 20 * 1024 * 1024;
const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.odt,.ods,.txt,.csv';

export interface TimelineEntryData {
    type: string;
    at: string;
    title: string;
    body: string | null;
    author: string | null;
    meta: { id?: string; size?: number; mime?: string; file_deleted?: boolean } & Record<string, unknown>;
}

interface Props {
    kind: 'organisation' | 'person';
    subjectId: string;
    /** May add / remove notes and files (crm.update on a live fiche). */
    canEdit: boolean;
}

function formatSize(bytes: number | undefined): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} o`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;

    return `${(bytes / (1024 * 1024)).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`;
}

function EntryIcon({ type }: { type: string }) {
    const cls = 'text-muted-foreground mt-0.5 h-4 w-4 shrink-0';
    if (type === 'note') return <StickyNote className={cls} aria-hidden />;
    if (type === 'attachment') return <Paperclip className={cls} aria-hidden />;
    if (type === 'opportunity') return <Handshake className={cls} aria-hidden />;
    if (type === 'activity') return <ClipboardCheck className={cls} aria-hidden />;
    if (type === 'quote') return <FileText className={cls} aria-hidden />;

    return <History className={cls} aria-hidden />;
}

/** Status, type and result of an action, overdue in red. */
function ActivityBadges({ meta }: { meta: TimelineEntryData['meta'] }) {
    const status = typeof meta.status === 'string' ? meta.status : null;
    const label = typeof meta.status_label === 'string' ? meta.status_label : null;

    return (
        <span className="flex flex-wrap items-center gap-1">
            {typeof meta.type === 'string' && <Badge variant="outline">{meta.type}</Badge>}
            {label && <Badge variant={status === 'overdue' ? 'destructive' : status === 'done' ? 'secondary' : 'default'}>{label}</Badge>}
            {typeof meta.result === 'string' && <Badge variant="outline">{meta.result}</Badge>}
        </span>
    );
}

/** Route that removes an entry of the history (notes, files, actions). */
function removeRoute(entry: TimelineEntryData): string {
    const id = String(entry.meta.id);
    if (entry.type === 'note') return route('crm.notes.destroy', id);
    if (entry.type === 'activity') return route('crm.activities.destroy', id);

    return route('crm.attachments.destroy', id);
}

const REMOVABLE = ['note', 'attachment', 'activity'];

/**
 * « Historique » of a fiche: notes, attached files and changes, most recent
 * first, loaded 30 at a time. Notes and files are posted through Inertia (CSRF,
 * validation errors); the feed itself is a JSON endpoint.
 */
export function Timeline({ kind, subjectId, canEdit }: Props) {
    const base = kind === 'organisation' ? 'crm.organisations' : 'crm.people';
    const timelineUrl = route(`${base}.timeline`, subjectId);

    const [entries, setEntries] = useState<TimelineEntryData[]>([]);
    /** Opaque cursor from the server (position of the last entry shown), sent back as is. */
    const [next, setNext] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const [note, setNote] = useState('');
    const [noteError, setNoteError] = useState<string>();
    const [fileError, setFileError] = useState<string>();
    const [saving, setSaving] = useState(false);
    const [removing, setRemoving] = useState<TimelineEntryData | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const load = useCallback(
        async (before: string | null) => {
            // A failed « Voir plus » keeps the entries already shown; a first load replaces them.
            setLoading(true);
            setFailed(false);
            try {
                const response = await fetch(before ? `${timelineUrl}?before=${encodeURIComponent(before)}` : timelineUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error(String(response.status));
                const json = (await response.json()) as { entries: TimelineEntryData[]; next: string | null };
                setEntries((current) => (before ? [...current, ...json.entries] : json.entries));
                setNext(json.next);
            } catch {
                setFailed(true);
            } finally {
                setLoading(false);
            }
        },
        [timelineUrl],
    );

    useEffect(() => {
        void load(null);
    }, [load]);

    // An action saved from the « + Action » dialog shows up at once.
    useEffect(() => {
        const reload = () => void load(null);
        window.addEventListener(ACTIVITY_SAVED_EVENT, reload);

        return () => window.removeEventListener(ACTIVITY_SAVED_EVENT, reload);
    }, [load]);

    const options = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => setSaving(false),
    };

    const addNote = (e: React.FormEvent) => {
        e.preventDefault();
        if (!note.trim()) {
            setNoteError('Écrivez le texte de la note.');
            return;
        }
        setSaving(true);
        setNoteError(undefined);
        router.post(
            route(`${base}.notes.store`, subjectId),
            { body: note },
            {
                ...options,
                onSuccess: () => {
                    setNote('');
                    void load(null);
                },
                onError: (errors) => setNoteError(errors.body ?? 'La note n’a pas pu être enregistrée.'),
            },
        );
    };

    const upload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        setFileError(undefined);
        if (file.size > MAX_FILE_BYTES) {
            setFileError('Le fichier est trop volumineux (maximum : 20 Mo).');
            return;
        }
        setSaving(true);
        router.post(
            route(`${base}.attachments.store`, subjectId),
            { file },
            {
                ...options,
                forceFormData: true,
                onSuccess: () => void load(null),
                onError: (errors) => setFileError(errors.file ?? 'Le fichier n’a pas pu être ajouté.'),
            },
        );
    };

    const remove = () => {
        if (!removing?.meta.id) return;
        router.delete(removeRoute(removing), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setRemoving(null);
                void load(null);
            },
        });
    };

    return (
        <section role="tabpanel" id="panel-Historique" aria-labelledby="tab-Historique" className="grid gap-4">
            {canEdit && (
                <div className="grid gap-3 rounded-xl border p-4">
                    <form onSubmit={addNote} className="grid gap-2" noValidate>
                        <label htmlFor="timeline-note" className="text-sm font-semibold">
                            Ajouter une note
                        </label>
                        <textarea
                            id="timeline-note"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            maxLength={MAX_NOTE}
                            rows={3}
                            placeholder="Appel, rendez-vous, information utile…"
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-base focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden md:text-sm"
                        />
                        <InputError message={noteError} />
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Button type="submit" disabled={saving || !note.trim()}>
                                {saving && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Ajouter la note
                            </Button>
                            <span className="text-muted-foreground text-xs">
                                {note.length.toLocaleString('fr-FR')} / {MAX_NOTE.toLocaleString('fr-FR')}
                            </span>
                        </div>
                    </form>
                    <div className="grid gap-1 border-t pt-3">
                        <input ref={fileInput} type="file" accept={ACCEPT} onChange={upload} className="sr-only" id="timeline-file" tabIndex={-1} />
                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="button" variant="outline" disabled={saving} onClick={() => fileInput.current?.click()}>
                                <Paperclip className="h-4 w-4" />
                                Joindre un fichier
                            </Button>
                            <span className="text-muted-foreground text-xs">PDF, images, Word, Excel, OpenDocument, texte, CSV · 20 Mo maximum</span>
                        </div>
                        <InputError message={fileError} />
                    </div>
                </div>
            )}

            {failed && (
                <div
                    role="alert"
                    className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                >
                    L’historique n’a pas pu être chargé.{' '}
                    <button type="button" className="underline" onClick={() => void load(null)}>
                        Réessayer
                    </button>
                </div>
            )}

            {!failed && !loading && entries.length === 0 && (
                <p className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucun événement pour le moment.</p>
            )}

            {entries.length > 0 && (
                <ol className="grid gap-2" aria-label="Historique de la fiche">
                    {entries.map((entry, index) => (
                        <li key={`${entry.type}-${entry.meta.id ?? index}`} className="flex gap-3 rounded-lg border p-3">
                            <EntryIcon type={entry.type} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                    {entry.type === 'attachment' && entry.meta.id && !entry.meta.file_deleted ? (
                                        <a
                                            href={route('crm.attachments.download', entry.meta.id)}
                                            className="inline-flex items-center gap-1 text-sm font-medium break-all underline"
                                        >
                                            <FileText className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                            {entry.title}
                                            <span className="text-muted-foreground ml-1 font-normal no-underline">{formatSize(entry.meta.size)}</span>
                                        </a>
                                    ) : entry.type === 'opportunity' && typeof entry.meta.opportunity_id === 'string' ? (
                                        <Link
                                            href={route('crm.opportunities.show', entry.meta.opportunity_id)}
                                            className="text-sm font-medium hover:underline"
                                        >
                                            {entry.title}
                                        </Link>
                                    ) : entry.type === 'quote' && typeof entry.meta.quote_id === 'string' ? (
                                        <Link href={route('crm.quotes.show', entry.meta.quote_id)} className="text-sm font-medium hover:underline">
                                            {entry.title}
                                        </Link>
                                    ) : (
                                        <span className="text-sm font-medium">{entry.title}</span>
                                    )}
                                    {entry.type === 'activity' && <ActivityBadges meta={entry.meta} />}
                                    <span className="text-muted-foreground text-xs">
                                        {entry.type === 'activity' && entry.meta.status !== 'done' ? 'Échéance : ' : ''}
                                        {formatDateTime(entry.at)}
                                        {entry.author && ` · ${entry.author}`}
                                    </span>
                                </div>
                                {entry.body && <p className="mt-1 text-sm break-words whitespace-pre-line">{entry.body}</p>}
                                {entry.type === 'activity' && typeof entry.meta.opportunity_id === 'string' && (
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        Opportunité :{' '}
                                        <Link href={route('crm.opportunities.show', entry.meta.opportunity_id)} className="underline">
                                            {typeof entry.meta.opportunity === 'string' ? entry.meta.opportunity : 'voir'}
                                        </Link>
                                    </p>
                                )}
                            </div>
                            {canEdit && entry.type === 'activity' && entry.meta.status !== 'done' && entry.meta.id && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => completeActivity(String(entry.meta.id))}
                                >
                                    <Check className="h-4 w-4" />
                                    Fait
                                </Button>
                            )}
                            {canEdit && REMOVABLE.includes(entry.type) && entry.meta.id && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8 shrink-0"
                                    aria-label={
                                        entry.type === 'note'
                                            ? 'Supprimer cette note'
                                            : entry.type === 'activity'
                                              ? `Supprimer l’action ${entry.title}`
                                              : `Retirer ${entry.title}`
                                    }
                                    onClick={() => setRemoving(entry)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ol>
            )}

            {loading && (
                <p role="status" className="text-muted-foreground flex items-center justify-center gap-2 text-sm">
                    <LoaderCircle className="h-4 w-4 animate-spin" />
                    Chargement…
                </p>
            )}

            {next && !loading && (
                <div className="flex justify-center">
                    <Button type="button" variant="outline" onClick={() => void load(next)}>
                        Voir plus
                    </Button>
                </div>
            )}

            <Dialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {removing?.type === 'note'
                                ? 'Supprimer cette note ?'
                                : removing?.type === 'activity'
                                  ? 'Supprimer cette action ?'
                                  : 'Retirer ce fichier ?'}
                        </DialogTitle>
                        <DialogDescription>
                            {removing?.type === 'note'
                                ? 'La note disparaît de l’historique.'
                                : removing?.type === 'activity'
                                  ? `« ${removing?.title} » disparaît de l’historique ; la date du dernier contact est recalculée.`
                                  : `« ${removing?.title} » disparaît de l’historique et ne peut plus être téléchargé.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRemoving(null)}>
                            Annuler
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            {removing?.type === 'attachment' ? 'Retirer' : 'Supprimer'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
