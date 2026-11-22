import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatDay, type Option } from '@/lib/crm';
import { sendJson } from '@/lib/sessions';
import { CheckCheck, ClipboardCheck, FileText, LoaderCircle, Lock, LockOpen, QrCode, Trash2 } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useRef, useState } from 'react';

/** Attendance of a trainee at a séance (AttendanceRows::grid): names only, never needs, notes nor prices. */
interface GridRow {
    enrolment_id: string;
    name: string;
    status: 'present' | 'partial' | 'absent' | 'excused' | null;
    minutes: number | null;
    minutes_label: string | null;
    reason: string | null;
    needs_review: boolean;
    /** Expected that day (entry and leaving days); false: entered before the trainee's dates changed. */
    eligible: boolean;
}

/** Signed scan of an attendance sheet (AttendanceSheetController::list). */
interface Scan {
    id: string;
    scope: 'seance' | 'day';
    name: string;
    mime: string;
    size_bytes: number;
    created_at: string | null;
    url: string;
}

interface ScanList {
    scans: Scan[];
    can_delete: boolean;
}

interface Grid {
    seance: {
        id: string;
        date: string;
        start: string;
        end: string;
        duration_minutes: number;
        duration: string;
        module: string;
        cancelled: boolean;
        closed: boolean;
    };
    rows: GridRow[];
    statuses: Option[];
    can_edit: boolean;
    count?: number;
    /** The half-days of the séance's day (SlotRows::forSeance): online signing. */
    slots?: DaySlot[];
}

/** A half-day of the séance's day and its online signing, if opened. */
interface DaySlot {
    half: 'morning' | 'afternoon';
    label: string;
    status: 'open' | 'closed' | 'validated' | null;
    slot_id: string | null;
    signed: number;
    expected: number;
    is_today: boolean;
    /** The séance's day is still to come: signing opens that day. */
    is_future: boolean;
    /** A past day the user (a manager) may open a catch-up for. */
    can_catch_up: boolean;
}

const SLOT_STATUS: Record<string, string> = { open: 'Ouvert', closed: 'Lien fermé', validated: 'Validé' };

