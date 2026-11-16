import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/crm';
import { CircleDollarSign, FolderOpen, History, UserRound } from 'lucide-react';
import { useState } from 'react';

/** One line of the « Historique » of a dossier (SessionsHistory::dossierPage, read from the journal). */
export interface HistoryEntry {
    type: 'dossier' | 'enrolment' | 'funding' | string;
    at: string;
    title: string;
    body: string | null;
    author: string | null;
}

const SHOWN = 15;

function EntryIcon({ type }: { type: string }) {
    const cls = 'text-muted-foreground mt-0.5 h-4 w-4 shrink-0';
    if (type === 'dossier') return <FolderOpen className={cls} aria-hidden />;
    if (type === 'enrolment') return <UserRound className={cls} aria-hidden />;
    if (type === 'funding') return <CircleDollarSign className={cls} aria-hidden />;

    return <History className={cls} aria-hidden />;
}

/**
 * « Historique » of a dossier: creation and states, enrolments, fundings,
 * most recent first; the 15 latest, then all on demand.
 */
export function HistoryList({ entries }: { entries: HistoryEntry[] }) {
    const [all, setAll] = useState(false);
    const shown = all ? entries : entries.slice(0, SHOWN);

    return (
        <section aria-label="Historique" className="grid gap-3">
            <h2 className="text-sm font-semibold">Historique</h2>
            {entries.length === 0 ? (
                <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucun évènement enregistré.</div>
            ) : (
                <ol className="grid gap-2">
                    {shown.map((entry, index) => (
                        <li key={`${entry.at}-${index}`} className="flex gap-3 rounded-lg border p-3">
                            <EntryIcon type={entry.type} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span className="text-sm font-medium break-words">{entry.title}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {formatDateTime(entry.at)}
                                        {entry.author && ` · ${entry.author}`}
                                    </span>
                                </div>
                                {entry.body && <p className="mt-1 text-sm break-words">{entry.body}</p>}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
            {!all && entries.length > SHOWN && (
                <div>
                    <Button type="button" variant="outline" size="sm" onClick={() => setAll(true)}>
                        Voir tout l'historique ({entries.length})
                    </Button>
                </div>
            )}
        </section>
    );
}
