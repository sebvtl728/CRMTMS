import InputError from '@/components/input-error';
import { IndividualPlanningDialog } from '@/components/sessions/individual-planning';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatDay } from '@/lib/crm';
import { formatMinutes } from '@/lib/finance';
import { sendJson } from '@/lib/sessions';
import { Link, router } from '@inertiajs/react';
import { Video } from 'lucide-react';
import { useState } from 'react';

export interface FollowUpSeance {
    id: string;
    date: string;
    starts_at: string;
    ends_at: string;
    duration_minutes: number;
    module: string | null;
    trainer: string | null;
    place: string | null;
    remote: boolean;
    cancelled: boolean;
    past: boolean;
    /** Absent without the attendance right; null: nothing entered yet. */
    attendance?: { status: string; label: string; minutes: number } | null;
    /** « Noter la présence »: a manager of the attendance, a past séance of an open month. */
    can_record?: boolean;
}

export interface FollowUpSignature {
    id: string | null;
    slot_id: string;
    date: string;
    half: 'morning' | 'afternoon';
    half_label: string;
    slot_status: 'open' | 'closed' | 'validated';
    signed: boolean;
    signed_at: string | null;
    kind: 'on_time' | 'catch_up_onsite' | 'catch_up_remote' | null;
    state: 'pending' | 'confirmed' | 'refused' | null;
    drawing_url: string | null;
    /** The remote catch-up link last sent for this half-day (not revoked). */
    catch_up_link: { sent_at: string | null; email_status: string | null; expires_at: string; used: boolean } | null;
    can_confirm: boolean;
    can_send_link: boolean;
}

export interface TrainingFollowUpProps {
    session: { id: string; label: string; href: string; starts_on: string; ends_on: string };
    seances: FollowUpSeance[];
    hours?: { realised_minutes: number; planned_minutes: number };
    signatures?: FollowUpSignature[];
    /** With the attendance right: the enrolment the actions write to. */
    enrolment_id?: string;
    /** For a manager of the attendance: the trainee's e-mail, to prefill the catch-up link. */
    email?: string | null;
    /** Who may change the enrolment: « Planning individuel » (exemptions, own hours). */
    planning_enrolment_id?: string;
}

type Action =
    | { kind: 'record'; seance: FollowUpSeance }
    | { kind: 'refuse'; signature: FollowUpSignature }
    | { kind: 'link'; signature: FollowUpSignature };

const LINK_STATUS: Record<string, string> = { pending: 'envoi en cours', sent: 'envoyé', failed: "échec de l'envoi" };

/** Séances to come shown before « Afficher toutes les séances à venir ». */
const UPCOMING_LIMIT = 10;

const time = new Intl.DateTimeFormat('fr-FR', { timeStyle: 'short', timeZone: 'Europe/Paris' });

const ATTENDANCE_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    present: 'default',
    partial: 'secondary',
    absent: 'destructive',
    excused: 'outline',
};

const KIND_LABEL: Record<string, string> = {
    on_time: "À l'heure",
    catch_up_onsite: 'Rattrapage sur place',
    catch_up_remote: 'Rattrapage à distance',
};

const STATE_LABEL: Record<string, string> = { pending: 'à confirmer', confirmed: 'confirmé', refused: 'refusé' };

function weekday(day: string): string {
    const [y, m, d] = day.split('-').map(Number);

    return new Intl.DateTimeFormat('fr-FR', { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)));
}

