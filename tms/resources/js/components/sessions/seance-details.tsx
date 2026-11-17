import { seancePlace, type PlanningSeance } from '@/components/sessions/planning-board';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatDay } from '@/lib/crm';
import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

/** A séance opened from the planning: its details, its session, its attendance. */
export function SeanceDetails({
    seance,
    onClose,
    onAttendance,
}: {
    seance: PlanningSeance | null;
    onClose: () => void;
    onAttendance: (s: PlanningSeance) => void;
}) {
    const cancelled = seance?.status === 'cancelled';

    return (
        <Dialog open={seance !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                {seance && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{seance.session.label}</DialogTitle>
                            <DialogDescription>
                                {formatDay(seance.date)} · {seance.start}–{seance.end} ({seance.duration})
                            </DialogDescription>
                        </DialogHeader>
                        <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Module</dt>
                            <dd>{seance.module}</dd>
                            <dt className="text-muted-foreground">Formateur</dt>
                            <dd>{seance.trainer?.label ?? 'À définir'}</dd>
                            <dt className="text-muted-foreground">Lieu</dt>
                            <dd>{seancePlace(seance) ?? '—'}</dd>
                            {cancelled && (
                                <>
                                    <dt className="text-muted-foreground">Statut</dt>
                                    <dd>
                                        <Badge variant="destructive">Annulée</Badge> {seance.cancel_reason}
                                    </dd>
                                </>
                            )}
                            {seance.note && (
                                <>
                                    <dt className="text-muted-foreground">Note interne</dt>
                                    <dd className="whitespace-pre-line">{seance.note}</dd>
                                </>
                            )}
                        </dl>
                        {(seance.alerts ?? []).length > 0 && (
                            <ul className="grid gap-1 rounded-md border border-amber-500/40 bg-amber-500/5 p-2 text-xs" aria-label="Alertes">
                                {(seance.alerts ?? []).map((a, i) => (
                                    <li key={i} className="flex gap-1">
                                        <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0 text-amber-600" aria-hidden />
                                        {a.message}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <DialogFooter className="gap-2">
                            {seance.session.href && (
                                <Button asChild variant="outline">
                                    <Link href={seance.session.href}>Ouvrir la session</Link>
                                </Button>
                            )}
                            {seance.can_attendance && <Button onClick={() => onAttendance(seance)}>Saisir les présences</Button>}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
