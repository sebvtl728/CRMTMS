import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Check, Monitor } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

export const STEPS = ['Fichier', 'Colonnes', 'Valeurs', 'Simulation', 'Import', 'Rapport'] as const;

export interface BatchStatus {
    value: string;
    label: string;
}

export interface BatchSummary {
    id: string;
    original_name: string;
    target: { value: string; label: string };
    author: string | null;
    status: BatchStatus;
    total: number;
    created: number;
    updated: number;
    skipped: number;
    errors: number;
    started_at: string | null;
    finished_at: string | null;
    undone_at: string | null;
    created_at: string;
    failure: string | null;
    undo: { removed: number; kept: number } | null;
    strategy: string;
    source_system: string | null;
}

export const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs disabled:opacity-50';

/** Badge colours of an import status. */
export function statusVariant(status: string, stuck = false): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (stuck || status === 'failed') return 'destructive';
    if (status === 'done') return 'default';
    if (status === 'undone') return 'outline';
    return 'secondary';
}

export function StatusBadge({ status, stuck = false }: { status: BatchStatus; stuck?: boolean }) {
    return <Badge variant={statusVariant(status.value, stuck)}>{stuck ? 'Bloqué' : status.label}</Badge>;
}

/**
 * Page of the import wizard: stepper (current step 1-6), the content on a
 * computer, and a short message below 768 px (the import is done from a computer).
 */