/** Planning, attendance and signatures of an admitted trainee (the keys sent follow the user's rights). */
export function TrainingFollowUp({
    followUp,
    name,
}: {
    followUp: TrainingFollowUpProps;
    /** The trainee, shown in the « Planning individuel » title. */ name?: string;
}) {
    const { session, seances, hours, signatures } = followUp;
    const withAttendance = hours !== undefined;
    const [showPast, setShowPast] = useState(false);
    const [showAllUpcoming, setShowAllUpcoming] = useState(false);
    const upcoming = seances.filter((s) => !s.past);
    const past = seances.filter((s) => s.past);
    // A long training holds hundreds of séances: the next ones only, unless asked.
    const shownUpcoming = showAllUpcoming ? upcoming : upcoming.slice(0, UPCOMING_LIMIT);
    const shown = showPast ? [...past, ...shownUpcoming] : shownUpcoming;
    const [action, setAction] = useState<Action | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [planningOpen, setPlanningOpen] = useState(false);

    const confirmCatchUp = async (signature: FollowUpSignature) => {
        if (!signature.id || !confirm(`Confirmer le rattrapage du ${formatDay(signature.date)} (${signature.half_label.toLowerCase()}) ?`)) return;
        const answer = await sendJson('POST', `/sessions/rattrapages/${signature.id}/confirm`);
        setNotice(answer.ok ? 'Rattrapage confirmé.' : answer.message);
        if (answer.ok) router.reload({ only: ['follow_up'] });
    };

    return (
        <section aria-label="Suivi de formation" className="grid gap-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">Suivi de formation</h2>
                <p className="text-sm">
                    <Link href={session.href} className="font-medium hover:underline">
                        {session.label}
                    </Link>
                    <span className="text-muted-foreground">
                        {' '}
                        du {formatDay(session.starts_on)} au {formatDay(session.ends_on)}
                    </span>
                </p>
            </div>

            {hours && (
                <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div>
                        <dt className="text-muted-foreground">Heures réalisées</dt>
                        <dd className="font-medium">{formatMinutes(hours.realised_minutes)}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Heures prévues</dt>
                        <dd className="font-medium">{formatMinutes(hours.planned_minutes)}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Séances passées</dt>
                        <dd className="font-medium">{past.filter((s) => !s.cancelled).length}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Séances à venir</dt>
                        <dd className="font-medium">{upcoming.filter((s) => !s.cancelled).length}</dd>
                    </div>
                </dl>
            )}

            <div className="grid gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Planning</h3>
                    <div className="flex flex-wrap gap-4">
                        {followUp.planning_enrolment_id && (
                            <button type="button" className="text-sm underline" onClick={() => setPlanningOpen(true)}>
                                Planning individuel
                            </button>
                        )}
                        {past.length > 0 && (
                            <button type="button" className="text-primary text-sm hover:underline" onClick={() => setShowPast((v) => !v)}>
                                {showPast ? 'Masquer les séances passées' : `Afficher les séances passées (${past.length})`}
                            </button>
                        )}
                        {upcoming.length > UPCOMING_LIMIT && (
                            <button type="button" className="text-primary text-sm hover:underline" onClick={() => setShowAllUpcoming((v) => !v)}>
                                {showAllUpcoming ? 'Réduire' : `Afficher toutes les séances à venir (${upcoming.length})`}
                            </button>
                        )}
                    </div>
                </div>
                {shown.length === 0 ? (
                    <p className="text-muted-foreground text-sm">{seances.length === 0 ? 'Aucune séance planifiée.' : 'Aucune séance à venir.'}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left">
                                <tr>
                                    <th className="py-1 pr-3 font-normal">Date</th>
                                    <th className="py-1 pr-3 font-normal">Horaires</th>
                                    <th className="py-1 pr-3 font-normal">Module</th>
                                    <th className="py-1 pr-3 font-normal">Formateur</th>
                                    <th className="py-1 pr-3 font-normal">Lieu</th>
                                    {withAttendance && <th className="py-1 font-normal">Présence</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((s) => (
                                    <tr key={s.id} className={`border-t ${s.cancelled ? 'text-muted-foreground line-through' : ''}`}>
                                        <td className="py-1 pr-3 whitespace-nowrap">
                                            <span className="capitalize">{weekday(s.date)}</span> {formatDay(s.date)}
                                        </td>
                                        <td className="py-1 pr-3 whitespace-nowrap">
                                            {s.starts_at}–{s.ends_at}
                                        </td>
                                        <td className="py-1 pr-3">{s.module ?? '-'}</td>
                                        <td className="py-1 pr-3">{s.trainer ?? '-'}</td>
                                        <td className="py-1 pr-3">
                                            <span className="inline-flex items-center gap-1">
                                                {s.remote && <Video className="h-3.5 w-3.5" aria-label="Distanciel" />}
                                                {s.place ?? '-'}
                                            </span>
                                        </td>
                                        {withAttendance && (
                                            <td className="py-1">
                                                {s.cancelled ? (
                                                    <span className="no-underline">Annulée</span>
                                                ) : s.attendance ? (
                                                    <Badge variant={ATTENDANCE_VARIANT[s.attendance.status] ?? 'outline'}>
                                                        {s.attendance.label}
                                                        {s.attendance.status === 'partial' && ` (${formatMinutes(s.attendance.minutes)})`}
                                                    </Badge>
                                                ) : s.past ? (
                                                    <span className="text-muted-foreground">Non saisie</span>
                                                ) : (
                                                    <span className="text-muted-foreground">À venir</span>
                                                )}
                                                {s.can_record && (
                                                    <button
                                                        type="button"
                                                        className="text-primary ml-2 text-xs hover:underline"
                                                        onClick={() => setAction({ kind: 'record', seance: s })}
                                                    >
                                                        {s.attendance ? 'Modifier' : 'Noter la présence'}
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {signatures && (
                <div className="grid gap-2">
                    <h3 className="text-sm font-medium">Émargement en ligne</h3>
                    {signatures.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune demi-journée ouverte à la signature.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="py-1 pr-3 font-normal">Demi-journée</th>
                                        <th className="py-1 pr-3 font-normal">Signé</th>
                                        <th className="py-1 pr-3 font-normal">Type</th>
                                        <th className="py-1 pr-3 font-normal">Signature</th>
                                        <th className="py-1 font-normal">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {signatures.map((s) => (
                                        <tr key={s.slot_id} className="border-t align-middle">
                                            <td className="py-1 pr-3 whitespace-nowrap">
                                                {formatDay(s.date)} · {s.half_label}
                                            </td>
                                            <td className="py-1 pr-3 whitespace-nowrap">
                                                {s.signed ? (
                                                    `à ${s.signed_at ? time.format(new Date(s.signed_at)) : '-'}`
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {s.slot_status === 'open' ? 'Pas encore' : 'Non signé'}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-1 pr-3">
                                                {s.kind ? KIND_LABEL[s.kind] : '-'}
                                                {s.state && (
                                                    <Badge
                                                        variant={
                                                            s.state === 'refused' ? 'destructive' : s.state === 'pending' ? 'outline' : 'secondary'
                                                        }
                                                        className="ml-2"
                                                    >
                                                        {STATE_LABEL[s.state]}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-1">
                                                {s.drawing_url ? (
                                                    <a href={s.drawing_url} target="_blank" rel="noreferrer" title="Ouvrir la signature">
                                                        <img
                                                            src={s.drawing_url}
                                                            alt={`Signature du ${formatDay(s.date)}, ${s.half_label.toLowerCase()}`}
                                                            className="h-8 w-auto rounded border bg-white"
                                                            loading="lazy"
                                                        />
                                                    </a>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="py-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {s.can_confirm && (
                                                        <>
                                                            <Button type="button" size="sm" variant="outline" onClick={() => confirmCatchUp(s)}>
                                                                Confirmer
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => setAction({ kind: 'refuse', signature: s })}
                                                            >
                                                                Refuser
                                                            </Button>
                                                        </>
                                                    )}
                                                    {!s.signed && s.catch_up_link && (
                                                        <span className="text-muted-foreground text-xs">
                                                            Lien{' '}
                                                            {s.catch_up_link.used
                                                                ? 'utilisé'
                                                                : (LINK_STATUS[s.catch_up_link.email_status ?? ''] ?? 'envoyé')}{' '}
                                                            le {formatDateTime(s.catch_up_link.sent_at)}
                                                        </span>
                                                    )}
                                                    {s.can_send_link && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => setAction({ kind: 'link', signature: s })}
                                                        >
                                                            {s.catch_up_link ? 'Renvoyer un lien' : 'Envoyer un lien de rattrapage'}
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {notice && (
                <p role="status" className="text-sm">
                    {notice}
                </p>
            )}

            {action && followUp.enrolment_id && (
                <ActionDialog
                    action={action}
                    enrolmentId={followUp.enrolment_id}
                    email={followUp.email ?? ''}
                    onClose={() => setAction(null)}
                    onDone={(message) => {
                        setAction(null);
                        setNotice(message);
                        router.reload({ only: ['follow_up'] });
                    }}
                />
            )}
            {planningOpen && followUp.planning_enrolment_id && (
                <IndividualPlanningDialog
                    enrolmentId={followUp.planning_enrolment_id}
                    name={name ?? 'ce stagiaire'}
                    onClose={() => {
                        setPlanningOpen(false);
                        router.reload({ only: ['follow_up'] });
                    }}
                />
            )}
        </section>
    );
}

/** Notes the attendance of a séance, refuses a catch-up (reason required) or sends a remote catch-up link. */
function ActionDialog({
    action,
    enrolmentId,
    email: initialEmail,
    onClose,
    onDone,
}: {
    action: Action;
    enrolmentId: string;
    email: string;
    onClose: () => void;
    onDone: (message: string) => void;
}) {
    const [status, setStatus] = useState(action.kind === 'record' ? (action.seance.attendance?.status ?? 'present') : 'present');
    const [minutes, setMinutes] = useState(
        action.kind === 'record' && action.seance.attendance?.status === 'partial' ? String(action.seance.attendance.minutes) : '',
    );
    const [reason, setReason] = useState('');
    const [email, setEmail] = useState(initialEmail);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async () => {
        setBusy(true);
        const answer =
            action.kind === 'record'
                ? await sendJson('POST', `/seances/${action.seance.id}/attendance`, {
                      enrolment_id: enrolmentId,
                      status,
                      minutes: status === 'partial' ? minutes : null,
                  })
                : action.kind === 'refuse'
                  ? await sendJson('POST', `/sessions/rattrapages/${action.signature.id}/refuse`, { reason })
                  : await sendJson('POST', `/sessions/emargement/${action.signature.slot_id}/catch-up-links`, { enrolment_id: enrolmentId, email });
        setBusy(false);
        if (answer.ok) {
            onDone(
                action.kind === 'record' ? 'Présence enregistrée.' : action.kind === 'refuse' ? 'Rattrapage refusé.' : 'Lien de rattrapage envoyé.',
            );
        } else {
            setErrors(answer.errors);
            setMessage(Object.keys(answer.errors).length > 0 ? null : answer.message);
        }
    };

    const title =
        action.kind === 'record' ? 'Noter la présence' : action.kind === 'refuse' ? 'Refuser le rattrapage' : 'Envoyer un lien de rattrapage';
    const subject =
        action.kind === 'record'
            ? `Séance du ${formatDay(action.seance.date)}, ${action.seance.starts_at}–${action.seance.ends_at}`
            : `${formatDay(action.signature.date)}, ${action.signature.half_label.toLowerCase()}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{subject}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    {action.kind === 'record' && (
                        <>
                            <fieldset className="grid gap-2">
                                <legend className="sr-only">Présence</legend>
                                {[
                                    ['present', 'Présent'],
                                    ['partial', 'Partiel'],
                                    ['absent', 'Absent'],
                                ].map(([value, label]) => (
                                    <label key={value} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name="status"
                                            value={value}
                                            checked={status === value}
                                            onChange={() => setStatus(value)}
                                        />
                                        {label}
                                    </label>
                                ))}
                            </fieldset>
                            {status === 'partial' && (
                                <div className="grid gap-1">
                                    <Label htmlFor="follow-up-minutes">Minutes de présence (sur {action.seance.duration_minutes})</Label>
                                    <Input id="follow-up-minutes" inputMode="numeric" value={minutes} onChange={(e) => setMinutes(e.target.value)} />
                                    <InputError message={errors.minutes} />
                                </div>
                            )}
                            <InputError message={errors.status ?? errors.enrolment_id} />
                        </>
                    )}
                    {action.kind === 'refuse' && (
                        <div className="grid gap-1">
                            <Label htmlFor="follow-up-reason">Motif (obligatoire)</Label>
                            <Input id="follow-up-reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                    )}
                    {action.kind === 'link' && (
                        <div className="grid gap-1">
                            <Label htmlFor="follow-up-email">Adresse e-mail du stagiaire</Label>
                            <Input id="follow-up-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                            <p className="text-muted-foreground text-xs">Le lien est valable 7 jours, pour une seule signature.</p>
                            <InputError message={errors.email ?? errors.enrolment_id} />
                        </div>
                    )}
                    {message && (
                        <p role="alert" className="text-destructive text-sm">
                            {message}
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button type="button" onClick={submit} disabled={busy}>
                        {action.kind === 'record' ? 'Enregistrer' : action.kind === 'refuse' ? 'Refuser' : 'Envoyer'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
