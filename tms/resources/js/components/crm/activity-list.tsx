import { ACTIVITY_SAVED_EVENT, completeActivity, editActivity } from '@/components/crm/quick-action';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/crm';
import { router } from '@inertiajs/react';
import { Check, Pencil } from 'lucide-react';
import { useEffect } from 'react';

export interface ActivityRow {
    id: string;
    type: string | null;
    subject: string;
    purpose: string | null;
    report: string | null;
    next_steps: string | null;
    location: string | null;
    due_at: string | null;
    done_at: string | null;
    result: string | null;
    responsible: string | null;
    status: 'todo' | 'overdue' | 'done';
    status_label: string;
    /** The viewer's rights on this action (scope of crm.activities.update / delete). */
    can_update: boolean;
    can_delete: boolean;
}

/**
 * Actions of an opportunity, most recent first; « Fait » on those to do,
 * « Modifier » on those the viewer may update.
 * Reloads the `activities` prop once an action was saved.
 */
export function ActivityList({ activities }: { activities: ActivityRow[] }) {
    useEffect(() => {
        const reload = () => router.reload({ only: ['activities'] });
        window.addEventListener(ACTIVITY_SAVED_EVENT, reload);

        return () => window.removeEventListener(ACTIVITY_SAVED_EVENT, reload);
    }, []);

    if (activities.length === 0) {
        return <p className="text-muted-foreground text-sm">Aucune action pour le moment.</p>;
    }

    return (
        <ol className="grid gap-2" aria-label="Actions">
            {activities.map((a) => (
                <li key={a.id} className="flex flex-wrap items-start gap-3 rounded-lg border p-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium">{a.subject}</span>
                            {a.type && <Badge variant="outline">{a.type}</Badge>}
                            <Badge variant={a.status === 'overdue' ? 'destructive' : a.status === 'done' ? 'secondary' : 'default'}>
                                {a.status_label}
                            </Badge>
                            {a.result && <Badge variant="outline">{a.result}</Badge>}
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {a.done_at ? `Faite le ${formatDateTime(a.done_at)}` : `Échéance : ${formatDateTime(a.due_at)}`}
                            {a.responsible && ` · ${a.responsible}`}
                            {a.location && ` · ${a.location}`}
                        </p>
                        {a.purpose && (
                            <p className="mt-1 text-sm break-words whitespace-pre-line">
                                <span className="text-muted-foreground">Motif : </span>
                                {a.purpose}
                            </p>
                        )}
                        {a.report && <p className="mt-1 text-sm break-words whitespace-pre-line">{a.report}</p>}
                        {a.next_steps && (
                            <p className="mt-1 text-sm break-words whitespace-pre-line">
                                <span className="text-muted-foreground">Suite à donner : </span>
                                {a.next_steps}
                            </p>
                        )}
                    </div>
                    {a.can_update && (
                        <div className="flex flex-wrap gap-2">
                            {a.status !== 'done' && (
                                <Button type="button" variant="outline" size="sm" onClick={() => completeActivity(a.id)}>
                                    <Check className="h-4 w-4" />
                                    Fait
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => editActivity(a.id)}
                                aria-label={`Modifier l’action ${a.subject}`}
                            >
                                <Pencil className="h-4 w-4" />
                                Modifier
                            </Button>
                        </div>
                    )}
                </li>
            ))}
        </ol>
    );
}
