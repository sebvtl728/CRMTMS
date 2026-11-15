import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Copy, ExternalLink, Merge } from 'lucide-react';

export interface DuplicateCandidate {
    id: string;
    label: string;
    reason: string;
    reason_label: string;
    score: number;
    /** « Fusionner » allowed (scopes of crm.fiches.update / delete). */
    can_merge?: boolean;
}

export type FicheType = 'organisation' | 'person';

export function showRoute(type: FicheType, id: string): string {
    return type === 'organisation' ? route('crm.organisations.show', id) : route('crm.people.show', id);
}

export function mergeRoute(type: FicheType, keep: string, drop: string): string {
    return route('crm.merge.show', { type, keep, drop });
}

interface Props {
    type: FicheType;
    duplicates: DuplicateCandidate[];
    canMerge: boolean;
    /** Fiche being edited; null on a new fiche. */
    currentId: string | null;
    processing: boolean;
    /** Save anyway (confirm_duplicate=1). */
    onConfirm: () => void;
    /** New fiche only: create it, then open the merge screen with this candidate kept. */
    onCreateAndMerge: (candidateId: string) => void;
}

/**
 * « Fiches similaires » shown when a save is stopped by the duplicate check.
 */
export function DuplicateWarning({ type, duplicates, canMerge, currentId, processing, onConfirm, onCreateAndMerge }: Props) {
    if (duplicates.length === 0) return null;

    return (
        <section
            role="alert"
            aria-labelledby="duplicates-title"
            className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40"
        >
            <h2 id="duplicates-title" className="flex items-center gap-2 font-semibold">
                <Copy className="h-4 w-4" aria-hidden />
                Fiches similaires
            </h2>
            <p className="text-muted-foreground mt-1 text-sm">
                {duplicates.length === 1 ? 'Une fiche ressemble' : `${duplicates.length} fiches ressemblent`} à celle que vous enregistrez. Vérifiez
                avant de créer un doublon.
            </p>

            <ul className="mt-3 grid gap-2">
                {duplicates.map((d) => (
                    <li key={d.id} className="bg-background flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <div className="truncate font-medium">{d.label}</div>
                            <div className="text-muted-foreground text-xs">{d.reason_label}</div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" size="sm" asChild>
                                <a href={showRoute(type, d.id)} target="_blank" rel="noopener">
                                    <ExternalLink className="h-4 w-4" aria-hidden />
                                    Ouvrir
                                </a>
                            </Button>
                            {canMerge &&
                                d.can_merge !== false &&
                                (currentId === null ? (
                                    <Button type="button" variant="outline" size="sm" disabled={processing} onClick={() => onCreateAndMerge(d.id)}>
                                        <Merge className="h-4 w-4" aria-hidden />
                                        Créer puis fusionner
                                    </Button>
                                ) : (
                                    <Button type="button" variant="outline" size="sm" asChild>
                                        <Link href={mergeRoute(type, d.id, currentId)}>
                                            <Merge className="h-4 w-4" aria-hidden />
                                            Fusionner
                                        </Link>
                                    </Button>
                                ))}
                        </div>
                    </li>
                ))}
            </ul>

            <div className="mt-3">
                <Button type="button" variant="secondary" disabled={processing} onClick={onConfirm}>
                    {currentId === null ? 'Créer quand même' : 'Enregistrer quand même'}
                </Button>
            </div>
        </section>
    );
}

/** « Fiches similaires » panel of an existing fiche: open or merge each one. */
export function SimilarFiches({ type, currentId, similar }: { type: FicheType; currentId: string; similar: DuplicateCandidate[] }) {
    if (similar.length === 0) return null;

    return (
        <section
            aria-labelledby="similar-title"
            className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40"
        >
            <h2 id="similar-title" className="flex items-center gap-2 font-semibold">
                <Copy className="h-4 w-4" aria-hidden />
                Fiches similaires
            </h2>
            <ul className="mt-2 grid gap-2">
                {similar.map((d) => (
                    <li key={d.id} className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0">
                            <Link href={showRoute(type, d.id)} className="truncate font-medium underline">
                                {d.label}
                            </Link>
                            <div className="text-muted-foreground text-xs">{d.reason_label}</div>
                        </div>
                        {d.can_merge !== false && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={mergeRoute(type, currentId, d.id)}>
                                    <Merge className="h-4 w-4" aria-hidden />
                                    Fusionner
                                </Link>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