/** « Émargement en ligne » of a séance: open the half-day of today (QR code), or follow it. */
function OnlineSigning({ seanceId, slots }: { seanceId: string; slots: DaySlot[] }) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    if (slots.length === 0) return null;

    const open = async (half: string) => {
        setBusy(half);
        const answer = await sendJson<{ url: string }>('POST', route('seances.signing-slots', seanceId), { half });
        setBusy(null);
        if (answer.ok && answer.data) window.location.assign(answer.data.url);
        else setError(answer.errors.half ?? answer.message);
    };

    const catchUp = async (half: string) => {
        setBusy(half);
        setError(null);
        const answer = await sendJson<{ url: string }>('POST', route('seances.catch-up-slots', seanceId), { half });
        setBusy(null);
        if (answer.ok && answer.data) window.location.assign(answer.data.url);
        else setError(answer.errors.half ?? answer.message);
    };

    return (
        <section aria-labelledby="online-signing-title" className="grid gap-3 rounded-md border p-3">
            <h3 id="online-signing-title" className="font-medium">
                Émargement en ligne
            </h3>
            <p className="text-muted-foreground text-xs">
                Les stagiaires signent depuis leur téléphone en scannant un QR code, le jour même ; vous validez ensuite chaque demi-journée.
            </p>
            <ul className="grid gap-2">
                {slots.map((s) => (
                    <li key={s.half} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="flex items-center gap-2">
                            {s.label}
                            {s.status && <Badge variant={s.status === 'validated' ? 'secondary' : 'outline'}>{SLOT_STATUS[s.status]}</Badge>}
                            {s.status && (
                                <span className="text-muted-foreground text-xs">
                                    {s.signed} / {s.expected} signé{s.signed > 1 ? 's' : ''}
                                </span>
                            )}
                        </span>
                        {s.slot_id && (s.status === 'validated' || !s.is_today) ? (
                            <Button asChild variant="outline" size="sm">
                                <a href={route('sessions.signing.show', s.slot_id)}>{s.status === 'validated' ? 'Voir' : 'Valider'}</a>
                            </Button>
                        ) : s.is_today ? (
                            <Button size="sm" disabled={busy !== null} onClick={() => open(s.half)}>
                                {busy === s.half ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <QrCode className="h-4 w-4" />}
                                {s.status === 'open' ? 'Afficher le QR code' : s.status === 'closed' ? 'Rouvrir' : "Ouvrir l'émargement"}
                            </Button>
                        ) : s.can_catch_up ? (
                            <span className="flex items-center gap-2">
                                <span className="text-muted-foreground text-xs">Pas d'émargement en ligne ce jour-là</span>
                                <Button variant="outline" size="sm" disabled={busy !== null} onClick={() => catchUp(s.half)}>
                                    {busy === s.half && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                    Faire un rattrapage
                                </Button>
                            </span>
                        ) : (
                            <span className="text-muted-foreground text-xs">
                                {s.is_future ? "S'ouvre le jour de la séance" : "Pas d'émargement en ligne ce jour-là"}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            <InputError message={error ?? undefined} />
        </section>
    );
}

interface SessionAttendance {
    month: string;
    months: { value: string; label: string; closed: boolean }[];
    closure: {
        closed: boolean;
        closed_at: string | null;
        closed_by: string | null;
        reopened_at: string | null;
        reopened_by: string | null;
        reopen_reason: string | null;
    };
    can: { manage: boolean; close: boolean };
    seances: {
        id: string;
        date: string;
        start: string;
        end: string;
        duration: string;
        module: string;
        trainer: string | null;
        cancelled: boolean;
        /** The user may open its attendance (a manager, or its trainer). */
        can_record: boolean;
        recorded: number;
        needs_review: number;
        expected: number;
    }[];
    monthly: {
        modules: { id: string; title: string; planned: string }[];
        rows: { enrolment_id: string; name: string; allowed: boolean; minutes: Record<string, number>; trainers: Record<string, string | null> }[];
        trainers: Option[];
    };
    realised: { enrolment_id: string; name: string; planned: string; realised: string }[];
    /** Half-days signed online this month (SlotRows::forMonth). */
    slots: {
        id: string;
        date: string;
        half: string;
        label: string;
        status: 'open' | 'closed' | 'validated';
        signed: number;
        expected: number;
        validated_by: string | null;
        to_validate: boolean;
    }[];
    /** Archived signed sheets of the session, the latest first. */
    signed_sheets: { id: string; month: string; month_label: string; generated_at: string | null; sha256: string; url: string }[];
    /** Catch-ups of the month waiting for a confirmation (attendance managers only). */
    catch_ups: CatchUp[];
}

interface CatchUp {
    signature_id: string;
    enrolment_id: string;
    name: string;
    date: string;
    half: string;
    half_label: string;
    kind: string;
    kind_label: string;
    signed_at: string;
    drawing_url: string | null;
    manual_attendance: boolean;
}

/** 750 → « 12:30 ». */
function hhmm(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return '';

    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

/** « 12:30 », « 12h30 », « 12 » → minutes; null when malformed. */
function parseHours(value: string): number | null {
    const m = value.trim().match(/^(\d{1,3})(?:\s*[:hH]\s*([0-5]?\d)?)?$/);
    if (!m) return null;

    return Number(m[1]) * 60 + (m[2] ? Number(m[2]) : 0);
}

function monthLabel(label: string): string {
    return label.charAt(0).toUpperCase() + label.slice(1);
}

/**
 * Attendance of one séance: « Tous présents », then the exceptions (partial
 * with its minutes, absent, excused, a reason). Usable on a phone and with
 * the keyboard: one block per trainee, status buttons with aria-pressed.
 */
export function AttendanceDialog({ seanceId, open, onClose }: { seanceId: string | null; open: boolean; onClose: (changed: boolean) => void }) {
    const [grid, setGrid] = useState<Grid | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, Record<string, string>>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [drafts, setDrafts] = useState<Record<string, { minutes: string; reason: string }>>({});
    const [changed, setChanged] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [scans, setScans] = useState<ScanList>({ scans: [], can_delete: false });
    const [scanDay, setScanDay] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);

    const take = (answer: Grid) => {
        setGrid(answer);
        setDrafts(
            Object.fromEntries(
                answer.rows.map((r) => [
                    r.enrolment_id,
                    { minutes: r.status === 'partial' && r.minutes !== null ? String(r.minutes) : '', reason: r.reason ?? '' },
                ]),
            ),
        );
    };

    useEffect(() => {
        if (!open || !seanceId) return;
        setGrid(null);
        setError(null);
        setErrors({});
        setChanged(false);
        setNotice(null);
        setScans({ scans: [], can_delete: false });
        setScanError(null);
        sendJson<Grid & ScanList>('GET', route('seances.attendance.show', seanceId)).then((answer) => {
            if (answer.ok && answer.data) {
                take(answer.data);
                setScans({ scans: answer.data.scans, can_delete: answer.data.can_delete });
            } else setError(answer.message);
        });
    }, [open, seanceId]);

    const save = async (row: GridRow, status: string) => {
        if (!grid) return;
        const draft = drafts[row.enrolment_id] ?? { minutes: '', reason: '' };
        setBusy(row.enrolment_id);
        const answer = await sendJson<Grid>('POST', route('seances.attendance.store', grid.seance.id), {
            enrolment_id: row.enrolment_id,
            status,
            minutes: status === 'partial' ? draft.minutes : null,
            reason: draft.reason,
        });
        setBusy(null);
        if (answer.ok && answer.data) {
            take(answer.data);
            setErrors((e) => ({ ...e, [row.enrolment_id]: {} }));
            setChanged(true);
            setError(null);
        } else if (answer.status === 422) {
            setErrors((e) => ({ ...e, [row.enrolment_id]: answer.errors }));
        } else {
            setError(answer.message);
        }
    };

    const clear = async (row: GridRow) => {
        if (!grid) return;
        setBusy(row.enrolment_id);
        const answer = await sendJson<Grid>('DELETE', route('seances.attendance.destroy', [grid.seance.id, row.enrolment_id]));
        setBusy(null);
        if (answer.ok && answer.data) {
            take(answer.data);
            setChanged(true);
        } else setError(answer.message);
    };

    const allPresent = async () => {
        if (!grid) return;
        setBusy('all');
        const answer = await sendJson<Grid>('POST', route('seances.attendance.all-present', grid.seance.id));
        setBusy(null);
        if (answer.ok && answer.data) {
            take(answer.data);
            setChanged(true);
            const n = answer.data.count ?? 0;
            setNotice(
                n === 0
                    ? 'Aucun stagiaire à marquer : tous ont déjà une présence.'
                    : n === 1
                      ? '1 stagiaire marqué présent.'
                      : `${n} stagiaires marqués présents.`,
            );
        } else setError(answer.message);
    };

    const uploadScan = async (file: File | undefined) => {
        if (!grid || !file) return;
        const body = new FormData();
        body.append('file', file);
        if (scanDay) body.append('day', '1');
        setBusy('scan');
        setScanError(null);
        const answer = await sendJson<ScanList>('POST', route('seances.scans.store', grid.seance.id), body);
        setBusy(null);
        if (answer.ok && answer.data) setScans(answer.data);
        else setScanError(answer.errors.file ?? answer.message);
    };

    const deleteScan = async (scan: Scan) => {
        if (!grid || !window.confirm('Supprimer ce scan ? Cette action est définitive.')) return;
        setBusy('scan');
        const answer = await sendJson<ScanList>('DELETE', route('seances.scans.destroy', [grid.seance.id, scan.id]));
        setBusy(null);
        if (answer.ok && answer.data) setScans(answer.data);
        else setScanError(answer.message);
    };

    const missing = grid ? grid.rows.filter((r) => r.eligible && r.status === null).length : 0;

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose(changed)}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Présences</DialogTitle>
                    <DialogDescription>
                        {grid
                            ? `${grid.seance.module} - ${formatDay(grid.seance.date)}, ${grid.seance.start}–${grid.seance.end} (${grid.seance.duration})`
                            : 'Chargement…'}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-destructive text-sm">
                        {error}
                    </p>
                )}
                {!grid && !error && <LoaderCircle className="h-5 w-5 animate-spin" aria-label="Chargement" />}
                {grid && (
                    <div className="grid gap-4">
                        {grid.seance.cancelled && (
                            <p className="text-muted-foreground text-sm">Séance annulée : ses présences sont gardées à 0 minute.</p>
                        )}
                        {grid.seance.closed && (
                            <p className="text-muted-foreground text-sm">Ce mois est clôturé : les présences ne se modifient plus.</p>
                        )}
                        {grid.can_edit && (
                            <div className="flex flex-wrap items-center gap-3">
                                <Button type="button" onClick={allPresent} disabled={busy !== null || missing === 0}>
                                    {busy === 'all' ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <CheckCheck className="h-4 w-4" />}
                                    Tous présents
                                </Button>
                                <span className="text-muted-foreground text-sm">puis notez les exceptions.</span>
                            </div>
                        )}
                        {notice && (
                            <p role="status" className="text-sm">
                                {notice}
                            </p>
                        )}
                        {grid.rows.length === 0 && <p className="text-muted-foreground text-sm">Aucun stagiaire attendu à cette séance.</p>}
                        <ul className="grid gap-3">
                            {grid.rows.map((row) => {
                                const draft = drafts[row.enrolment_id] ?? { minutes: '', reason: '' };
                                const rowErrors = errors[row.enrolment_id] ?? {};
                                const editable = grid.can_edit && row.eligible && busy === null;
                                const setDraft = (patch: Partial<typeof draft>) =>
                                    setDrafts((d) => ({ ...d, [row.enrolment_id]: { ...draft, ...patch } }));

                                return (
                                    <li key={row.enrolment_id} className="rounded-md border p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-medium">{row.name}</span>
                                            <span className="flex items-center gap-2 text-sm">
                                                {row.status !== null && <span className="text-muted-foreground">{row.minutes_label}</span>}
                                                {row.needs_review && <Badge variant="destructive">À revoir</Badge>}
                                                {!row.eligible && <Badge variant="outline">Hors de ses dates</Badge>}
                                            </span>
                                        </div>
                                        <div role="group" aria-label={`Présence de ${row.name}`} className="mt-2 flex flex-wrap gap-2">
                                            {grid.statuses.map((s) => (
                                                <Button
                                                    key={s.value}
                                                    type="button"
                                                    size="sm"
                                                    variant={row.status === s.value ? 'default' : 'outline'}
                                                    aria-pressed={row.status === s.value}
                                                    disabled={!editable}
                                                    onClick={() => save(row, s.value)}
                                                >
                                                    {s.label}
                                                </Button>
                                            ))}
                                            {row.status !== null && grid.can_edit && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={busy !== null}
                                                    onClick={() => clear(row)}
                                                    aria-label={`Effacer la présence de ${row.name}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            )}
                                        </div>
                                        {editable && (
                                            <div className="mt-2 grid gap-2 sm:grid-cols-[8rem_1fr_auto] sm:items-end">
                                                <div className="grid gap-1">
                                                    <Label htmlFor={`minutes-${row.enrolment_id}`}>Minutes (partiel)</Label>
                                                    <Input
                                                        id={`minutes-${row.enrolment_id}`}
                                                        inputMode="numeric"
                                                        value={draft.minutes}
                                                        onChange={(e) => setDraft({ minutes: e.target.value })}
                                                    />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label htmlFor={`reason-${row.enrolment_id}`}>Motif</Label>
                                                    <Input
                                                        id={`reason-${row.enrolment_id}`}
                                                        maxLength={300}
                                                        value={draft.reason}
                                                        onChange={(e) => setDraft({ reason: e.target.value })}
                                                    />
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => save(row, row.status ?? (draft.minutes !== '' ? 'partial' : 'present'))}
                                                >
                                                    Enregistrer
                                                </Button>
                                            </div>
                                        )}
                                        <InputError message={rowErrors.minutes ?? rowErrors.status ?? rowErrors.reason ?? rowErrors.enrolment_id} />
                                    </li>
                                );
                            })}
                        </ul>
                        <OnlineSigning seanceId={grid.seance.id} slots={grid.slots ?? []} />
                        <details className="group grid gap-3 rounded-md border p-3">
                            <summary id="sheet-title" className="cursor-pointer font-medium">
                                Émargement papier (secours)
                            </summary>
                            <p className="text-muted-foreground mt-2 text-sm">
                                Sans réseau, sans téléphone ou à la demande d'un financeur : imprimez la feuille, faites-la signer, puis déposez le
                                scan.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline" size="sm" disabled={grid.seance.cancelled}>
                                    <a href={route('seances.sheet', { seance: grid.seance.id })} target="_blank" rel="noreferrer">
                                        <FileText className="h-4 w-4" />
                                        Imprimer cette séance
                                    </a>
                                </Button>
                                <Button asChild variant="outline" size="sm" disabled={grid.seance.cancelled}>
                                    <a href={route('seances.sheet', { seance: grid.seance.id, day: 1 })} target="_blank" rel="noreferrer">
                                        <FileText className="h-4 w-4" />
                                        Imprimer la journée
                                    </a>
                                </Button>
                            </div>
                            {scans.scans.length > 0 && (
                                <ul className="grid gap-1 text-sm">
                                    {scans.scans.map((scan) => (
                                        <li key={scan.id} className="flex flex-wrap items-center justify-between gap-2">
                                            <a href={scan.url} className="underline">
                                                {scan.name}
                                            </a>
                                            <span className="text-muted-foreground flex items-center gap-2">
                                                {scan.scope === 'day' ? 'Journée' : 'Séance'} · {Math.max(1, Math.round(scan.size_bytes / 1024))} Ko
                                                {scans.can_delete && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={busy !== null}
                                                        onClick={() => deleteScan(scan)}
                                                        aria-label={`Supprimer le scan ${scan.name}`}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="scan-file">Déposer la feuille signée (PDF, PNG ou JPEG, 10 Mo au plus)</Label>
                                <Input
                                    id="scan-file"
                                    type="file"
                                    accept="application/pdf,image/png,image/jpeg"
                                    disabled={busy !== null}
                                    onChange={(e) => {
                                        void uploadScan(e.target.files?.[0]);
                                        e.target.value = '';
                                    }}
                                />
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={scanDay} onChange={(e) => setScanDay(e.target.checked)} />
                                    Ce scan couvre toute la journée
                                </label>
                                <InputError message={scanError ?? undefined} />
                            </div>
                        </details>
                    </div>
                )}
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onClose(changed)}>
                        Fermer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ReopenDialog({
    open,
    month,
    onClose,
    onSubmit,
}: {
    open: boolean;
    month: string;
    onClose: () => void;
    onSubmit: (reason: string) => Promise<string | null>;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        setBusy(true);
        const failure = await onSubmit(reason);
        setBusy(false);
        if (failure === null) {
            setReason('');
            setError(null);
            onClose();
        } else setError(failure);
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Rouvrir {month}</DialogTitle>
                        <DialogDescription>
                            Les présences, les heures et les séances du mois redeviennent modifiables. La réouverture est journalisée.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="reopen-reason">Motif *</Label>
                        <Input id="reopen-reason" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
                        <InputError message={error ?? undefined} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={busy}>
                            {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Rouvrir le mois
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MonthlyCell({
    value,
    disabled,
    label,
    onSave,
}: {
    value: number | undefined;
    disabled: boolean;
    label: string;
    onSave: (minutes: number | null) => Promise<string | null>;
}) {
    const [text, setText] = useState(hhmm(value));
    const [error, setError] = useState<string | null>(null);
    useEffect(() => setText(hhmm(value)), [value]);

    const commit = async () => {
        if (text === hhmm(value)) return;
        if (text.trim() === '') {
            setError(value === undefined ? null : await onSave(null));
            return;
        }
        const minutes = parseHours(text);
        if (minutes === null) {
            setError('Format attendu : 12:30');
            return;
        }
        setError(await onSave(minutes));
    };

    return (
        <div className="grid gap-1">
            <Input
                aria-label={label}
                className="w-24"
                placeholder="0:00"
                value={text}
                disabled={disabled}
                onChange={(e) => setText(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), commit())}
            />
            {error && <span className="text-destructive text-xs">{error}</span>}
        </div>
    );
}

/**
 * « Présences » tab of a session: a Paris month at a time - its séances
 * and their attendance, the hours of its interval modules, the realised
 * hours, and its closure (closing locks attendance, hours and séances of
 * the month; reopening needs a reason).
 */
/** Monday to Sunday of the current week, Paris days (YYYY-MM-DD). */
function currentWeek(): { from: string; to: string } {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
    const day = new Date(`${today}T12:00:00Z`);
    const monday = new Date(day.getTime() - ((day.getUTCDay() + 6) % 7) * 86400000);
    const sunday = new Date(monday.getTime() + 6 * 86400000);

    return { from: monday.toISOString().slice(0, 10), to: sunday.toISOString().slice(0, 10) };
}

export function SessionAttendance({ sessionId }: { sessionId: string }) {
    const [data, setData] = useState<SessionAttendance | null>(null);
    const [month, setMonth] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [seanceId, setSeanceId] = useState<string | null>(null);
    const [reopen, setReopen] = useState(false);
    const [busy, setBusy] = useState(false);
    const seancesBox = useRef<HTMLDivElement>(null);

    const load = useCallback(
        (m: string | null) => {
            sendJson<SessionAttendance>('GET', route('sessions.attendance', sessionId) + (m ? `?month=${encodeURIComponent(m)}` : '')).then(
                (answer) => {
                    if (answer.ok && answer.data) {
                        setData(answer.data);
                        setMonth(answer.data.month);
                        setError(null);
                    } else setError(answer.message);
                },
            );
        },
        [sessionId],
    );

    useEffect(() => load(null), [load]);

    // The scroll area opens on the current week (the month's start otherwise), without moving the page.
    useEffect(() => {
        const box = seancesBox.current;
        if (!box) return;
        const row = box.querySelector<HTMLElement>('[data-this-week]');
        const head = box.querySelector('thead')?.getBoundingClientRect().height ?? 0;
        box.scrollTop = row ? row.offsetTop - head : 0;
    }, [data?.month]);

    if (!data) {
        return error ? (
            <p className="text-destructive text-sm">{error}</p>
        ) : (
            <LoaderCircle className="h-5 w-5 animate-spin" aria-label="Chargement" />
        );
    }

    const week = currentWeek();

    const current = data.months.find((m) => m.value === data.month);
    const label = current ? monthLabel(current.label) : data.month;
    const closed = data.closure.closed;

    const close = async () => {
        setBusy(true);
        const answer = await sendJson<SessionAttendance>('POST', route('sessions.closures.store', sessionId), { month: data.month });
        setBusy(false);
        if (answer.ok && answer.data) setData(answer.data);
        else setError(answer.message);
    };

    const reopenMonth = async (reason: string): Promise<string | null> => {
        const answer = await sendJson<SessionAttendance>('POST', route('sessions.closures.reopen', sessionId), { month: data.month, reason });
        if (answer.ok && answer.data) {
            setData(answer.data);
            return null;
        }

        return answer.errors.reason ?? answer.message ?? 'Une erreur est survenue.';
    };

    const saveHours = async (enrolmentId: string, moduleId: string, minutes: number | null): Promise<string | null> => {
        const answer = await sendJson<SessionAttendance>('POST', route('sessions.monthly-hours', sessionId), {
            enrolment_id: enrolmentId,
            session_module_id: moduleId,
            month: data.month,
            minutes,
            clear: minutes === null,
        });
        if (answer.ok && answer.data) {
            setData(answer.data);
            return null;
        }

        return Object.values(answer.errors)[0] ?? answer.message ?? 'Une erreur est survenue.';
    };

    return (
        <div className="grid gap-6">
            <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Mois">
                {data.months.map((m) => (
                    <Button
                        key={m.value}
                        type="button"
                        size="sm"
                        variant={m.value === month ? 'default' : 'outline'}
                        aria-pressed={m.value === month}
                        onClick={() => load(m.value)}
                    >
                        {m.closed && <Lock className="h-3 w-3" aria-label="clôturé" />}
                        {monthLabel(m.label)}
                    </Button>
                ))}
            </div>
            {error && (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}

            <section id="closures" className="flex scroll-mt-4 flex-wrap items-center justify-between gap-3 rounded-md border p-4">
                <div className="text-sm">
                    {closed ? (
                        <p>
                            <Badge variant="secondary" className="mr-2">
                                Clôturé
                            </Badge>
                            {label} clôturé{data.closure.closed_at ? ` le ${formatDateTime(data.closure.closed_at)}` : ''}
                            {data.closure.closed_by ? ` par ${data.closure.closed_by}` : ''} : présences, heures et séances du mois verrouillées.
                        </p>
                    ) : (
                        <p>{label} : ouvert à la saisie.</p>
                    )}
                    {data.closure.reopened_at && (
                        <p className="text-muted-foreground mt-1">
                            Dernière réouverture le {formatDateTime(data.closure.reopened_at)}
                            {data.closure.reopened_by ? ` par ${data.closure.reopened_by}` : ''}
                            {data.closure.reopen_reason ? ` - motif : ${data.closure.reopen_reason}` : ''}
                        </p>
                    )}
                </div>
                {data.can.close &&
                    (closed ? (
                        <Button type="button" variant="outline" onClick={() => setReopen(true)}>
                            <LockOpen className="h-4 w-4" />
                            Rouvrir le mois
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={close} disabled={busy}>
                            <Lock className="h-4 w-4" />
                            Clôturer {label}
                        </Button>
                    ))}
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Séances du mois</h3>
                {data.seances.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucune séance ce mois-ci.</p>
                ) : (
                    <div ref={seancesBox} className="max-h-80 overflow-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground bg-background sticky top-0 z-10 text-left">
                                <tr>
                                    <th className="py-2 pr-3 pl-2 font-normal">Date</th>
                                    <th className="py-2 pr-3 font-normal">Horaires</th>
                                    <th className="py-2 pr-3 font-normal">Module</th>
                                    <th className="py-2 pr-3 font-normal">Formateur</th>
                                    <th className="py-2 pr-3 font-normal">Présences</th>
                                    <th className="py-2 font-normal">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.seances.map((s) => (
                                    <tr
                                        key={s.id}
                                        data-this-week={s.date >= week.from && s.date <= week.to ? '' : undefined}
                                        className={`border-t ${s.date >= week.from && s.date <= week.to ? 'bg-muted/50' : ''} ${s.cancelled ? 'text-muted-foreground line-through' : ''}`}
                                    >
                                        <td className="py-2 pr-3 pl-2">{formatDay(s.date)}</td>
                                        <td className="py-2 pr-3">
                                            {s.start}–{s.end} ({s.duration})
                                        </td>
                                        <td className="py-2 pr-3">{s.module}</td>
                                        <td className="py-2 pr-3">{s.trainer ?? '-'}</td>
                                        <td className="py-2 pr-3">
                                            {s.recorded} / {s.expected}
                                            {s.needs_review > 0 && (
                                                <Badge variant="destructive" className="ml-2 no-underline">
                                                    {s.needs_review} à revoir
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="py-2 text-right">
                                            {s.can_record && (
                                                <Button type="button" size="sm" variant="outline" onClick={() => setSeanceId(s.id)}>
                                                    <ClipboardCheck className="h-4 w-4" />
                                                    Présences
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {data.monthly.modules.length > 0 && (
                <section className="grid gap-2">
                    <h3 className="font-medium">Heures du mois (modules en intervalle)</h3>
                    <p className="text-muted-foreground text-sm">
                        Heures réalisées en {current?.label ?? data.month}, au format 12:30. Videz une case pour effacer ses heures.
                    </p>
                    {data.monthly.rows.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucun stagiaire ce mois-ci.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="py-2 pr-3 font-normal">Stagiaire</th>
                                        {data.monthly.modules.map((m) => (
                                            <th key={m.id} className="py-2 pr-3 font-normal">
                                                {m.title} <span className="text-xs">({m.planned} prévues)</span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.monthly.rows.map((r) => (
                                        <tr key={r.enrolment_id} className="border-t">
                                            <td className="py-2 pr-3">{r.name}</td>
                                            {data.monthly.modules.map((m) => (
                                                <td key={m.id} className="py-2 pr-3">
                                                    <MonthlyCell
                                                        value={r.minutes[m.id]}
                                                        label={`Heures de ${r.name} - ${m.title}`}
                                                        disabled={!data.can.manage || closed || !r.allowed}
                                                        onSave={(minutes) => saveHours(r.enrolment_id, m.id, minutes)}
                                                    />
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            <section className="grid gap-2">
                <h3 className="font-medium">Émargement en ligne</h3>
                {data.slots.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucune demi-journée émargée en ligne ce mois-ci.</p>
                ) : (
                    <ul className="divide-y rounded-md border text-sm">
                        {data.slots.map((s) => (
                            <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                                <span>
                                    {formatDay(s.date)} – {s.label} · {s.signed} / {s.expected} signé{s.signed > 1 ? 's' : ''}
                                    {s.validated_by && <span className="text-muted-foreground"> · validé par {s.validated_by}</span>}
                                </span>
                                <span className="flex items-center gap-2">
                                    {s.to_validate ? (
                                        <Badge variant="destructive">À valider</Badge>
                                    ) : (
                                        <Badge variant={s.status === 'validated' ? 'secondary' : 'outline'}>{SLOT_STATUS[s.status]}</Badge>
                                    )}
                                    <Button asChild variant="outline" size="sm">
                                        <a href={route('sessions.signing.show', s.id)}>Ouvrir</a>
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {data.catch_ups.length > 0 && <CatchUps catchUps={data.catch_ups} onChange={() => load(data.month)} />}
                <SignedSheets
                    sessionId={sessionId}
                    month={data.month}
                    label={label}
                    canGenerate={data.can.manage}
                    sheets={data.signed_sheets}
                    onChange={(sheets) => setData({ ...data, signed_sheets: sheets })}
                />
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Heures réalisées</h3>
                {data.realised.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun stagiaire.</p>
                ) : (
                    <table className="text-sm">
                        <thead className="text-muted-foreground text-left">
                            <tr>
                                <th className="py-2 pr-3 font-normal">Stagiaire</th>
                                <th className="py-2 pr-3 font-normal">Réalisées en {current?.label ?? data.month}</th>
                                <th className="py-2 font-normal">Prévues (session)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.realised.map((r) => (
                                <tr key={r.enrolment_id} className="border-t">
                                    <td className="py-2 pr-3">{r.name}</td>
                                    <td className="py-2 pr-3">{r.realised}</td>
                                    <td className="py-2">{r.planned}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <AttendanceDialog
                seanceId={seanceId}
                open={seanceId !== null}
                onClose={(changed) => {
                    setSeanceId(null);
                    if (changed) load(data.month);
                }}
            />
            <ReopenDialog open={reopen} month={label} onClose={() => setReopen(false)} onSubmit={reopenMonth} />
        </div>
    );
}

/** The signed sheet of the month: generated by a manager, archived versions with their SHA-256. */
function SignedSheets({
    sessionId,
    month,
    label,
    canGenerate,
    sheets,
    onChange,
}: {
    sessionId: string;
    month: string;
    label: string;
    canGenerate: boolean;
    sheets: SessionAttendance['signed_sheets'];
    onChange: (sheets: SessionAttendance['signed_sheets']) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const ofMonth = sheets.filter((s) => s.month === month);

    const generate = async () => {
        setBusy(true);
        const answer = await sendJson<{ sheets: SessionAttendance['signed_sheets'] }>('POST', route('sessions.signed-sheets.store', sessionId), {
            month,
        });
        setBusy(false);
        if (answer.ok && answer.data) {
            onChange(answer.data.sheets);
            setError(null);
        } else setError(answer.errors.month ?? answer.message);
    };

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium">Feuille d'émargement signée – {label}</span>
                {canGenerate && (
                    <Button size="sm" variant="outline" disabled={busy} onClick={generate}>
                        {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <FileText className="h-4 w-4" />}
                        Générer la feuille signée
                    </Button>
                )}
            </div>
            {ofMonth.length === 0 ? (
                <p className="text-muted-foreground text-xs">Aucune feuille générée pour ce mois.</p>
            ) : (
                <ul className="grid gap-1 text-sm">
                    {ofMonth.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                {s.generated_at ? formatDateTime(s.generated_at) : ''}{' '}
                                <span className="text-muted-foreground font-mono text-xs" title={s.sha256}>
                                    SHA-256 {s.sha256.slice(0, 12)}…
                                </span>
                            </span>
                            <span className="flex gap-1">
                                <Button asChild variant="ghost" size="sm">
                                    <a href={`${s.url}?apercu=1`} target="_blank" rel="noopener noreferrer">
                                        Aperçu
                                    </a>
                                </Button>
                                <Button asChild variant="ghost" size="sm">
                                    <a href={s.url}>Télécharger</a>
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <InputError message={error ?? undefined} />
        </div>
    );
}

/** « Rattrapages à confirmer »: a catch-up counts as attendance once confirmed; refused, it stays as a trace. */
function CatchUps({ catchUps, onChange }: { catchUps: CatchUp[]; onChange: () => void }) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refusing, setRefusing] = useState<CatchUp | null>(null);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | undefined>();

    const confirm = async (c: CatchUp) => {
        setBusy(c.signature_id);
        const answer = await sendJson('POST', route('sessions.catch-ups.confirm', c.signature_id));
        setBusy(null);
        if (answer.ok) {
            setError(null);
            onChange();
        } else setError(answer.message);
    };
    const refuse = async () => {
        if (!refusing) return;
        setBusy(refusing.signature_id);
        const answer = await sendJson('POST', route('sessions.catch-ups.refuse', refusing.signature_id), { reason });
        setBusy(null);
        if (answer.ok) {
            setRefusing(null);
            onChange();
        } else setReasonError(answer.errors.reason ?? answer.message);
    };

    return (
        <div className="grid gap-2 rounded-md border border-amber-300 p-3">
            <span className="flex items-center gap-2 text-sm font-medium">
                Rattrapages à confirmer <Badge variant="destructive">{catchUps.length}</Badge>
            </span>
            <p className="text-muted-foreground text-xs">
                Signatures faites après coup (sur place ou à distance). Confirmer écrit la présence ; refuser garde la signature sans présence.
            </p>
            <ul className="divide-y rounded-md border text-sm">
                {catchUps.map((c) => (
                    <li key={c.signature_id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                        <span className="flex items-center gap-2">
                            {c.drawing_url && (
                                <img src={c.drawing_url} alt={`Signature de ${c.name}`} className="h-8 w-16 rounded border bg-white object-contain" />
                            )}
                            <span>
                                {c.name} · {formatDay(c.date)} {c.half_label.toLowerCase()}
                                <span className="text-muted-foreground block text-xs">
                                    {c.kind_label}, signé le {formatDateTime(c.signed_at)}
                                    {c.manual_attendance && ' · Présence saisie à la main : non modifiée'}
                                </span>
                            </span>
                        </span>
                        <span className="flex gap-2">
                            <Button size="sm" disabled={busy !== null} onClick={() => confirm(c)}>
                                Confirmer
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy !== null}
                                onClick={() => {
                                    setRefusing(c);
                                    setReason('');
                                    setReasonError(undefined);
                                }}
                            >
                                Refuser
                            </Button>
                        </span>
                    </li>
                ))}
            </ul>
            <InputError message={error ?? undefined} />
            <Dialog open={refusing !== null} onOpenChange={(o) => !o && setRefusing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Refuser le rattrapage – {refusing?.name}</DialogTitle>
                        <DialogDescription>La signature est gardée comme trace, sans présence. Indiquez pourquoi.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="refusal-reason">Motif</Label>
                        <Input id="refusal-reason" maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
                        <InputError message={reasonError} />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRefusing(null)}>
                            Annuler
                        </Button>
                        <Button disabled={busy !== null || reason.trim() === ''} onClick={refuse}>
                            Refuser
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
