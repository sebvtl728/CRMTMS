import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime, todayIso, type Option } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { BellRing, CalendarDays, LoaderCircle, Mail, Phone, Plus, X } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';

/** Fiches an action is prefilled with (ids of the current page). */
export interface QuickActionContext {
    organisation_id?: string;
    person_id?: string;
    opportunity_id?: string;
}

type QuickActionRequest =
    | { mode: 'create'; context: QuickActionContext }
    | { mode: 'complete'; activityId: string }
    | { mode: 'edit'; activityId: string };

const OPEN_EVENT = 'crm:quick-action';
/** Dispatched on window once an action was saved (histories reload). */
export const ACTIVITY_SAVED_EVENT = 'crm:activity-saved';

/** Opens the « + Action » dialog, prefilled with the given fiches. */
export function openQuickAction(context: QuickActionContext = {}): void {
    window.dispatchEvent(new CustomEvent<QuickActionRequest>(OPEN_EVENT, { detail: { mode: 'create', context } }));
}

/** Opens the « Fait » dialog of an action to do. */
export function completeActivity(activityId: string): void {
    window.dispatchEvent(new CustomEvent<QuickActionRequest>(OPEN_EVENT, { detail: { mode: 'complete', activityId } }));
}

/** Opens the « Modifier » dialog of an action (to do or done). */
export function editActivity(activityId: string): void {
    window.dispatchEvent(new CustomEvent<QuickActionRequest>(OPEN_EVENT, { detail: { mode: 'edit', activityId } }));
}

export interface TypeOption extends Option {
    code: string;
}

interface Fiche {
    id: string;
    name: string;
}

interface Choices {
    types: TypeOption[];
    results: Option[];
}

/** One-click types (by their stable code; hidden when the organisme removed them). */
const SHORTCUTS = [
    { code: 'appel', label: 'Appel', icon: Phone },
    { code: 'rendez-vous-physique', label: 'RDV', icon: CalendarDays },
    { code: 'e-mail', label: 'E-mail', icon: Mail },
    { code: 'rappel', label: 'Rappel', icon: BellRing },
];

/**
 * YYYY-MM-DD + n days / months, on the calendar (no time zone shift). A
 * month later is clamped to the last day of the target month (31/01 → 28/02).
 */
export function shiftDay(iso: string, days: number, months = 0): string {
    const [y, m, d] = iso.split('-').map(Number);
    const lastDay = new Date(Date.UTC(y, m - 1 + months + 1, 0)).getUTCDate();
    const date = new Date(Date.UTC(y, m - 1 + months, Math.min(d, lastDay) + days));

    return date.toISOString().slice(0, 10);
}

/** Current Paris time as a datetime-local value (max of « Fait le »). */
function nowLocal(): string {
    const parts = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris', dateStyle: 'short', timeStyle: 'short' }).format(new Date());

    return parts.replace(' ', 'T');
}

/** An ISO 8601 instant as a datetime-local value in Paris time (« 2026-11-20T09:00 »). */
export function isoToLocal(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris', dateStyle: 'short', timeStyle: 'short' }).format(date).replace(' ', 'T');
}

/** Fiche of the page shown, for the header button. */
function contextFromPage(component: string, props: Record<string, unknown>): QuickActionContext {
    const id = (key: string) => {
        const value = props[key] as { id?: unknown } | undefined;

        return typeof value?.id === 'string' ? value.id : undefined;
    };
    if (component === 'crm/organisations/show') return { organisation_id: id('organisation') };
    if (component === 'crm/people/show') return { person_id: id('person') };
    if (component === 'crm/opportunities/show') return { opportunity_id: id('opportunity') };

    return {};
}

/** « + Action » of the app header on CRM pages: prefilled with the fiche of the page. */
export function QuickActionButton({ className }: { className?: string }) {
    const page = usePage<SharedData>();
    // CRM pages only.
    if (!page.component.startsWith('crm/') || !page.props.auth.permissions.includes('crm.activities.update')) return null;

    return (
        <Button
            type="button"
            size="sm"
            className={className}
            onClick={() => openQuickAction(contextFromPage(page.component, page.props as unknown as Record<string, unknown>))}
        >
            <Plus className="h-4 w-4" />
            Action
        </Button>
    );
}

