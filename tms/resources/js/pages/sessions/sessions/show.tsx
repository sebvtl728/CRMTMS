import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { SessionBlocks, type SessionBlock } from '@/components/session-blocks';
import { EnrolDialog, type EnrolDossierOption } from '@/components/sessions/enrolment-dialog';
import { EnrolmentList, type EnrolmentReferences, type EnrolmentRow } from '@/components/sessions/enrolment-list';
import { SessionAttendance } from '@/components/sessions/session-attendance';
import { SessionColorDot } from '@/components/sessions/session-color-dot';
import { SessionModules, type SessionPlanning } from '@/components/sessions/session-modules';
import { SessionNotesPanel, type NotesPanel } from '@/components/sessions/session-notes-panel';
import { SessionPlanningSeances } from '@/components/sessions/session-planning';
import { StepNav, useStep } from '@/components/sessions/session-steps';
import { SessionSynthesis, type CardRow } from '@/components/sessions/session-synthesis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDay, type Option } from '@/lib/crm';
import { sessionDates, sessionLocation, sessionStatusVariant, type SessionRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Pencil, UserPlus } from 'lucide-react';
import { FormEventHandler, useState, type ReactNode } from 'react';

interface ShowProps {
    session: Omit<SessionRow, 'organisation'> & {
        /** can_open: the user may open the fiche (a link then, plain text otherwise). */
        organisation: (NonNullable<SessionRow['organisation']> & { can_open: boolean }) | null;
        stored_status: string;
        notes: string | null;
        cancel_reason: string | null;
        cancelled_on: string | null;
        created_by: string | null;
        created_at: string | null;
    };
    can: { update: boolean; cancel: boolean; delete: boolean; restore: boolean };
    cancel_reasons: Option[];
    enrolments: EnrolmentRow[];
    places: { capacity: number | null; taken: number; free: number | null };
    /** Null without sessions.sessions.update on the session, or once cancelled. */
    enrol: { dossiers: EnrolDossierOption[] } | null;
    enrolment_references: EnrolmentReferences | null;
    planning: SessionPlanning;
    /** The « Suivi » step: attendance managed or closed on the session. */
    attendance_tab: boolean;
    /** Steps shown to the user (synthese, configuration, planning, stagiaires[, suivi]). */
    steps: string[];
    /** Blocks of other modules (documents…), on the « Stagiaires » step. */
    blocks: SessionBlock[];
    /** Cards of the « Synthèse » (none once cancelled). */
    checklist: CardRow[];
    /** Tasks and notes of the « Synthèse » (spec § 4.5). */
    notes_panel: NotesPanel;
    status?: string;
    /** Trainees planned above the new hours of the session (their enrolments unchanged). */
    warning?: string | null;
}

