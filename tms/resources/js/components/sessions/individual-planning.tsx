import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDay } from '@/lib/crm';
import { sendJson } from '@/lib/sessions';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

/** As IndividualPlanningView sends it. */
interface PlanningSeance {
    id: string;
    date: string;
    start: string;
    end: string;
    seance_start: string;
    seance_end: string;
    duration: string;
    module: string | null;
    /** The session of an added séance (catch-up), null for his own session. */
    session_label: string | null;
    kind: 'normal' | 'exempted' | 'added';
    own_hours: boolean;
    adjustment_id: string | null;
    reason: string | null;
    /** Exempted through the whole module (no adjustment of its own). */
    module_exempted: boolean;
    has_attendance: boolean;
    closed: boolean;
}

interface PlanningModule {
    id: string;
    title: string;
    mode: 'seances' | 'interval';
    planned: string;
    exempted: boolean;
    adjustment_id: string | null;
}

interface Planning {
    enrolment_id: string;
    can_edit: boolean;
    base: string;
    planned: string;
    base_minutes: number;
    planned_minutes: number;
    has_adjustments: boolean;
    modules: PlanningModule[];
    seances: PlanningSeance[];
}

interface Available {
    sessions: { id: string; label: string }[];
    seances: { id: string; date: string; start: string; end: string; module: string | null; conflict: boolean }[];
}