/** « + Action » of a fiche or an opportunity page (live fiche, crm.activities.update). */
export function QuickActionFicheButton({ context, available = true }: { context: QuickActionContext; available?: boolean }) {
    const { auth } = usePage<SharedData>().props;
    if (!available || !auth.permissions.includes('crm.activities.update')) return null;

    return (
        <Button type="button" variant="outline" onClick={() => openQuickAction(context)}>
            <Plus className="h-4 w-4" />
            Action
        </Button>
    );
}

/**
 * The quick entry (and « Fait ») dialog, mounted once in the app header:
 * type in one click, fiche prefilled from the page (or searched), « fait
 * maintenant » checked, result, report and the next follow-up with
 * shortcuts. Target: under two minutes.
 */
export function QuickActionDialog() {
    const [request, setRequest] = useState<QuickActionRequest | null>(null);

    useEffect(() => {
        const onOpen = (e: Event) => setRequest((e as CustomEvent<QuickActionRequest>).detail);
        window.addEventListener(OPEN_EVENT, onOpen);

        return () => window.removeEventListener(OPEN_EVENT, onOpen);
    }, []);

    const close = () => setRequest(null);

    return (
        <Dialog open={request !== null} onOpenChange={(o) => !o && close()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                {request?.mode === 'create' && <CreateForm key={JSON.stringify(request.context)} context={request.context} onDone={close} />}
                {request?.mode === 'complete' && <CompleteForm key={request.activityId} activityId={request.activityId} onDone={close} />}
                {request?.mode === 'edit' && <EditForm key={request.activityId} activityId={request.activityId} onDone={close} />}
            </DialogContent>
        </Dialog>
    );
}

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!response.ok) throw new Error(String(response.status));

    return (await response.json()) as T;
}

function saved() {
    window.dispatchEvent(new Event(ACTIVITY_SAVED_EVENT));
}

type CreateFields = {
    type_id: string;
    organisation_id: string;
    person_id: string;
    opportunity_id: string;
    subject: string;
    done: boolean;
    done_at: string;
    due_at: string;
    result_id: string;
    report: string;
    location: string;
    next_type_id: string;
    next_due_at: string;
};