function CancelDialog({ sessionId, reasons, open, onClose }: { sessionId: string; reasons: Option[]; open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancel_reason_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sessions.cancel', sessionId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Annuler la session</DialogTitle>
                        <DialogDescription>Une session annulée ne se modifie plus. Le motif est obligatoire.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="cancel_reason_id">Motif d'annulation *</Label>
                        <Select value={data.cancel_reason_id || undefined} onValueChange={(v) => setData('cancel_reason_id', v)}>
                            <SelectTrigger id="cancel_reason_id">
                                <SelectValue placeholder="Choisissez" />
                            </SelectTrigger>
                            <SelectContent>
                                {reasons.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cancel_reason_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Annuler la session
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SessionShow({
    session,
    can,
    cancel_reasons,
    enrolments,
    places,
    enrol,
    enrolment_references,
    planning,
    attendance_tab,
    steps,
    blocks,
    checklist,
    notes_panel,
    status,
    warning,
}: ShowProps) {
    const [step, setStep] = useStep(steps);
    const [cancelling, setCancelling] = useState(false);
    const [enrolling, setEnrolling] = useState(false);
    const waiting = enrolments.filter((e) => e.status === 'waitlisted');
    const others = enrolments.filter((e) => e.status !== 'waitlisted');
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sessions', href: '/sessions' },
        { title: session.label, href: '#' },
    ];

    const destroy = () => {
        if (window.confirm(`Mettre la session ${session.number} à la corbeille ?`)) {
            router.delete(route('sessions.destroy', session.id));
        }
    };

    const field = (label: string, value: ReactNode) => (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{value}</dd>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={session.title ?? `Session ${session.number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-2">
                        {session.color && (
                            <div className="flex items-center gap-2 text-xs">
                                <SessionColorDot color={session.color} className="h-3 w-3" />
                                <span className="text-muted-foreground">Couleur de la session dans le planning</span>
                            </div>
                        )}
                        <Heading
                            title={session.title ?? `Session ${session.number}`}
                            description={
                                session.title
                                    ? `${session.training?.title ?? 'Formation introuvable'} · Session ${session.number}`
                                    : (session.training?.title ?? 'Formation introuvable')
                            }
                        />
                        <div className="flex flex-wrap gap-2">
                            <Badge variant={sessionStatusVariant(session.status)}>{session.status_label}</Badge>
                            <Badge variant="outline">{session.kind_label}</Badge>
                            {session.trashed && <Badge variant="destructive">Dans la corbeille</Badge>}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('sessions.edit', session.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Modifier
                                </Link>
                            </Button>
                        )}
                        {can.cancel && (
                            <Button type="button" variant="outline" onClick={() => setCancelling(true)}>
                                Annuler la session
                            </Button>
                        )}
                        {can.delete && (
                            <Button type="button" variant="ghost" className="text-destructive" onClick={destroy}>
                                Mettre à la corbeille
                            </Button>
                        )}
                        {can.restore && (
                            <Button type="button" onClick={() => router.post(route('sessions.restore', session.id))}>
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {warning && <div className="text-sm font-medium text-amber-700 dark:text-amber-400">{warning}</div>}

                {session.status === 'cancelled' && step !== 'synthese' && (
                    <div className="border-destructive/40 bg-destructive/5 rounded-xl border p-3 text-sm">
                        Annulée le {formatDay(session.cancelled_on)} · Motif : {session.cancel_reason ?? '-'}
                    </div>
                )}

                <StepNav steps={steps} current={step} onChange={(s) => setStep(s)} />

                {step === 'synthese' && (
                    <SessionSynthesis
                        cards={checklist}
                        cancelled={session.status === 'cancelled' ? { reason: session.cancel_reason } : null}
                        aside={<SessionNotesPanel sessionId={session.id} panel={notes_panel} />}
                        onOpen={setStep}
                    />
                )}

                {step === 'planning' && <SessionPlanningSeances session={session} planning={planning} />}

                {step === 'suivi' && attendance_tab && (
                    <div id="attendance" className="grid scroll-mt-4 gap-6">
                        <SessionAttendance sessionId={session.id} />
                    </div>
                )}

                {step === 'configuration' && (
                    <div className="grid gap-6">
                        <section aria-label="Informations" className="rounded-xl border p-4">
                            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {field(
                                    'Formation',
                                    session.training ? (
                                        <>
                                            {session.training.title}
                                            {session.training.code && <span className="text-muted-foreground"> ({session.training.code})</span>}
                                        </>
                                    ) : (
                                        '-'
                                    ),
                                )}
                                {field('DAS', session.das ?? '-')}
                                {field('Dates', sessionDates(session))}
                                {field('Heures prévues', session.planned_hours ? `${session.planned_hours} h` : '-')}
                                {field('Lieu', sessionLocation(session))}
                                {field('Modalité', session.modality ?? '-')}
                                {session.kind !== 'inter'
                                    ? field(
                                          'Entreprise',
                                          session.organisation ? (
                                              session.organisation.can_open ? (
                                                  <Link href={route('crm.organisations.show', session.organisation.id)} className="hover:underline">
                                                      {session.organisation.label}
                                                      {session.organisation.trashed && ' (supprimée)'}
                                                  </Link>
                                              ) : (
                                                  `${session.organisation.label}${session.organisation.trashed ? ' (supprimée)' : ''}`
                                              )
                                          ) : (
                                              '-'
                                          ),
                                      )
                                    : field('Places', session.capacity ?? '-')}
                                {field('Responsable', session.responsible ?? '-')}
                                {field('Créée', `${formatDateTime(session.created_at)}${session.created_by ? ` par ${session.created_by}` : ''}`)}
                            </dl>
                            {session.notes && (
                                <div className="mt-4 grid gap-0.5">
                                    <div className="text-muted-foreground text-xs">Notes</div>
                                    <p className="text-sm whitespace-pre-line">{session.notes}</p>
                                </div>
                            )}
                        </section>

                        <SessionModules sessionId={session.id} session={session} planning={planning} />
                    </div>
                )}

                {step === 'stagiaires' && (
                    <div className="grid gap-6">
                        <section id="enrolments" aria-label="Inscrits" className="grid scroll-mt-4 gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 id="fundings" className="scroll-mt-4 text-sm font-semibold">
                                    Inscrits · {places.taken}
                                    {places.capacity !== null &&
                                        ` / ${places.capacity} places (${places.free} libre${(places.free ?? 0) > 1 ? 's' : ''})`}
                                </h2>
                                {enrol && (
                                    <Button type="button" size="sm" onClick={() => setEnrolling(true)}>
                                        <UserPlus className="h-4 w-4" />
                                        Inscrire des stagiaires
                                    </Button>
                                )}
                            </div>
                            <EnrolmentList rows={others} context="session" references={enrolment_references} />
                        </section>

                        {waiting.length > 0 && (
                            <section aria-label="Liste d'attente" className="grid gap-3">
                                <h2 className="text-sm font-semibold">Liste d'attente · {waiting.length}</h2>
                                <p className="text-muted-foreground text-sm">
                                    Ordre d'arrivée ; une place libérée ne promeut personne automatiquement.
                                </p>
                                <EnrolmentList rows={waiting} context="session" references={enrolment_references} />
                            </section>
                        )}

                        <SessionBlocks blocks={blocks} />
                    </div>
                )}
            </div>

            {enrolling && enrol && (
                <EnrolDialog
                    source={{
                        from: 'session',
                        sessionId: session.id,
                        sessionHours: session.planned_hours,
                        sessionStart: session.starts_on,
                        dossiers: enrol.dossiers,
                    }}
                    onClose={() => setEnrolling(false)}
                />
            )}
            {can.cancel && <CancelDialog sessionId={session.id} reasons={cancel_reasons} open={cancelling} onClose={() => setCancelling(false)} />}
        </AppLayout>
    );
}
