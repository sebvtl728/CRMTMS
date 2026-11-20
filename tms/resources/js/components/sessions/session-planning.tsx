import InputError from '@/components/input-error';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import type { Alert, PlacementRow, SeanceRow, SessionPlanning } from '@/components/sessions/session-modules';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay, type Option } from '@/lib/crm';
import { addDays, dayShade, isoWeekday, mondayOf, monthGrid, MONTHS, sendJson, WEEKDAYS_SHORT } from '@/lib/sessions';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CalendarPlus, ChevronLeft, ChevronRight, LoaderCircle, Pencil, Plus, Trash2, Wand2, X } from 'lucide-react';
import { FormEventHandler, useEffect, useMemo, useRef, useState } from 'react';

const NONE = '__none__';
const visit = { preserveScroll: true, preserveState: true };
const WEEKDAY_LABELS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

interface SessionDates {
    id: string;
    starts_on: string;
    ends_on: string;
}

function AlertList({ alerts }: { alerts: Alert[] }) {
    if (alerts.length === 0) return null;

    return (
        <ul className="grid gap-1 rounded-md border border-amber-500/40 bg-amber-500/5 p-2 text-xs" aria-label="Alertes">
            {alerts.map((a, i) => (
                <li key={i} className="flex gap-1">
                    <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0 text-amber-600" aria-hidden />
                    {a.message}
                </li>
            ))}
        </ul>
    );
}

function OptionSelect({
    id,
    value,
    options,
    onChange,
    none,
}: {
    id: string;
    value: string;
    options: (Option & { das_match?: boolean })[];
    onChange: (v: string) => void;
    none?: string;
}) {
    // Trainers: those of the DAS of the session first, under their own heading.
    const grouped = options.some((o) => o.das_match === true);
    const item = (o: Option) => (
        <SelectItem key={o.value} value={o.value}>
            {o.label}
        </SelectItem>
    );

    return (
        <Select value={value || (none ? NONE : undefined)} onValueChange={(v) => onChange(v === NONE ? '' : v)}>
            <SelectTrigger id={id}>
                <SelectValue placeholder="Choisissez" />
            </SelectTrigger>
            <SelectContent>
                {none && <SelectItem value={NONE}>{none}</SelectItem>}
                {grouped ? (
                    <>
                        <SelectGroup>
                            <SelectLabel>Formateurs du DAS de la session</SelectLabel>
                            {options.filter((o) => o.das_match === true).map(item)}
                        </SelectGroup>
                        <SelectGroup>
                            <SelectLabel>Autres formateurs</SelectLabel>
                            {options.filter((o) => o.das_match !== true).map(item)}
                        </SelectGroup>
                    </>
                ) : (
                    options.map(item)
                )}
            </SelectContent>
        </Select>
    );
}

// ---------------------------------------------------------------- calendar

type CalendarItem =
    | { kind: 'seance'; seance: SeanceRow }
    | { kind: 'interval'; title: string; detail?: string }
    | { kind: 'placement'; label: string };

/** Lines of a day in the month view (the last one becomes « +N autres » when there are more). */
const MONTH_ITEMS = 4;

/**
 * Calendar of the session (month or week): séances, interval modules on
 * working days, placements, weekends / holidays / closures shaded.
 */