function CreateForm({ context, onDone }: { context: QuickActionContext; onDone: () => void }) {
    const [choices, setChoices] = useState<Choices | null>(null);
    const [failed, setFailed] = useState(false);
    const [fiches, setFiches] = useState<{ organisation: Fiche | null; person: Fiche | null; opportunity: Fiche | null }>({
        organisation: null,
        person: null,
        opportunity: null,
    });

    const { data, setData, post, processing, errors } = useForm<CreateFields>({
        type_id: '',
        organisation_id: '',
        person_id: '',
        opportunity_id: '',
        subject: '',
        done: true,
        done_at: '',
        due_at: '',
        result_id: '',
        report: '',
        location: '',
        next_type_id: '',
        next_due_at: '',
    });

    useEffect(() => {
        const params = new URLSearchParams();
        if (context.organisation_id) params.set('organisation', context.organisation_id);
        if (context.person_id) params.set('person', context.person_id);
        if (context.opportunity_id) params.set('opportunity', context.opportunity_id);
        getJson<Choices & { context: typeof fiches }>(`${route('crm.activities.options')}?${params}`)
            .then((json) => {
                setChoices({ types: json.types, results: json.results });
                setFiches(json.context);
                const call = json.types.find((t) => t.code === 'appel') ?? json.types[0];
                setData((current) => ({
                    ...current,
                    type_id: call?.value ?? '',
                    organisation_id: json.context.organisation?.id ?? '',
                    person_id: json.context.person?.id ?? '',
                    opportunity_id: json.context.opportunity?.id ?? '',
                }));
            })
            .catch(() => setFailed(true));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const type = choices?.types.find((t) => t.value === data.type_id);
    const ficheName = fiches.person?.name ?? fiches.organisation?.name ?? '';
    const shortcuts = SHORTCUTS.map((s) => ({ ...s, type: choices?.types.find((t) => t.code === s.code) })).filter((s) => s.type);

    const pickFiche = (kind: 'organisation' | 'person', fiche: Fiche) => {
        setFiches((f) => ({ ...f, [kind]: fiche }));
        setData(`${kind}_id`, fiche.id);
    };
    const dropFiche = (kind: 'organisation' | 'person' | 'opportunity') => {
        setFiches((f) => ({ ...f, [kind]: null }));
        setData(`${kind}_id`, '');
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('crm.activities.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved();
                onDone();
            },
        });
    };

    if (failed) {
        return <p role="alert">Le formulaire n’a pas pu être chargé. Fermez la fenêtre et réessayez.</p>;
    }
    if (choices === null) {
        return (
            <p role="status" className="text-muted-foreground flex items-center gap-2 text-sm">
                <LoaderCircle className="h-4 w-4 animate-spin" />
                Chargement…
            </p>
        );
    }

    const anyFiche = fiches.organisation || fiches.person || fiches.opportunity;

    return (
        <form onSubmit={submit} className="grid gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>Nouvelle action</DialogTitle>
                <DialogDescription>Choisissez le type, complétez si besoin, enregistrez.</DialogDescription>
            </DialogHeader>

            <div className="grid gap-2">
                <span className="text-sm font-medium" id="qa-type-label">
                    Type *
                </span>
                <div className="flex flex-wrap gap-2" role="group" aria-labelledby="qa-type-label">
                    {shortcuts.map(({ code, label, icon: Icon, type: t }) => (
                        <Button
                            key={code}
                            type="button"
                            size="sm"
                            variant={data.type_id === t?.value ? 'default' : 'outline'}
                            aria-pressed={data.type_id === t?.value}
                            onClick={() => setData('type_id', t?.value ?? '')}
                        >
                            <Icon className="h-4 w-4" />
                            {label}
                        </Button>
                    ))}
                    <Select value={data.type_id} onValueChange={(v) => setData('type_id', v)}>
                        <SelectTrigger className="h-8 w-auto min-w-40" aria-label="Autre type d’action">
                            <SelectValue placeholder="Autre type…" />
                        </SelectTrigger>
                        <SelectContent>
                            {choices.types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <InputError message={errors.type_id} />
            </div>

            <div className="grid gap-2">
                <span className="text-sm font-medium">Concerne *</span>
                {anyFiche && (
                    <ul className="flex flex-wrap gap-2">
                        {(['organisation', 'person', 'opportunity'] as const).map((kind) =>
                            fiches[kind] ? (
                                <li key={kind} className="bg-muted flex items-center gap-1 rounded-full py-1 pr-1 pl-3 text-sm">
                                    <span className="text-muted-foreground">
                                        {kind === 'organisation' ? 'Entreprise' : kind === 'person' ? 'Personne' : 'Opportunité'} :
                                    </span>
                                    {fiches[kind]?.name}
                                    <button
                                        type="button"
                                        className="hover:bg-background rounded-full p-1"
                                        aria-label={`Retirer ${fiches[kind]?.name}`}
                                        onClick={() => dropFiche(kind)}
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </li>
                            ) : null,
                        )}
                    </ul>
                )}
                {(!fiches.organisation || !fiches.person) && <FicheSearch onPick={pickFiche} hasFiche={Boolean(anyFiche)} />}
                <InputError
                    message={
                        (errors as Record<string, string | undefined>).fiche ?? errors.organisation_id ?? errors.person_id ?? errors.opportunity_id
                    }
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qa-subject">Objet</Label>
                <Input
                    id="qa-subject"
                    value={data.subject}
                    maxLength={200}
                    onChange={(e) => setData('subject', e.target.value)}
                    placeholder={type ? (ficheName ? `${type.label} – ${ficheName}` : type.label) : ''}
                />
                <InputError message={errors.subject} />
            </div>

            <div className="flex items-center gap-2">
                <Checkbox id="qa-done" checked={data.done} onCheckedChange={(c) => setData('done', c === true)} />
                <Label htmlFor="qa-done">Fait maintenant</Label>
            </div>

            {data.done ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="qa-result">Résultat</Label>
                            <Select value={data.result_id} onValueChange={(v) => setData('result_id', v)}>
                                <SelectTrigger id="qa-result">
                                    <SelectValue placeholder="—" />
                                </SelectTrigger>
                                <SelectContent>
                                    {choices.results.map((r) => (
                                        <SelectItem key={r.value} value={r.value}>
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.result_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="qa-done-at">Faite le (si pas maintenant)</Label>
                            <Input
                                id="qa-done-at"
                                type="datetime-local"
                                max={nowLocal()}
                                value={data.done_at}
                                onChange={(e) => setData('done_at', e.target.value)}
                            />
                            <InputError message={errors.done_at} />
                        </div>
                    </div>
                    <ReportField value={data.report} onChange={(v) => setData('report', v)} error={errors.report} />
                </>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="qa-due-at">À faire le *</Label>
                    <Input id="qa-due-at" type="datetime-local" value={data.due_at} onChange={(e) => setData('due_at', e.target.value)} />
                    <InputError message={errors.due_at} />
                </div>
            )}

            {type?.code.includes('rendez-vous') && (
                <div className="grid gap-2">
                    <Label htmlFor="qa-location">Lieu</Label>
                    <Input id="qa-location" value={data.location} maxLength={255} onChange={(e) => setData('location', e.target.value)} />
                    <InputError message={errors.location} />
                </div>
            )}

            {data.done && (
                <NextFollowUp
                    types={choices.types}
                    typeId={data.next_type_id}
                    dueAt={data.next_due_at}
                    onChange={(field, value) => setData(field, value)}
                    errors={errors}
                />
            )}

            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    Annuler
                </Button>
                <Button type="submit" disabled={processing || !data.type_id}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Enregistrer
                </Button>
            </DialogFooter>
        </form>
    );
}

interface ActivityData {
    id: string;
    type: string | null;
    type_id: string;
    subject: string;
    due_at: string | null;
    done_at: string | null;
    duration_minutes: number | null;
    location: string | null;
    report: string | null;
    result_id: string | null;
    responsible_user_id: string | null;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
}

type CompleteFields = {
    result_id: string;
    report: string;
    done_at: string;
    next_type_id: string;
    next_due_at: string;
};

/** « Fait » on an action to do: result, report, next follow-up. */
function CompleteForm({ activityId, onDone }: { activityId: string; onDone: () => void }) {
    const [loaded, setLoaded] = useState<(Choices & { activity: ActivityData }) | null>(null);
    const [failed, setFailed] = useState(false);
    const { data, setData, post, processing, errors } = useForm<CompleteFields>({
        result_id: '',
        report: '',
        done_at: '',
        next_type_id: '',
        next_due_at: '',
    });

    useEffect(() => {
        getJson<Choices & { activity: ActivityData }>(route('crm.activities.show', activityId))
            .then((json) => {
                setLoaded(json);
                setData((current) => ({ ...current, report: json.activity.report ?? '', result_id: json.activity.result_id ?? '' }));
            })
            .catch(() => setFailed(true));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activityId]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('crm.activities.done', activityId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved();
                onDone();
            },
        });
    };

    if (failed) return <p role="alert">L’action n’a pas pu être chargée.</p>;
    if (loaded === null) {
        return (
            <p role="status" className="text-muted-foreground flex items-center gap-2 text-sm">
                <LoaderCircle className="h-4 w-4 animate-spin" />
                Chargement…
            </p>
        );
    }

    const a = loaded.activity;

    return (
        <form onSubmit={submit} className="grid gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>Action faite</DialogTitle>
                <DialogDescription>
                    {a.subject}
                    {a.due_at && ` · prévue le ${formatDateTime(a.due_at)}`}
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="qa-result">Résultat</Label>
                    <Select value={data.result_id} onValueChange={(v) => setData('result_id', v)}>
                        <SelectTrigger id="qa-result">
                            <SelectValue placeholder="—" />
                        </SelectTrigger>
                        <SelectContent>
                            {loaded.results.map((r) => (
                                <SelectItem key={r.value} value={r.value}>
                                    {r.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.result_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="qa-done-at">Faite le (si pas maintenant)</Label>
                    <Input
                        id="qa-done-at"
                        type="datetime-local"
                        max={nowLocal()}
                        value={data.done_at}
                        onChange={(e) => setData('done_at', e.target.value)}
                    />
                    <InputError message={errors.done_at} />
                </div>
            </div>
            <ReportField value={data.report} onChange={(v) => setData('report', v)} error={errors.report} />
            <NextFollowUp
                types={loaded.types}
                typeId={data.next_type_id}
                dueAt={data.next_due_at}
                onChange={(field, value) => setData(field, value)}
                errors={errors}
            />
            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    Annuler
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Enregistrer
                </Button>
            </DialogFooter>
        </form>
    );
}

type EditFields = {
    type_id: string;
    subject: string;
    due_at: string;
    done_at: string;
    duration_minutes: string;
    location: string;
    responsible_user_id: string;
    result_id: string;
    report: string;
};

/**
 * « Modifier » an action: type, subject, due date (or date done, result
 * and report once done), duration, place and responsible. The fiches it
 * concerns are not changed (read-only).
 */
function EditForm({ activityId, onDone }: { activityId: string; onDone: () => void }) {
    const [loaded, setLoaded] = useState<(Choices & { activity: ActivityData; responsibles: Option[] }) | null>(null);
    const [failed, setFailed] = useState(false);
    // Dates as loaded: sent back only when changed (no minute rounding of an untouched date).
    const [initialDates, setInitialDates] = useState({ due_at: '', done_at: '' });
    const { data, setData, put, transform, processing, errors, setError, clearErrors } = useForm<EditFields>({
        type_id: '',
        subject: '',
        due_at: '',
        done_at: '',
        duration_minutes: '',
        location: '',
        responsible_user_id: '',
        result_id: '',
        report: '',
    });

    useEffect(() => {
        getJson<Choices & { activity: ActivityData; responsibles: Option[] }>(route('crm.activities.show', activityId))
            .then((json) => {
                setLoaded(json);
                const a = json.activity;
                setInitialDates({ due_at: isoToLocal(a.due_at), done_at: isoToLocal(a.done_at) });
                setData({
                    type_id: a.type_id,
                    subject: a.subject,
                    due_at: isoToLocal(a.due_at),
                    done_at: isoToLocal(a.done_at),
                    duration_minutes: a.duration_minutes === null ? '' : String(a.duration_minutes),
                    location: a.location ?? '',
                    responsible_user_id: a.responsible_user_id ?? '',
                    result_id: a.result_id ?? '',
                    report: a.report ?? '',
                });
            })
            .catch(() => setFailed(true));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activityId]);

    const done = loaded?.activity.done_at != null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        clearErrors();
        // A done action stays done: its date is required.
        if (done && data.done_at === '') {
            setError('done_at', 'Indiquez la date à laquelle l’action a été faite.');
            return;
        }
        if (!done && data.due_at === '') {
            setError('due_at', 'Indiquez l’échéance de l’action à faire.');
            return;
        }
        // An action to do keeps its report and stays to do; a done one keeps its due date.
        transform((d) => {
            const common = {
                type_id: d.type_id,
                subject: d.subject,
                duration_minutes: d.duration_minutes,
                location: d.location,
                ...(d.responsible_user_id ? { responsible_user_id: d.responsible_user_id } : {}),
            };

            const doneAt = d.done_at !== initialDates.done_at ? { done_at: d.done_at } : {};
            const dueAt = d.due_at !== initialDates.due_at ? { due_at: d.due_at } : {};

            return done ? { ...common, ...doneAt, result_id: d.result_id, report: d.report } : { ...common, ...dueAt };
        });
        put(route('crm.activities.update', activityId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved();
                onDone();
            },
        });
    };

    if (failed) return <p role="alert">L’action n’a pas pu être chargée.</p>;
    if (loaded === null) {
        return (
            <p role="status" className="text-muted-foreground flex items-center gap-2 text-sm">
                <LoaderCircle className="h-4 w-4 animate-spin" />
                Chargement…
            </p>
        );
    }

    const a = loaded.activity;
    const fiches = (['organisation', 'person', 'opportunity'] as const).filter((kind) => a[kind] !== null);

    return (
        <form onSubmit={submit} className="grid gap-4" noValidate>
            <DialogHeader>
                <DialogTitle>Modifier l’action</DialogTitle>
                <DialogDescription>{done ? 'Action faite' : 'Action à faire'}</DialogDescription>
            </DialogHeader>

            {fiches.length > 0 && (
                <div className="grid gap-2">
                    <span className="text-sm font-medium">Concerne</span>
                    <ul className="flex flex-wrap gap-2">
                        {fiches.map((kind) => (
                            <li key={kind} className="bg-muted rounded-full px-3 py-1 text-sm">
                                <span className="text-muted-foreground">
                                    {kind === 'organisation' ? 'Entreprise' : kind === 'person' ? 'Personne' : 'Opportunité'} :
                                </span>{' '}
                                {a[kind]?.name}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="qa-edit-type">Type *</Label>
                    <Select value={data.type_id} onValueChange={(v) => setData('type_id', v)}>
                        <SelectTrigger id="qa-edit-type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {loaded.types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.type_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="qa-edit-responsible">Responsable</Label>
                    <Select value={data.responsible_user_id} onValueChange={(v) => setData('responsible_user_id', v)}>
                        <SelectTrigger id="qa-edit-responsible">
                            <SelectValue placeholder="—" />
                        </SelectTrigger>
                        <SelectContent>
                            {loaded.responsibles.map((u) => (
                                <SelectItem key={u.value} value={u.value}>
                                    {u.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.responsible_user_id} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qa-edit-subject">Objet *</Label>
                <Input id="qa-edit-subject" value={data.subject} maxLength={200} onChange={(e) => setData('subject', e.target.value)} />
                <InputError message={errors.subject} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                {done ? (
                    <div className="grid gap-2">
                        <Label htmlFor="qa-edit-done-at">Faite le *</Label>
                        <Input
                            id="qa-edit-done-at"
                            type="datetime-local"
                            max={nowLocal()}
                            value={data.done_at}
                            onChange={(e) => setData('done_at', e.target.value)}
                        />
                        <InputError message={errors.done_at ?? errors.due_at} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="qa-edit-due-at">À faire le *</Label>
                        <Input id="qa-edit-due-at" type="datetime-local" value={data.due_at} onChange={(e) => setData('due_at', e.target.value)} />
                        <InputError message={errors.due_at} />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label htmlFor="qa-edit-duration">Durée (minutes)</Label>
                    <Input
                        id="qa-edit-duration"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={1440}
                        value={data.duration_minutes}
                        onChange={(e) => setData('duration_minutes', e.target.value)}
                    />
                    <InputError message={errors.duration_minutes} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qa-edit-location">Lieu</Label>
                <Input id="qa-edit-location" value={data.location} maxLength={255} onChange={(e) => setData('location', e.target.value)} />
                <InputError message={errors.location} />
            </div>

            {done && (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="qa-result">Résultat</Label>
                        <Select value={data.result_id} onValueChange={(v) => setData('result_id', v)}>
                            <SelectTrigger id="qa-result">
                                <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                                {loaded.results.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.result_id} />
                    </div>
                    <ReportField value={data.report} onChange={(v) => setData('report', v)} error={errors.report} />
                </>
            )}

            <DialogFooter>
                <Button type="button" variant="outline" onClick={onDone}>
                    Annuler
                </Button>
                <Button type="submit" disabled={processing || !data.type_id}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Enregistrer
                </Button>
            </DialogFooter>
        </form>
    );
}

function ReportField({ value, onChange, error }: { value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor="qa-report">Compte rendu</Label>
            <textarea
                id="qa-report"
                rows={3}
                maxLength={10000}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
            />
            <InputError message={error} />
        </div>
    );
}

export function NextFollowUp({
    types,
    typeId,
    dueAt,
    onChange,
    errors,
}: {
    types: TypeOption[];
    typeId: string;
    dueAt: string;
    onChange: (field: 'next_type_id' | 'next_due_at', value: string) => void;
    errors: Partial<Record<string, string>>;
}) {
    const today = todayIso();
    const shortcuts = [
        { label: 'Demain', value: shiftDay(today, 1) },
        { label: 'Dans 1 semaine', value: shiftDay(today, 7) },
        { label: 'Dans 1 mois', value: shiftDay(today, 0, 1) },
    ];
    const pick = (value: string) => {
        onChange('next_due_at', value);
        if (!typeId) onChange('next_type_id', types.find((t) => t.code === 'rappel')?.value ?? types[0]?.value ?? '');
    };

    return (
        <fieldset className="grid gap-3 rounded-xl border p-3">
            <legend className="px-1 text-sm font-semibold">Prochaine relance</legend>
            <div className="flex flex-wrap gap-2">
                {shortcuts.map((s) => (
                    <Button
                        key={s.label}
                        type="button"
                        size="sm"
                        variant={dueAt === s.value ? 'default' : 'outline'}
                        aria-pressed={dueAt === s.value}
                        onClick={() => pick(s.value)}
                    >
                        {s.label}
                    </Button>
                ))}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="qa-next-type">Type</Label>
                    <Select value={typeId} onValueChange={(v) => onChange('next_type_id', v)}>
                        <SelectTrigger id="qa-next-type">
                            <SelectValue placeholder="Aucune relance" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.next_type_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="qa-next-due">Date</Label>
                    <Input id="qa-next-due" type="date" min={today} value={dueAt} onChange={(e) => onChange('next_due_at', e.target.value)} />
                    <InputError message={errors.next_due_at} />
                </div>
            </div>
            {(typeId || dueAt) && (
                <button
                    type="button"
                    className={cn('text-muted-foreground justify-self-start text-xs underline')}
                    onClick={() => {
                        onChange('next_type_id', '');
                        onChange('next_due_at', '');
                    }}
                >
                    Pas de relance
                </button>
            )}
        </fieldset>
    );
}

/** Entreprise or person picked through the global search (at least 2 letters). */
export function FicheSearch({ onPick, hasFiche }: { onPick: (kind: 'organisation' | 'person', fiche: Fiche) => void; hasFiche: boolean }) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<{ organisations: Fiche[]; people: Fiche[] }>({ organisations: [], people: [] });

    const reset = useCallback(() => {
        setTerm('');
        setHits({ organisations: [], people: [] });
    }, []);

    useEffect(() => {
        if (term.trim().length < 2) {
            setHits({ organisations: [], people: [] });
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`${route('crm.search')}?${new URLSearchParams({ q: term })}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => json && setHits({ organisations: json.organisations ?? [], people: json.people ?? [] }))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    return (
        <div className="grid gap-1">
            <Input
                type="search"
                aria-label="Rechercher une entreprise ou une personne"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder={hasFiche ? 'Ajouter une entreprise ou une personne…' : 'Rechercher une entreprise ou une personne (2 lettres)'}
            />
            <ul className="grid gap-1 text-sm">
                {hits.organisations.map((h) => (
                    <li key={h.id}>
                        <button
                            type="button"
                            className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                            onClick={() => {
                                onPick('organisation', { id: h.id, name: h.name });
                                reset();
                            }}
                        >
                            {h.name} <span className="text-muted-foreground">· Entreprise</span>
                        </button>
                    </li>
                ))}
                {hits.people.map((h) => (
                    <li key={h.id}>
                        <button
                            type="button"
                            className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                            onClick={() => {
                                onPick('person', { id: h.id, name: h.name });
                                reset();
                            }}
                        >
                            {h.name} <span className="text-muted-foreground">· Personne</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