export function WizardPage({
    step,
    title,
    description,
    children,
    breadcrumbs = [],
}: {
    step: number;
    title: string;
    description?: string;
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}) {
    const crumbs: BreadcrumbItem[] = [{ title: 'Imports', href: '/crm/import' }, ...breadcrumbs];

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={`Importer : ${title}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="bg-muted/40 flex items-start gap-3 rounded-xl border p-4 text-sm md:hidden" role="note">
                    <Monitor className="mt-0.5 h-5 w-5 shrink-0" aria-hidden />
                    <div>
                        <p className="font-medium">L&apos;import se fait depuis un ordinateur.</p>
                        <p className="text-muted-foreground mt-1">
                            Ouvrez cette page sur un écran plus large pour déposer et vérifier votre fichier.
                        </p>
                        <Button asChild variant="outline" size="sm" className="mt-3">
                            <Link href={route('crm.import.index')}>Voir l&apos;historique des imports</Link>
                        </Button>
                    </div>
                </div>

                <div className="hidden flex-col gap-4 md:flex">
                    <Stepper step={step} />
                    <Heading title={title} description={description} />
                    {children}
                </div>
            </div>
        </AppLayout>
    );
}

export function Stepper({ step }: { step: number }) {
    return (
        <nav aria-label="Étapes de l'import">
            <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                {STEPS.map((label, i) => {
                    const n = i + 1;
                    const done = n < step;
                    const current = n === step;

                    return (
                        <li key={label} className="flex items-center gap-2" aria-current={current ? 'step' : undefined}>
                            <span
                                className={
                                    'flex h-6 w-6 items-center justify-center rounded-full border text-xs ' +
                                    (current ? 'bg-primary text-primary-foreground border-primary' : done ? 'bg-muted' : 'text-muted-foreground')
                                }
                            >
                                {done ? <Check className="h-3.5 w-3.5" aria-hidden /> : n}
                            </span>
                            <span className={current ? 'font-medium' : 'text-muted-foreground'}>{label}</span>
                            {n < STEPS.length && <span className="text-muted-foreground mr-1">›</span>}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export function Counter({ label, value, tone = 'default' }: { label: string; value: number; tone?: 'default' | 'warning' | 'danger' | 'good' }) {
    const colour =
        tone === 'danger'
            ? 'text-red-600 dark:text-red-400'
            : tone === 'warning'
              ? 'text-amber-600 dark:text-amber-400'
              : tone === 'good'
                ? 'text-green-600 dark:text-green-400'
                : '';

    return (
        <div className="rounded-xl border p-4">
            <p className={`text-2xl font-semibold tabular-nums ${colour}`}>{new Intl.NumberFormat('fr-FR').format(value)}</p>
            <p className="text-muted-foreground text-sm">{label}</p>
        </div>
    );
}

export interface UndoPreviewData {
    canUndo: boolean;
    removable: number;
    keptModified: number;
    keptMerged: number;
}

/** « Annuler l'import » with a confirmation that says how many fiches go and that edited ones stay. */
export function UndoDialog({ batchId, open, onOpenChange }: { batchId: string; open: boolean; onOpenChange: (open: boolean) => void }) {
    const [preview, setPreview] = useState<UndoPreviewData | null>(null);
    const [failed, setFailed] = useState(false);
    const [expired, setExpired] = useState(false);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) return;
        setPreview(null);
        setFailed(false);
        setExpired(false);
        fetch(route('crm.import.undo.preview', batchId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => {
                if (r.status === 401 || r.status === 419) setExpired(true);

                return r.ok ? r.json() : Promise.reject(new Error('preview'));
            })
            .then(setPreview)
            .catch(() => setFailed(true));
    }, [open, batchId]);

    const kept = (preview?.keptModified ?? 0) + (preview?.keptMerged ?? 0);
    const plural = (n: number, one: string, many: string) => `${new Intl.NumberFormat('fr-FR').format(n)} ${n > 1 ? many : one}`;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Annuler cet import ?</DialogTitle>
                    <DialogDescription asChild>
                        <div className="space-y-2">
                            {failed && expired && <p>Session expirée, reconnectez-vous.</p>}
                            {failed && !expired && <p>Impossible de calculer l&apos;effet de l&apos;annulation. Réessayez dans un instant.</p>}
                            {!failed && preview === null && <p>Calcul en cours…</p>}
                            {preview && (
                                <>
                                    <p>
                                        {preview.removable > 0 ? (
                                            <>
                                                <strong>{plural(preview.removable, 'fiche sera supprimée', 'fiches seront supprimées')}</strong>{' '}
                                                (placées dans la corbeille).
                                            </>
                                        ) : (
                                            'Aucune fiche ne sera supprimée.'
                                        )}
                                    </p>
                                    <p>
                                        Les fiches modifiées depuis l&apos;import sont conservées
                                        {kept > 0 ? ` : ${plural(kept, 'fiche est concernée', 'fiches sont concernées')}.` : '.'} Les fiches
                                        simplement mises à jour par l&apos;import ne sont pas remises dans leur état précédent.
                                    </p>
                                </>
                            )}
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Garder l&apos;import
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={busy || preview === null || !preview.canUndo}
                        onClick={() =>
                            router.post(
                                route('crm.import.undo', batchId),
                                {},
                                { onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => onOpenChange(false) },
                            )
                        }
                    >
                        Annuler l&apos;import
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** « Abandonner » : supprime pour de bon un import qui n'a pas encore été lancé (après confirmation). */
export function AbandonButton({ batchId, size = 'default' }: { batchId: string; size?: 'default' | 'sm' }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <>
            <Button type="button" variant="ghost" size={size} onClick={() => setOpen(true)}>
                Abandonner
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Abandonner cet import ?</DialogTitle>
                        <DialogDescription>
                            Le fichier déposé et la correspondance des colonnes seront supprimés définitivement. Aucune fiche n&apos;est créée ni
                            modifiée : cet import n&apos;a pas été lancé.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Garder l&apos;import
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() =>
                                router.delete(route('crm.import.destroy', batchId), {
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                    onSuccess: () => setOpen(false),
                                })
                            }
                        >
                            Abandonner l&apos;import
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Lines of a simulation or of a run: line number and French messages. */
export function LineList({ rows, empty }: { rows: { line: number; messages: string[] }[]; empty?: string }) {
    if (rows.length === 0) {
        return empty ? <p className="text-muted-foreground text-sm">{empty}</p> : null;
    }

    return (
        <ul className="divide-y rounded-xl border text-sm">
            {rows.map((r) => (
                <li key={r.line} className="flex gap-3 px-4 py-2">
                    <span className="text-muted-foreground w-20 shrink-0 tabular-nums">Ligne {r.line}</span>
                    <span>{r.messages.join(' ')}</span>
                </li>
            ))}
        </ul>
    );
}