function PlanningCalendar({
    session,
    planning,
    onOpen,
}: {
    session: SessionDates;
    planning: SessionPlanning;
    onOpen: ((s: SeanceRow) => void) | null;
}) {
    const firstDay = planning.seances[0]?.date ?? session.starts_on;
    const [view, setView] = useState<'month' | 'week'>('month');
    const [month, setMonth] = useState(firstDay.slice(0, 7));
    const [week, setWeek] = useState(mondayOf(firstDay));
    const { holidays, closures, interval_days, placement_days } = planning.calendar;

    const items = useMemo(() => {
        const byDay: Record<string, CalendarItem[]> = {};
        const push = (day: string, item: CalendarItem) => (byDay[day] ??= []).push(item);
        planning.seances.forEach((s) => push(s.date, { kind: 'seance', seance: s }));
        planning.modules.forEach((m) => (interval_days[m.id] ?? []).forEach((d) => push(d, { kind: 'interval', title: m.title })));
        planning.placements.forEach((p) => (placement_days[p.id] ?? []).forEach((d) => push(d, { kind: 'placement', label: p.label })));

        return byDay;
    }, [planning, interval_days, placement_days]);

    const shift = (n: number) => {
        if (view === 'month') {
            const [y, m] = month.split('-').map(Number);
            const date = new Date(Date.UTC(y, m - 1 + n, 1));
            setMonth(`${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`);
        } else {
            setWeek(addDays(week, 7 * n));
        }
    };
    const [y, m] = month.split('-').map(Number);
    const title = view === 'month' ? `${MONTHS[m - 1]} ${y}` : `Semaine du ${formatDay(week)} au ${formatDay(addDays(week, 6))}`;

    const cell = (day: string, inMonth: boolean, tall: boolean) => {
        const shade = dayShade(day, holidays, closures);
        const inSession = day >= session.starts_on && day <= session.ends_on;
        const all = items[day] ?? [];
        // Month view: the interval modules of a day on one line, at most MONTH_ITEMS lines, then « +N ».
        const intervals = all.filter((i): i is Extract<CalendarItem, { kind: 'interval' }> => i.kind === 'interval');
        const grouped: CalendarItem[] =
            !tall && intervals.length > 1
                ? [
                      ...all.filter((i) => i.kind !== 'interval'),
                      { kind: 'interval', title: `${intervals.length} modules en intervalle`, detail: intervals.map((i) => i.title).join('\n') },
                  ]
                : all;
        const limit = tall ? grouped.length : MONTH_ITEMS;
        const list = grouped.length > limit ? grouped.slice(0, limit - 1) : grouped;
        const hidden = grouped.length - list.length;

        return (
            <td
                key={day}
                className={`overflow-hidden border-l p-1 align-top first:border-l-0 ${tall ? 'h-48' : 'h-28'} ${shade.off || !inMonth ? 'bg-muted/40' : ''} ${inMonth ? '' : 'text-muted-foreground'}`}
            >
                <div className="mb-1 flex min-w-0 items-start justify-between gap-1 text-xs">
                    <span className={`shrink-0 ${inSession ? 'font-semibold' : ''}`}>
                        {tall ? `${WEEKDAYS_SHORT[isoWeekday(day) - 1]} ${formatDay(day)}` : Number(day.slice(8))}
                    </span>
                    {shade.label && (
                        <span className="text-muted-foreground min-w-0 truncate text-[10px] italic" title={shade.label}>
                            {shade.label}
                        </span>
                    )}
                </div>
                <ul className="grid min-w-0 gap-1">
                    {list.map((item, i) => (
                        <li key={i} className="min-w-0">
                            {item.kind === 'seance' ? (
                                <button
                                    type="button"
                                    disabled={!onOpen}
                                    onClick={() => onOpen?.(item.seance)}
                                    title={[
                                        `${item.seance.start}–${item.seance.end} · ${item.seance.module}`,
                                        item.seance.trainer?.label,
                                        item.seance.room?.name ?? item.seance.place_text,
                                        item.seance.status === 'cancelled' ? `Annulée : ${item.seance.cancel_reason ?? ''}` : null,
                                        ...item.seance.alerts.map((a) => a.message),
                                    ]
                                        .filter(Boolean)
                                        .join('\n')}
                                    className={`bg-primary/10 hover:bg-primary/20 block w-full truncate rounded border-l-4 border-l-blue-500 px-1 text-left text-xs ${item.seance.status === 'cancelled' ? 'line-through opacity-60' : ''}`}
                                >
                                    {item.seance.alerts.length > 0 && (
                                        <AlertTriangle className="mr-0.5 inline h-3 w-3 text-amber-600" aria-label="Alertes" />
                                    )}
                                    {item.seance.start} {tall ? `– ${item.seance.end} · ${item.seance.module}` : item.seance.module}
                                    {tall && item.seance.trainer && <span className="text-muted-foreground"> · {item.seance.trainer.label}</span>}
                                </button>
                            ) : item.kind === 'interval' ? (
                                <span
                                    className="bg-muted block truncate rounded border-l-4 border-l-slate-400 px-1 text-xs"
                                    title={item.detail ?? `${item.title} (intervalle de dates)`}
                                >
                                    {item.title}
                                </span>
                            ) : (
                                <span
                                    className="block truncate rounded border-l-4 border-l-emerald-600 bg-emerald-500/10 px-1 text-xs"
                                    title={`Stage : ${item.label}`}
                                >
                                    Stage : {item.label}
                                </span>
                            )}
                        </li>
                    ))}
                    {hidden > 0 && (
                        <li>
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground text-xs underline-offset-2 hover:underline"
                                onClick={() => {
                                    setWeek(mondayOf(day));
                                    setView('week');
                                }}
                            >
                                + {hidden} autre{hidden > 1 ? 's' : ''}
                            </button>
                        </li>
                    )}
                </ul>
            </td>
        );
    };

    return (
        <section aria-label="Calendrier de la session" className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" aria-label="Précédent" onClick={() => shift(-1)}>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <h2 className="min-w-48 text-center text-sm font-semibold">{title}</h2>
                    <Button variant="outline" size="icon" aria-label="Suivant" onClick={() => shift(1)}>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
                <div className="flex gap-1" role="group" aria-label="Vue">
                    <Button size="sm" variant={view === 'month' ? 'default' : 'outline'} onClick={() => setView('month')}>
                        Mois
                    </Button>
                    <Button
                        size="sm"
                        variant={view === 'week' ? 'default' : 'outline'}
                        onClick={() => {
                            setWeek(mondayOf(month === firstDay.slice(0, 7) ? firstDay : `${month}-01`));
                            setView('week');
                        }}
                    >
                        Semaine
                    </Button>
                </div>
            </div>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[42rem] table-fixed text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            {WEEKDAYS_SHORT.map((d) => (
                                <th key={d} className="px-2 py-1 text-left font-medium">
                                    {d}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {view === 'month' ? (
                            monthGrid(month).map((w) => (
                                <tr key={w[0].day} className="border-t">
                                    {w.map(({ day, inMonth }) => cell(day, inMonth, false))}
                                </tr>
                            ))
                        ) : (
                            <tr className="border-t">{Array.from({ length: 7 }, (_, i) => cell(addDays(week, i), true, true))}</tr>
                        )}
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground text-xs">
                Bleu : séances · gris : modules en intervalle (jours ouvrés) · vert : stages (jours ouvrés) · fond grisé : week-end, jour férié ou
                fermeture.
            </p>
        </section>
    );
}

// ---------------------------------------------------------------- séance form

function SeanceDialog({
    session,
    seance,
    planning,
    onClose,
}: {
    session: SessionDates;
    seance: SeanceRow | null;
    planning: SessionPlanning;
    onClose: () => void;
}) {
    const options = planning.options;
    const { data, setData, post, put, processing, errors } = useForm({
        session_module_id: seance?.module_id ?? planning.seance_modules[0]?.value ?? '',
        date: seance?.date ?? session.starts_on,
        starts_at: seance?.start ?? '09:00',
        ends_at: seance?.end ?? '12:30',
        break_minutes: String(seance?.break_minutes ?? 0),
        trainer_id: seance?.trainer?.id ?? '',
        room_id: seance?.room?.id ?? '',
        place_text: seance?.place_text ?? '',
        note: seance?.note ?? '',
    });
    const [alerts, setAlerts] = useState<Alert[]>(seance?.alerts ?? []);

    // Alerts of the séance being typed: they warn, they never block.
    useEffect(() => {
        if (!data.session_module_id || !data.date || !data.starts_at || !data.ends_at) return;
        const timer = setTimeout(() => {
            sendJson<{ alerts: Alert[] }>('POST', route('sessions.seances.check', session.id), { ...data, seance_id: seance?.id ?? null }).then(
                (answer) => answer.ok && answer.data && setAlerts(answer.data.alerts),
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [data, session.id, seance?.id]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { ...visit, onSuccess: onClose };
        if (seance) {
            put(route('sessions.seances.update', [session.id, seance.id]), options);
        } else {
            post(route('sessions.seances.store', session.id), options);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{seance ? 'Modifier la séance' : 'Ajouter une séance'}</DialogTitle>
                        <DialogDescription>
                            Heures de Paris. Les alertes (formateur, salle, stage, férié…) préviennent sans bloquer l'enregistrement.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="seance_module">Module *</Label>
                        <OptionSelect
                            id="seance_module"
                            value={data.session_module_id}
                            options={planning.seance_modules}
                            onChange={(v) => setData('session_module_id', v)}
                        />
                        <InputError message={errors.session_module_id} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="seance_date">Date *</Label>
                            <Input
                                id="seance_date"
                                type="date"
                                min={session.starts_on}
                                max={session.ends_on}
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                            />
                            <InputError message={errors.date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="seance_start">Début *</Label>
                            <Input id="seance_start" type="time" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                            <InputError message={errors.starts_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="seance_end">Fin *</Label>
                            <Input id="seance_end" type="time" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                            <InputError message={errors.ends_at} />
                        </div>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="seance_break">Pause (min)</Label>
                            <Input
                                id="seance_break"
                                inputMode="numeric"
                                value={data.break_minutes}
                                onChange={(e) => setData('break_minutes', e.target.value)}
                            />
                            <InputError message={errors.break_minutes} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="seance_trainer">Formateur</Label>
                            <OptionSelect
                                id="seance_trainer"
                                value={data.trainer_id}
                                options={withCurrent(
                                    options?.trainers ?? [],
                                    seance?.trainer ? { value: seance.trainer.id, label: seance.trainer.label } : null,
                                )}
                                onChange={(v) => setData('trainer_id', v)}
                                none="Aucun"
                            />
                            <InputError message={errors.trainer_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="seance_room">Salle</Label>
                            <OptionSelect
                                id="seance_room"
                                value={data.room_id}
                                options={withCurrent(options?.rooms ?? [], seance?.room ? { value: seance.room.id, label: seance.room.name } : null)}
                                onChange={(v) => setData('room_id', v)}
                                none="Aucune"
                            />
                            <InputError message={errors.room_id} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="seance_place">Lieu (hors centre, distanciel)</Label>
                        <Input id="seance_place" maxLength={300} value={data.place_text} onChange={(e) => setData('place_text', e.target.value)} />
                        <InputError message={errors.place_text} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="seance_note">Note interne</Label>
                        <Input id="seance_note" maxLength={1000} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                    <AlertList alerts={alerts} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** The current value of a select kept among the choices (a deactivated trainer or room). */
function withCurrent<T extends Option>(options: T[], current: Option | null): (T | Option)[] {
    return current && !options.some((o) => o.value === current.value) ? [...options, current] : options;
}

function CancelSeanceDialog({ sessionId, seance, onClose }: { sessionId: string; seance: SeanceRow; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sessions.seances.cancel', [sessionId, seance.id]), { ...visit, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Annuler la séance du {formatDay(seance.date)}</DialogTitle>
                        <DialogDescription>La séance reste visible, barrée. Le motif est obligatoire.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="seance_cancel_reason">Motif *</Label>
                        <Input id="seance_cancel_reason" maxLength={300} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Annuler la séance
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ---------------------------------------------------------------- assistant

interface Preview {
    seances: { date: string; start: string; end: string; minutes: number; skipped_reason: string | null }[];
    count: number;
    total: string;
    planned: string;
    existing: string;
    alerts: Alert[];
    warning: string | null;
    estimated_end: string | null;
    reached: boolean;
}

type Slot = { start: string; end: string; break_minutes: string };

/** « Générer les séances »: days, slots, period, trainer, room; preview, then create. */
function GeneratorDialog({ session, planning, onClose }: { session: SessionDates; planning: SessionPlanning; onClose: () => void }) {
    const options = planning.options;
    const [moduleId, setModuleId] = useState(planning.seance_modules[0]?.value ?? '');
    const module = planning.modules.find((m) => m.id === moduleId);
    const [weekdays, setWeekdays] = useState<number[]>(options?.weekdays ?? [1, 2, 3, 4, 5]);
    const [slots, setSlots] = useState<Slot[]>(
        (options?.slots ?? [{ start: '09:00', end: '12:30', break_minutes: 0 }]).map((s) => ({ ...s, break_minutes: String(s.break_minutes) })),
    );
    const [from, setFrom] = useState(module?.starts_on ?? session.starts_on);
    const [to, setTo] = useState(module?.ends_on ?? session.ends_on);
    // « hours »: the end is computed from the planned hours of the module (estimated); « date »: chosen.
    const [until, setUntil] = useState<'hours' | 'date'>(module?.planned_minutes != null ? 'hours' : 'date');
    const canEstimate = module?.planned_minutes != null;
    const byHours = until === 'hours' && canEstimate;
    const [trainer, setTrainer] = useState('');
    const [room, setRoom] = useState('');
    const [place, setPlace] = useState('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const payload = () => ({
        weekdays,
        slots,
        from,
        to: byHours ? '' : to,
        until: byHours ? 'hours' : 'date',
        trainer_id: trainer,
        room_id: room,
        place_text: place,
    });

    const latest = useRef(0);
    const runPreview = async () => {
        const call = ++latest.current;
        setBusy(true);
        const answer = await sendJson<Preview>('POST', route('sessions.seances.preview', [session.id, moduleId]), payload());
        // Only the answer to the latest input counts.
        if (call !== latest.current) return;
        setBusy(false);
        setErrors(answer.errors);
        setMessage(answer.ok || answer.status === 422 ? null : answer.message);
        setPreview(answer.data);
    };

    // Any change makes the preview stale; computed again shortly after (the estimated end follows the input).
    useEffect(() => {
        setPreview(null);
        if (!moduleId || weekdays.length === 0 || slots.some((sl) => !sl.start || !sl.end) || !from) return;
        const timer = setTimeout(() => void runPreview(), 500);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [moduleId, weekdays, slots, from, to, until, trainer, room, place]);

    const generate = () => {
        setBusy(true);
        router.post(route('sessions.seances.generate', [session.id, moduleId]), payload(), {
            ...visit,
            onSuccess: onClose,
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setBusy(false),
        });
    };

    const toggleDay = (d: number, on: boolean) => setWeekdays((w) => (on ? [...w, d].sort() : w.filter((x) => x !== d)));
    const setSlot = (i: number, patch: Partial<Slot>) => setSlots((s) => s.map((slot, j) => (j === i ? { ...slot, ...patch } : slot)));
    const skipped = preview?.seances.filter((s) => s.skipped_reason !== null) ?? [];

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Générer les séances</DialogTitle>
                    <DialogDescription>
                        Choisissez le début : la fin est estimée d'après les heures prévues du module (heures déjà planifiées déduites). Jours fériés,
                        fermetures et dates communes des stages sont exclus ; une séance existante (même module, jour et heure de début) n'est jamais
                        recréée ni modifiée.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="gen_module">Module *</Label>
                        <OptionSelect id="gen_module" value={moduleId} options={planning.seance_modules} onChange={setModuleId} />
                        {module && (
                            <p className="text-muted-foreground text-xs">
                                Heures prévues : {module.planned} · déjà planifiées : {module.scheduled}
                            </p>
                        )}
                    </div>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">Jours de la semaine *</legend>
                        <div className="flex flex-wrap gap-3">
                            {WEEKDAY_LABELS.map((label, i) => (
                                <label key={label} className="flex items-center gap-1.5 text-sm">
                                    <Checkbox checked={weekdays.includes(i + 1)} onCheckedChange={(v) => toggleDay(i + 1, v === true)} />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.weekdays ?? errors['weekdays.0']} />
                    </fieldset>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">Créneaux *</legend>
                        {slots.map((slot, i) => (
                            <div key={i} className="grid grid-cols-[1fr_1fr_1fr_auto] items-end gap-2">
                                <div className="grid gap-1">
                                    <Label htmlFor={`slot_start_${i}`} className="text-xs">
                                        Début
                                    </Label>
                                    <Input
                                        id={`slot_start_${i}`}
                                        type="time"
                                        value={slot.start}
                                        onChange={(e) => setSlot(i, { start: e.target.value })}
                                    />
                                    <InputError message={errors[`slots.${i}.start`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`slot_end_${i}`} className="text-xs">
                                        Fin
                                    </Label>
                                    <Input id={`slot_end_${i}`} type="time" value={slot.end} onChange={(e) => setSlot(i, { end: e.target.value })} />
                                    <InputError message={errors[`slots.${i}.end`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`slot_break_${i}`} className="text-xs">
                                        Pause (min)
                                    </Label>
                                    <Input
                                        id={`slot_break_${i}`}
                                        inputMode="numeric"
                                        value={slot.break_minutes}
                                        onChange={(e) => setSlot(i, { break_minutes: e.target.value })}
                                    />
                                    <InputError message={errors[`slots.${i}.break_minutes`]} />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Retirer ce créneau"
                                    disabled={slots.length === 1}
                                    onClick={() => setSlots((s) => s.filter((_, j) => j !== i))}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                        ))}
                        <InputError message={errors.slots} />
                        {slots.length < 6 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="justify-self-start"
                                onClick={() => setSlots((s) => [...s, { start: '', end: '', break_minutes: '0' }])}
                            >
                                <Plus className="h-4 w-4" />
                                Ajouter un créneau
                            </Button>
                        )}
                    </fieldset>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">Fin de la période</legend>
                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-1.5">
                                <input type="radio" name="gen_until" checked={byHours} disabled={!canEstimate} onChange={() => setUntil('hours')} />
                                Calculée d'après les heures prévues du module
                            </label>
                            <label className="flex items-center gap-1.5">
                                <input type="radio" name="gen_until" checked={!byHours} onChange={() => setUntil('date')} />
                                Date de fin choisie
                            </label>
                        </div>
                        {!canEstimate && (
                            <p className="text-muted-foreground text-xs">Ce module n'a pas d'heures prévues : choisissez la date de fin.</p>
                        )}
                        <InputError message={errors.until} />
                    </fieldset>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="gen_from">Du *</Label>
                            <Input
                                id="gen_from"
                                type="date"
                                min={session.starts_on}
                                max={session.ends_on}
                                value={from}
                                onChange={(e) => setFrom(e.target.value)}
                            />
                            <InputError message={errors.from} />
                        </div>
                        {byHours ? (
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">Fin estimée</span>
                                <p className="flex h-9 items-center text-sm" aria-live="polite" data-testid="estimated-end">
                                    {busy && !preview ? (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    ) : preview?.estimated_end ? (
                                        <span>
                                            <strong>{formatDay(preview.estimated_end)}</strong>
                                            {!preview.reached && (
                                                <span className="text-amber-700 dark:text-amber-400">
                                                    {' '}
                                                    · heures prévues non atteintes avant la fin de la session
                                                </span>
                                            )}
                                        </span>
                                    ) : preview ? (
                                        <span className="text-muted-foreground">Les heures prévues sont déjà planifiées.</span>
                                    ) : (
                                        <span className="text-muted-foreground">-</span>
                                    )}
                                </p>
                                <InputError message={errors.to} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="gen_to">Au *</Label>
                                <Input id="gen_to" type="date" min={from} max={session.ends_on} value={to} onChange={(e) => setTo(e.target.value)} />
                                <InputError message={errors.to} />
                            </div>
                        )}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="gen_trainer">Formateur</Label>
                            <OptionSelect id="gen_trainer" value={trainer} options={options?.trainers ?? []} onChange={setTrainer} none="Aucun" />
                            <InputError message={errors.trainer_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="gen_room">Salle</Label>
                            <OptionSelect id="gen_room" value={room} options={options?.rooms ?? []} onChange={setRoom} none="Aucune" />
                            <InputError message={errors.room_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="gen_place">Lieu</Label>
                            <Input id="gen_place" maxLength={300} value={place} onChange={(e) => setPlace(e.target.value)} />
                            <InputError message={errors.place_text} />
                        </div>
                    </div>
                    {message && <p className="text-destructive text-sm">{message}</p>}

                    {preview && (
                        <section aria-label="Aperçu" className="grid gap-2 rounded-xl border p-3 text-sm">
                            <p className="font-medium">
                                {preview.count} séance{preview.count > 1 ? 's' : ''} à créer · {preview.total}
                                <span className="text-muted-foreground font-normal">
                                    {' '}
                                    (déjà planifiées : {preview.existing} · heures prévues du module : {preview.planned})
                                </span>
                            </p>
                            {preview.warning && <p className="text-amber-700 dark:text-amber-400">{preview.warning}</p>}
                            <AlertList alerts={preview.alerts} />
                            {skipped.length > 0 && (
                                <details>
                                    <summary className="cursor-pointer">
                                        {skipped.length} créneau{skipped.length > 1 ? 'x' : ''} exclu{skipped.length > 1 ? 's' : ''}
                                    </summary>
                                    <ul className="text-muted-foreground mt-1 grid gap-0.5 text-xs">
                                        {skipped.map((s, i) => (
                                            <li key={i}>
                                                {formatDay(s.date)} {s.start}–{s.end} : {s.skipped_reason}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </section>
                    )}
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Retour
                    </Button>
                    <Button type="button" variant="outline" disabled={busy || !moduleId} onClick={runPreview}>
                        {busy && !preview && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Aperçu
                    </Button>
                    <Button type="button" disabled={busy || !preview || preview.count === 0} onClick={generate}>
                        {busy && preview && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Créer {preview ? `${preview.count} séance${preview.count > 1 ? 's' : ''}` : 'les séances'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---------------------------------------------------------------- séance list

function SeanceList({
    sessionId,
    planning,
    onEdit,
    onCancel,
}: {
    sessionId: string;
    planning: SessionPlanning;
    onEdit: (s: SeanceRow) => void;
    onCancel: (s: SeanceRow) => void;
}) {
    const destroy = (s: SeanceRow) => {
        if (window.confirm(`Supprimer la séance du ${formatDay(s.date)} (${s.start}–${s.end}) ?`)) {
            router.delete(route('sessions.seances.destroy', [sessionId, s.id]), visit);
        }
    };

    const [selected, setSelected] = useState<string[]>([]);
    const [batch, setBatch] = useState<'update' | 'cancel' | null>(null);
    const plannedIds = planning.seances.filter((s) => s.status === 'planned').map((s) => s.id);
    // Only planned séances still on the page stay ticked (after a reload).
    const ticked = selected.filter((id) => plannedIds.includes(id));
    const toggle = (id: string, on: boolean) => setSelected((cur) => (on ? [...cur.filter((x) => x !== id), id] : cur.filter((x) => x !== id)));
    const allTicked = plannedIds.length > 0 && ticked.length === plannedIds.length;

    if (planning.seances.length === 0) {
        return <p className="text-muted-foreground text-sm">Aucune séance.</p>;
    }

    return (
        <div className="grid gap-2">
            {planning.can_seances && ticked.length > 0 && (
                <div className="bg-muted/40 flex flex-wrap items-center gap-2 rounded-xl border p-2 text-sm" role="toolbar" aria-label="Sélection">
                    <span className="font-medium">
                        {ticked.length} séance{ticked.length > 1 ? 's' : ''} cochée{ticked.length > 1 ? 's' : ''}
                    </span>
                    <Button type="button" size="sm" onClick={() => setBatch('update')}>
                        <Pencil className="h-4 w-4" />
                        Modifier la sélection
                    </Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => setBatch('cancel')}>
                        Annuler la sélection
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="text-destructive"
                        onClick={() => {
                            const n = ticked.length;
                            if (
                                window.confirm(
                                    `Supprimer ${n} séance${n > 1 ? 's' : ''} ? À utiliser pour des séances planifiées par erreur ; une séance avec des présences ne se supprime pas (annulez-la).`,
                                )
                            ) {
                                router.post(
                                    route('sessions.seances.batch-delete', sessionId),
                                    { seance_ids: ticked },
                                    { ...visit, onSuccess: () => setSelected([]) },
                                );
                            }
                        }}
                    >
                        <Trash2 className="h-4 w-4" />
                        Supprimer la sélection
                    </Button>
                    <Button type="button" size="sm" variant="ghost" onClick={() => setSelected([])}>
                        Tout décocher
                    </Button>
                </div>
            )}
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            {planning.can_seances && (
                                <th className="w-8 px-3 py-2">
                                    <Checkbox
                                        aria-label="Cocher toutes les séances prévues"
                                        checked={allTicked}
                                        disabled={plannedIds.length === 0}
                                        onCheckedChange={(v) => setSelected(v === true ? plannedIds : [])}
                                    />
                                </th>
                            )}
                            <th className="px-3 py-2 font-medium">Date</th>
                            <th className="px-3 py-2 font-medium">Horaires</th>
                            <th className="px-3 py-2 font-medium">Module</th>
                            <th className="px-3 py-2 font-medium">Durée</th>
                            <th className="px-3 py-2 font-medium">Formateur</th>
                            <th className="px-3 py-2 font-medium">Salle / lieu</th>
                            {planning.can_seances && <th className="px-3 py-2 font-medium">Actions</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {planning.seances.map((s) => (
                            <tr key={s.id} className={`border-t align-top ${s.status === 'cancelled' ? 'text-muted-foreground' : ''}`}>
                                {planning.can_seances && (
                                    <td className="px-3 py-2">
                                        {s.status === 'planned' && (
                                            <Checkbox
                                                aria-label={`Cocher la séance du ${formatDay(s.date)} (${s.start})`}
                                                checked={ticked.includes(s.id)}
                                                onCheckedChange={(v) => toggle(s.id, v === true)}
                                            />
                                        )}
                                    </td>
                                )}
                                <td className="px-3 py-2 whitespace-nowrap">
                                    <span className={s.status === 'cancelled' ? 'line-through' : ''}>{formatDay(s.date)}</span>
                                    {s.status === 'cancelled' && (
                                        <Badge variant="destructive" className="ml-2" title={s.cancel_reason ?? undefined}>
                                            Annulée
                                        </Badge>
                                    )}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {s.start}–{s.end}
                                    {s.break_minutes > 0 && <span className="text-muted-foreground text-xs"> (pause {s.break_minutes} min)</span>}
                                </td>
                                <td className="px-3 py-2">
                                    {s.module}
                                    {s.alerts.length > 0 && (
                                        <div className="mt-1">
                                            <AlertList alerts={s.alerts} />
                                        </div>
                                    )}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">{s.duration}</td>
                                <td className="px-3 py-2">{s.trainer?.label ?? '-'}</td>
                                <td className="px-3 py-2">{s.room?.name ?? s.place_text ?? '-'}</td>
                                {planning.can_seances && (
                                    <td className="px-3 py-2">
                                        {s.status === 'planned' && (
                                            <div className="flex flex-wrap gap-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => onEdit(s)}
                                                    aria-label={`Modifier la séance du ${formatDay(s.date)}`}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" onClick={() => onCancel(s)}>
                                                    Annuler
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    onClick={() => destroy(s)}
                                                    aria-label={`Supprimer la séance du ${formatDay(s.date)}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {batch === 'update' && (
                <BatchSeanceDialog
                    sessionId={sessionId}
                    planning={planning}
                    ids={ticked}
                    onClose={() => setBatch(null)}
                    onDone={() => {
                        setBatch(null);
                        setSelected([]);
                    }}
                />
            )}
            {batch === 'cancel' && (
                <BatchCancelDialog
                    sessionId={sessionId}
                    ids={ticked}
                    onClose={() => setBatch(null)}
                    onDone={() => {
                        setBatch(null);
                        setSelected([]);
                    }}
                />
            )}
        </div>
    );
}

/** « Modifier la sélection »: only the ticked groups change (trainer; room and place; hours, the day stays). */
function BatchSeanceDialog({
    sessionId,
    planning,
    ids,
    onClose,
    onDone,
}: {
    sessionId: string;
    planning: SessionPlanning;
    ids: string[];
    onClose: () => void;
    onDone: () => void;
}) {
    // One line per current slot of the ticked séances (morning, afternoon…), in order.
    const currentSlots = useMemo(() => {
        const found = new Map<string, { current: string; start: string; end: string; break_minutes: number; count: number }>();
        planning.seances
            .filter((s) => ids.includes(s.id))
            .forEach((s) => {
                const key = `${s.start}-${s.end}`;
                const slot = found.get(key) ?? { current: key, start: s.start, end: s.end, break_minutes: s.break_minutes, count: 0 };
                slot.count++;
                found.set(key, slot);
            });

        return [...found.values()].sort((a, b) => a.start.localeCompare(b.start));
    }, [planning.seances, ids]);
    const { data, setData, post, processing, errors } = useForm({
        seance_ids: ids,
        apply_trainer: false as boolean,
        trainer_id: '',
        apply_place: false as boolean,
        room_id: '',
        place_text: '',
        apply_hours: false as boolean,
        hours: currentSlots.map((c) => ({ current: c.current, starts_at: c.start, ends_at: c.end, break_minutes: String(c.break_minutes) })),
    });
    const setHour = (i: number, patch: Partial<{ starts_at: string; ends_at: string; break_minutes: string }>) =>
        setData(
            'hours',
            data.hours.map((h, j) => (j === i ? { ...h, ...patch } : h)),
        );
    const errs = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sessions.seances.batch', sessionId), { ...visit, onSuccess: onDone });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            Modifier {ids.length} séance{ids.length > 1 ? 's' : ''}
                        </DialogTitle>
                        <DialogDescription>
                            Cochez ce qui change ; le reste de chaque séance (jour, module, note) est conservé. Si une séance est refusée, aucune
                            n'est modifiée.
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-2 rounded-lg border p-3">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <Checkbox checked={data.apply_trainer} onCheckedChange={(v) => setData('apply_trainer', v === true)} />
                            Changer le formateur
                        </label>
                        {data.apply_trainer && (
                            <div className="grid gap-2">
                                <Label htmlFor="batch_trainer">Formateur</Label>
                                <OptionSelect
                                    id="batch_trainer"
                                    value={data.trainer_id}
                                    options={planning.options?.trainers ?? []}
                                    onChange={(v) => setData('trainer_id', v)}
                                    none="Aucun (retirer le formateur)"
                                />
                                <InputError message={errors.trainer_id} />
                            </div>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-2 rounded-lg border p-3">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <Checkbox checked={data.apply_place} onCheckedChange={(v) => setData('apply_place', v === true)} />
                            Changer la salle ou le lieu
                        </label>
                        {data.apply_place && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="batch_room">Salle</Label>
                                    <OptionSelect
                                        id="batch_room"
                                        value={data.room_id}
                                        options={planning.options?.rooms ?? []}
                                        onChange={(v) => setData('room_id', v)}
                                        none="Aucune"
                                    />
                                    <InputError message={errors.room_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="batch_place">Lieu (hors centre, distanciel)</Label>
                                    <Input
                                        id="batch_place"
                                        maxLength={300}
                                        value={data.place_text}
                                        onChange={(e) => setData('place_text', e.target.value)}
                                    />
                                    <InputError message={errors.place_text} />
                                </div>
                            </div>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-2 rounded-lg border p-3">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <Checkbox checked={data.apply_hours} onCheckedChange={(v) => setData('apply_hours', v === true)} />
                            Changer les horaires (le jour ne change pas)
                        </label>
                        {data.apply_hours && (
                            <div className="grid gap-3">
                                <p className="text-muted-foreground text-xs">
                                    Une ligne par créneau actuel des séances cochées : chaque séance prend les nouveaux horaires de son créneau.
                                </p>
                                {data.hours.map((h, i) => (
                                    <div key={h.current} className="grid gap-2 rounded-md border p-2">
                                        <span className="text-sm font-medium">
                                            Séances de {currentSlots[i].start} à {currentSlots[i].end}
                                            <span className="text-muted-foreground font-normal"> ({currentSlots[i].count})</span>
                                        </span>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="grid gap-1">
                                                <Label htmlFor={`batch_start_${i}`} className="text-xs">
                                                    Nouveau début *
                                                </Label>
                                                <Input
                                                    id={`batch_start_${i}`}
                                                    type="time"
                                                    value={h.starts_at}
                                                    onChange={(e) => setHour(i, { starts_at: e.target.value })}
                                                />
                                                <InputError message={errs[`hours.${i}.starts_at`]} />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor={`batch_end_${i}`} className="text-xs">
                                                    Nouvelle fin *
                                                </Label>
                                                <Input
                                                    id={`batch_end_${i}`}
                                                    type="time"
                                                    value={h.ends_at}
                                                    onChange={(e) => setHour(i, { ends_at: e.target.value })}
                                                />
                                                <InputError message={errs[`hours.${i}.ends_at`]} />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor={`batch_break_${i}`} className="text-xs">
                                                    Pause (min)
                                                </Label>
                                                <Input
                                                    id={`batch_break_${i}`}
                                                    inputMode="numeric"
                                                    value={h.break_minutes}
                                                    onChange={(e) => setHour(i, { break_minutes: e.target.value })}
                                                />
                                                <InputError message={errs[`hours.${i}.break_minutes`]} />
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </fieldset>

                    <InputError message={errs.batch ?? errs.seance_ids} />
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing || !(data.apply_trainer || data.apply_place || data.apply_hours)}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Appliquer aux {ids.length} séance{ids.length > 1 ? 's' : ''}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function BatchCancelDialog({ sessionId, ids, onClose, onDone }: { sessionId: string; ids: string[]; onClose: () => void; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ seance_ids: ids, reason: '' });
    const errs = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sessions.seances.batch-cancel', sessionId), { ...visit, onSuccess: onDone });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            Annuler {ids.length} séance{ids.length > 1 ? 's' : ''}
                        </DialogTitle>
                        <DialogDescription>Les séances restent visibles, barrées. Le motif, obligatoire, vaut pour toutes.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="batch_cancel_reason">Motif *</Label>
                        <Input id="batch_cancel_reason" maxLength={300} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        <InputError message={errors.reason ?? errs.batch ?? errs.seance_ids} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Annuler les {ids.length} séance{ids.length > 1 ? 's' : ''}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ---------------------------------------------------------------- placements

function PlacementDialog({ session, period, onClose }: { session: SessionDates; period: PlacementRow | null; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        starts_on: period?.starts_on ?? '',
        ends_on: period?.ends_on ?? '',
        label: period?.label ?? 'Stage en entreprise',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { ...visit, onSuccess: onClose };
        if (period) {
            put(route('sessions.placements.update', [session.id, period.id]), options);
        } else {
            post(route('sessions.placements.store', session.id), options);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{period ? 'Modifier la période de stage' : 'Ajouter une période de stage'}</DialogTitle>
                        <DialogDescription>
                            Dates communes de la session ; chaque stagiaire peut avoir ses propres dates. Les stages ne comptent dans aucune heure.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="placement_label">Intitulé *</Label>
                        <Input id="placement_label" maxLength={120} value={data.label} onChange={(e) => setData('label', e.target.value)} />
                        <InputError message={errors.label} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="placement_starts">Début *</Label>
                            <Input
                                id="placement_starts"
                                type="date"
                                min={session.starts_on}
                                max={session.ends_on}
                                value={data.starts_on}
                                onChange={(e) => setData('starts_on', e.target.value)}
                            />
                            <InputError message={errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="placement_ends">Fin *</Label>
                            <Input
                                id="placement_ends"
                                type="date"
                                min={data.starts_on || session.starts_on}
                                max={session.ends_on}
                                value={data.ends_on}
                                onChange={(e) => setData('ends_on', e.target.value)}
                            />
                            <InputError message={errors.ends_on} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function HostDialog({
    sessionId,
    period,
    trainee,
    onClose,
}: {
    sessionId: string;
    period: PlacementRow;
    trainee: PlacementRow['trainees'][number];
    onClose: () => void;
}) {
    const [host, setHost] = useState<Picked | null>(trainee.host ? { id: trainee.host.id, label: trainee.host.label } : null);
    const { data, setData, put, processing, errors, transform } = useForm({
        starts_on: trainee.own_dates ? trainee.from : '',
        ends_on: trainee.own_dates ? trainee.to : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, organisation_id: host?.id ?? '' }));
        put(route('sessions.placements.hosts.update', [sessionId, period.id, trainee.enrolment_id]), { ...visit, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Stage de {trainee.trainee}</DialogTitle>
                        <DialogDescription>
                            « {period.label} », du {formatDay(period.starts_on)} au {formatDay(period.ends_on)}. Sans dates propres, le stagiaire suit
                            les dates communes.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="host_organisation">Entreprise d'accueil</Label>
                        <FichePicker
                            id="host_organisation"
                            kind="organisation"
                            value={host}
                            onPick={setHost}
                            placeholder="Rechercher une entreprise"
                            searchUrl={route('sessions.placements.organisations', sessionId)}
                        />
                        <InputError message={(errors as Record<string, string>).organisation_id} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="host_starts">Début propre</Label>
                            <Input id="host_starts" type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                            <InputError message={errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="host_ends">Fin propre</Label>
                            <Input
                                id="host_ends"
                                type="date"
                                min={data.starts_on}
                                value={data.ends_on}
                                onChange={(e) => setData('ends_on', e.target.value)}
                            />
                            <InputError message={errors.ends_on} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Placements({ session, planning }: { session: SessionDates; planning: SessionPlanning }) {
    const [editing, setEditing] = useState<PlacementRow | 'new' | null>(null);
    const [host, setHost] = useState<{ period: PlacementRow; trainee: PlacementRow['trainees'][number] } | null>(null);

    const destroy = (p: PlacementRow) => {
        if (window.confirm(`Supprimer la période de stage « ${p.label} » ?`)) {
            router.delete(route('sessions.placements.destroy', [session.id, p.id]), visit);
        }
    };
    const removeHost = (p: PlacementRow, t: PlacementRow['trainees'][number]) =>
        router.delete(route('sessions.placements.hosts.destroy', [session.id, p.id, t.enrolment_id]), visit);

    return (
        <section id="placements" aria-label="Stages" className="grid scroll-mt-4 gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Stages en entreprise · {planning.placements.length}</h2>
                {planning.can_seances && (
                    <Button type="button" size="sm" variant="outline" onClick={() => setEditing('new')}>
                        <Plus className="h-4 w-4" />
                        Ajouter une période de stage
                    </Button>
                )}
            </div>
            {planning.placements.length === 0 && <p className="text-muted-foreground text-sm">Aucune période de stage.</p>}
            {planning.placements.map((p) => (
                <div key={p.id} className="grid gap-2 rounded-xl border p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="text-sm">
                            <span className="font-medium">{p.label}</span>
                            <span className="text-muted-foreground">
                                {' '}
                                · du {formatDay(p.starts_on)} au {formatDay(p.ends_on)}
                            </span>
                        </div>
                        {planning.can_seances && (
                            <div className="flex gap-1">
                                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(p)} aria-label={`Modifier ${p.label}`}>
                                    <Pencil className="h-4 w-4" />
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    onClick={() => destroy(p)}
                                    aria-label={`Supprimer ${p.label}`}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        )}
                    </div>
                    {p.trainees.length > 0 && (
                        <ul className="grid gap-1 text-sm">
                            {p.trainees.map((t) => (
                                <li key={t.enrolment_id} className="flex flex-wrap items-center justify-between gap-2 border-t pt-1">
                                    <span>
                                        {t.trainee}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {t.host?.label ?? 'entreprise non renseignée'} · {formatDay(t.from)} → {formatDay(t.to)}
                                            {t.own_dates && ' (dates propres)'}
                                        </span>
                                    </span>
                                    {planning.can_seances && (
                                        <span className="flex gap-1">
                                            <Button type="button" size="sm" variant="ghost" onClick={() => setHost({ period: p, trainee: t })}>
                                                Modifier
                                            </Button>
                                            {(t.host || t.own_dates) && (
                                                <Button type="button" size="sm" variant="ghost" onClick={() => removeHost(p, t)}>
                                                    Effacer
                                                </Button>
                                            )}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ))}
            {editing !== null && <PlacementDialog session={session} period={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {host && <HostDialog sessionId={session.id} period={host.period} trainee={host.trainee} onClose={() => setHost(null)} />}
        </section>
    );
}

// ---------------------------------------------------------------- tab

/** Séances, calendar, assistant and placements of the « Planning » tab of a session. */
export function SessionPlanningSeances({ session, planning }: { session: SessionDates; planning: SessionPlanning }) {
    const [editing, setEditing] = useState<SeanceRow | 'new' | null>(null);
    const [cancelling, setCancelling] = useState<SeanceRow | null>(null);
    const [generating, setGenerating] = useState(false);
    const canPlan = planning.can_seances && planning.seance_modules.length > 0;

    return (
        <div className="grid gap-6">
            <section id="seances" aria-label="Séances" className="grid scroll-mt-4 gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-sm font-semibold">Séances · {planning.seances.filter((s) => s.status === 'planned').length}</h2>
                    {planning.can_seances && (
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="outline" disabled={!canPlan} onClick={() => setEditing('new')}>
                                <CalendarPlus className="h-4 w-4" />
                                Ajouter une séance
                            </Button>
                            <Button type="button" size="sm" disabled={!canPlan} onClick={() => setGenerating(true)}>
                                <Wand2 className="h-4 w-4" />
                                Générer les séances
                            </Button>
                        </div>
                    )}
                </div>
                {planning.can_seances && planning.seance_modules.length === 0 && (
                    <div className="grid gap-2 rounded-xl border border-dashed p-3 text-sm" data-testid="no-seance-module">
                        <p>
                            Les séances se planifient sur un module en mode « Séances ». Les modules de cette session sont en mode « Intervalle »
                            (heures saisies par mois).
                        </p>
                        {planning.can_manage && planning.modules.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {planning.modules.map((m) => (
                                    <Button
                                        key={m.id}
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(route('sessions.modules.mode', [session.id, m.id]), { dating_mode: 'seances' }, visit)
                                        }
                                    >
                                        Passer « {m.title} » en séances
                                    </Button>
                                ))}
                            </div>
                        )}
                        {!planning.can_manage && (
                            <p className="text-muted-foreground">Demandez à un gestionnaire de la session de changer le mode d'un module.</p>
                        )}
                    </div>
                )}
                <PlanningCalendar
                    session={session}
                    planning={planning}
                    onOpen={planning.can_seances ? (s) => s.status === 'planned' && setEditing(s) : null}
                />
                <SeanceList sessionId={session.id} planning={planning} onEdit={setEditing} onCancel={setCancelling} />
            </section>

            <Placements session={session} planning={planning} />

            {editing !== null && (
                <SeanceDialog session={session} seance={editing === 'new' ? null : editing} planning={planning} onClose={() => setEditing(null)} />
            )}
            {cancelling && <CancelSeanceDialog sessionId={session.id} seance={cancelling} onClose={() => setCancelling(null)} />}
            {generating && <GeneratorDialog session={session} planning={planning} onClose={() => setGenerating(false)} />}
        </div>
    );
}
