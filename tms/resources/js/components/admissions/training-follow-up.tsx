import { Badge } from '@/components/ui/badge';
import { formatDay } from '@/lib/crm';
import { formatMinutes } from '@/lib/finance';
import { Link } from '@inertiajs/react';
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
}

export interface TrainingFollowUpProps {
    session: { id: string; label: string; href: string; starts_on: string; ends_on: string };
    seances: FollowUpSeance[];
    hours?: { realised_minutes: number; planned_minutes: number };
    signatures?: FollowUpSignature[];
}

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
export function TrainingFollowUp({ followUp }: { followUp: TrainingFollowUpProps }) {
    const { session, seances, hours, signatures } = followUp;
    const withAttendance = hours !== undefined;
    const [showPast, setShowPast] = useState(false);
    const [showAllUpcoming, setShowAllUpcoming] = useState(false);
    const upcoming = seances.filter((s) => !s.past);
    const past = seances.filter((s) => s.past);
    // A long training holds hundreds of séances: the next ones only, unless asked.
    const shownUpcoming = showAllUpcoming ? upcoming : upcoming.slice(0, UPCOMING_LIMIT);
    const shown = showPast ? [...past, ...shownUpcoming] : shownUpcoming;

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
                                        <th className="py-1 font-normal">Signature</th>
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
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