/** « Ajouter des séances » of another session (catch-up, personalised path). */
function AddSeances({ enrolmentId, busy, onAdd }: { enrolmentId: string; busy: boolean; onAdd: (ids: string[], reason: string) => void }) {
    const [q, setQ] = useState('');
    const [session, setSession] = useState('');
    const [found, setFound] = useState<Available>({ sessions: [], seances: [] });
    const [picked, setPicked] = useState<string[]>([]);
    const [reason, setReason] = useState('');

    useEffect(() => {
        const timer = window.setTimeout(async () => {
            const params = new URLSearchParams({ q, ...(session ? { session } : {}) });
            const answer = await sendJson<Available>('GET', `/enrolments/${enrolmentId}/planning/available?${params.toString()}`);
            if (answer.ok && answer.data) setFound(answer.data);
        }, 250);
        return () => window.clearTimeout(timer);
    }, [enrolmentId, q, session]);

    return (
        <section className="grid gap-2 rounded-lg border p-3">
            <h3 className="text-sm font-medium">Ajouter des séances d'une autre session</h3>
            <div className="grid gap-2 sm:grid-cols-2">
                <div className="grid gap-1">
                    <Label htmlFor="add_search">Rechercher une session</Label>
                    <Input id="add_search" value={q} placeholder="Numéro ou intitulé" onChange={(e) => setQ(e.target.value)} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="add_session">Session</Label>
                    <select
                        id="add_session"
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={session}
                        onChange={(e) => {
                            setSession(e.target.value);
                            setPicked([]);
                        }}
                    >
                        <option value="">Choisir…</option>
                        {found.sessions.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            {session && found.seances.length === 0 && <p className="text-muted-foreground text-sm">Aucune séance disponible pour lui.</p>}
            {found.seances.length > 0 && (
                <ul className="max-h-64 divide-y overflow-y-auto rounded-lg border">
                    {found.seances.map((s) => (
                        <li key={s.id} className="flex items-center gap-2 p-2 text-sm">
                            <input
                                type="checkbox"
                                aria-label={`Ajouter la séance du ${formatDay(s.date)}`}
                                checked={picked.includes(s.id)}
                                onChange={() => setPicked((c) => (c.includes(s.id) ? c.filter((x) => x !== s.id) : [...c, s.id]))}
                            />
                            <span>
                                {formatDay(s.date)} · {s.start}–{s.end}
                                {s.module && <span className="text-muted-foreground"> · {s.module}</span>}{' '}
                                {s.conflict && <Badge variant="destructive">Chevauche une de ses séances</Badge>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {picked.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                    <div className="grid gap-1">
                        <Label htmlFor="add_reason">Motif (facultatif)</Label>
                        <Input id="add_reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
                    </div>
                    <Button
                        disabled={busy}
                        onClick={() => {
                            onAdd(picked, reason);
                            setPicked([]);
                            setReason('');
                        }}
                    >
                        Ajouter {picked.length > 1 ? `${picked.length} séances` : 'la séance'}
                    </Button>
                </div>
            )}
        </section>
    );
}

/**
 * « Planning individuel » of one trainee (enrolment): exempt him from
 * séances or a whole module, give him his own hours at a séance. His
 * planned hours are computed from it on the server.
 */
export function IndividualPlanningDialog({ enrolmentId, name, onClose }: { enrolmentId: string; name: string; onClose: () => void }) {
    const [planning, setPlanning] = useState<Planning | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [reason, setReason] = useState('');
    const [hours, setHours] = useState<{ seance: PlanningSeance; start: string; end: string; pause: string } | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const load = useCallback(async () => {
        const answer = await sendJson<Planning>('GET', `/enrolments/${enrolmentId}/planning`);
        if (answer.ok && answer.data) setPlanning(answer.data);
        else setError(answer.message);
    }, [enrolmentId]);

    useEffect(() => {
        load();
    }, [load]);

    const write = (method: 'post' | 'put' | 'delete', url: string, data: Record<string, unknown> = {}, done?: () => void) => {
        setBusy(true);
        setErrors({});
        setError(null);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                done?.();
                load();
            },
            onError: (e: Record<string, string>) => {
                setErrors(e);
                setError(Object.values(e)[0] ?? 'Une erreur est survenue.');
            },
            onFinish: () => setBusy(false),
        };
        if (method === 'delete') router.delete(url, options);
        else router[method](url, data as never, options);
    };

    const exemptSelected = () =>
        write('post', `/enrolments/${enrolmentId}/planning/exemptions`, { seance_ids: selected, reason: reason || null }, () => {
            setSelected([]);
            setReason('');
        });

    const toggle = (id: string) => setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const live = planning?.seances.filter((s) => s.kind !== 'exempted') ?? [];
    const exempted = planning?.seances.filter((s) => s.kind === 'exempted') ?? [];

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Planning individuel de {name}</DialogTitle>
                    <DialogDescription>
                        Dispensez-le de séances ou d'un module, donnez-lui ses horaires (arrivée tardive, départ anticipé) ou ajoutez-lui des séances
                        d'une autre session (rattrapage). Ses heures prévues se recalculent ; le prix ne change pas.
                    </DialogDescription>
                </DialogHeader>

                {!planning && !error && <LoaderCircle className="h-5 w-5 animate-spin" aria-label="Chargement" />}
                {error && <p className="text-destructive text-sm">{error}</p>}

                {planning && (
                    <div className="grid gap-5">
                        <p className="text-sm">
                            Heures prévues : <span className="font-medium">{planning.planned}</span>
                            {planning.has_adjustments && planning.base !== planning.planned && (
                                <span className="text-muted-foreground"> (base {planning.base}, d'après son planning individuel)</span>
                            )}
                        </p>

                        {planning.modules.length > 1 || planning.modules.some((m) => m.exempted) ? (
                            <section className="grid gap-2">
                                <h3 className="text-sm font-medium">Modules</h3>
                                <ul className="divide-y rounded-lg border">
                                    {planning.modules.map((m) => (
                                        <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                                            <span>
                                                {m.title} <span className="text-muted-foreground">· {m.planned}</span>{' '}
                                                {m.exempted && <Badge variant="outline">Dispensé</Badge>}
                                            </span>
                                            {planning.can_edit &&
                                                (m.exempted && m.adjustment_id ? (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            write('delete', `/enrolments/${enrolmentId}/planning/adjustments/${m.adjustment_id}`)
                                                        }
                                                    >
                                                        Annuler la dispense
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            confirm(`Dispenser ${name} de tout le module « ${m.title} » ?`) &&
                                                            write('post', `/enrolments/${enrolmentId}/planning/exemptions`, { module_id: m.id })
                                                        }
                                                    >
                                                        Dispenser du module
                                                    </Button>
                                                ))}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ) : null}

                        <section className="grid gap-2">
                            <h3 className="text-sm font-medium">Ses séances ({live.length})</h3>
                            {live.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Aucune séance dans son planning.</p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {live.map((s) => {
                                        const locked = s.has_attendance || s.closed;
                                        return (
                                            <li key={s.id} className="flex flex-wrap items-center gap-2 p-2 text-sm">
                                                {planning.can_edit && s.kind === 'normal' && (
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Choisir la séance du ${formatDay(s.date)}`}
                                                        disabled={locked}
                                                        checked={selected.includes(s.id)}
                                                        onChange={() => toggle(s.id)}
                                                    />
                                                )}
                                                <span className="min-w-0 flex-1">
                                                    {formatDay(s.date)} · {s.start}–{s.end}
                                                    {s.own_hours && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            (séance {s.seance_start}–{s.seance_end})
                                                        </span>
                                                    )}
                                                    {s.module && <span className="text-muted-foreground"> · {s.module}</span>}
                                                    {s.session_label && <span className="text-muted-foreground"> · {s.session_label}</span>}{' '}
                                                    {s.own_hours && <Badge variant="secondary">Horaires individuels</Badge>}{' '}
                                                    {s.kind === 'added' && <Badge variant="secondary">Séance ajoutée</Badge>}{' '}
                                                    {s.has_attendance && <Badge variant="outline">Présence saisie</Badge>}{' '}
                                                    {s.closed && <Badge variant="outline">Mois clôturé</Badge>}
                                                </span>
                                                {planning.can_edit && !s.closed && (
                                                    <span className="flex gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            disabled={busy}
                                                            onClick={() => setHours({ seance: s, start: s.start, end: s.end, pause: '0' })}
                                                        >
                                                            Ses horaires
                                                        </Button>
                                                        {s.own_hours && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    write('delete', `/enrolments/${enrolmentId}/planning/seances/${s.id}/hours`)
                                                                }
                                                            >
                                                                Horaires de la séance
                                                            </Button>
                                                        )}
                                                        {s.kind === 'added' && s.adjustment_id && !s.has_attendance && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="text-destructive"
                                                                disabled={busy}
                                                                onClick={() =>
                                                                    write(
                                                                        'delete',
                                                                        `/enrolments/${enrolmentId}/planning/adjustments/${s.adjustment_id}`,
                                                                    )
                                                                }
                                                            >
                                                                Retirer
                                                            </Button>
                                                        )}
                                                    </span>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            {planning.can_edit && selected.length > 0 && (
                                <div className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                    <div className="grid gap-1">
                                        <Label htmlFor="exemption_reason">Motif (facultatif)</Label>
                                        <Input id="exemption_reason" value={reason} maxLength={300} onChange={(e) => setReason(e.target.value)} />
                                        <InputError message={errors.reason} />
                                    </div>
                                    <Button disabled={busy} onClick={exemptSelected}>
                                        {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                        Dispenser de {selected.length > 1 ? `${selected.length} séances` : 'cette séance'}
                                    </Button>
                                </div>
                            )}
                        </section>

                        {hours && (
                            <section className="grid gap-2 rounded-lg border p-3">
                                <h3 className="text-sm font-medium">
                                    Ses horaires le {formatDay(hours.seance.date)} (séance {hours.seance.seance_start}–{hours.seance.seance_end})
                                </h3>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    <div className="grid gap-1">
                                        <Label htmlFor="own_start">Arrivée</Label>
                                        <Input
                                            id="own_start"
                                            type="time"
                                            value={hours.start}
                                            onChange={(e) => setHours({ ...hours, start: e.target.value })}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="own_end">Départ</Label>
                                        <Input
                                            id="own_end"
                                            type="time"
                                            value={hours.end}
                                            onChange={(e) => setHours({ ...hours, end: e.target.value })}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="own_pause">Pause (minutes)</Label>
                                        <Input
                                            id="own_pause"
                                            inputMode="numeric"
                                            value={hours.pause}
                                            onChange={(e) => setHours({ ...hours, pause: e.target.value })}
                                        />
                                    </div>
                                </div>
                                <InputError message={errors.starts_at ?? errors.ends_at ?? errors.break_minutes} />
                                <div className="flex justify-end gap-2">
                                    <Button variant="outline" onClick={() => setHours(null)}>
                                        Retour
                                    </Button>
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            write(
                                                'put',
                                                `/enrolments/${enrolmentId}/planning/seances/${hours.seance.id}/hours`,
                                                { starts_at: hours.start, ends_at: hours.end, break_minutes: Number(hours.pause || 0) },
                                                () => setHours(null),
                                            )
                                        }
                                    >
                                        Enregistrer
                                    </Button>
                                </div>
                            </section>
                        )}

                        {planning.can_edit && (
                            <AddSeances
                                enrolmentId={enrolmentId}
                                busy={busy}
                                onAdd={(ids, why) =>
                                    write('post', `/enrolments/${enrolmentId}/planning/additions`, { seance_ids: ids, reason: why || null })
                                }
                            />
                        )}

                        {exempted.length > 0 && (
                            <section className="grid gap-2">
                                <h3 className="text-sm font-medium">Séances dont il est dispensé ({exempted.length})</h3>
                                <ul className="text-muted-foreground divide-y rounded-lg border">
                                    {exempted.map((s) => (
                                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-2 text-sm">
                                            <span>
                                                {formatDay(s.date)} · {s.start}–{s.end}
                                                {s.module && ` · ${s.module}`}
                                                {s.reason && ` · ${s.reason}`}
                                                {s.module_exempted && ' · module dispensé'}
                                            </span>
                                            {planning.can_edit && s.adjustment_id && !s.closed && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        write('delete', `/enrolments/${enrolmentId}/planning/adjustments/${s.adjustment_id}`)
                                                    }
                                                >
                                                    Annuler la dispense
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
