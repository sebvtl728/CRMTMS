import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { pickOnEnter, SearchHint } from '@/components/sessions/search-hits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Entreprise {
    id: string;
    label: string;
}

type Kind = 'intra' | 'inter' | 'individual';

interface FormProps {
    session: {
        id: string;
        number: string;
        training_id: string;
        kind: Kind;
        modality_id: string;
        organisation: Entreprise | null;
        starts_on: string;
        ends_on: string;
        planned_hours: string | null;
        location_kind: 'address' | 'client' | 'remote';
        location_text: string | null;
        capacity: number | null;
        responsible_user_id: string | null;
        status: string;
        notes: string | null;
    } | null;
    defaults: { kind: Kind; organisation: Entreprise | null; responsible_user_id: string } | null;
    options: {
        training: (Option & { hours: string | null })[];
        kind: Option[];
        modality: Option[];
        default_modality: string | null;
        location: Option[];
        status: Option[];
        responsible: Option[];
    };
}

type SessionForm = {
    training_id: string;
    kind: string;
    modality_id: string;
    organisation_id: string;
    starts_on: string;
    ends_on: string;
    planned_hours: string;
    location_kind: string;
    location_text: string;
    capacity: string;
    responsible_user_id: string;
    status: string;
    notes: string;
};

const NONE = 'none';

const KIND_LABELS: Record<string, string> = {
    intra: 'Intra (une entreprise)',
    inter: 'Inter (plusieurs entreprises ou particuliers)',
    individual: 'Individuel (un seul stagiaire)',
};

const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

/** Stored hours (« 21.00 ») as typed (« 21 », « 17,5 »). */
function hoursInput(value: string | null | undefined): string {
    if (!value) return '';

    return value.replace('.', ',').replace(/,?0+$/, '');
}

/** Search of the entreprise of an intra session (identity only, whole organisme). */
function EntreprisePicker({ value, onPick }: { value: Entreprise | null; onPick: (e: Entreprise | null) => void }) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<Entreprise[]>([]);
    const [searched, setSearched] = useState(false);

    useEffect(() => {
        setSearched(false);
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`${route('sessions.organisations')}?${new URLSearchParams({ q: term })}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => {
                    setSearched(true);
                    setHits((json?.entreprises ?? []) as Entreprise[]);
                })
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span>{value.label}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => onPick(null)}>
                    Changer
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-1">
            <Input
                id="organisation_search"
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                onKeyDown={(e) => pickOnEnter(e, term, hits, (h) => h.label, onPick)}
                placeholder="Nom, ville ou SIRET (2 lettres au moins)"
            />
            {hits.length > 0 && (
                <ul className="grid gap-1 rounded-md border p-1 text-sm">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button type="button" className="hover:bg-muted w-full rounded px-2 py-1 text-left" onClick={() => onPick(h)}>
                                {h.label}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <SearchHint term={term} count={hits.length} searched={searched} />
        </div>
    );
}

export default function SessionFormPage({ session, defaults, options }: FormProps) {
    const editing = session !== null;
    const [organisation, setOrganisation] = useState<Entreprise | null>(session?.organisation ?? defaults?.organisation ?? null);

    const { data, setData, post, put, processing, errors } = useForm<SessionForm>({
        training_id: session?.training_id ?? '',
        kind: session?.kind ?? defaults?.kind ?? 'inter',
        modality_id: session?.modality_id ?? options.default_modality ?? '',
        organisation_id: session?.organisation?.id ?? defaults?.organisation?.id ?? '',
        starts_on: session?.starts_on ?? '',
        ends_on: session?.ends_on ?? '',
        planned_hours: hoursInput(session?.planned_hours),
        location_kind: session?.location_kind ?? 'address',
        location_text: session?.location_text ?? '',
        capacity: session?.capacity ? String(session.capacity) : '',
        responsible_user_id: session?.responsible_user_id ?? defaults?.responsible_user_id ?? '',
        status: session?.status ?? 'planned',
        notes: session?.notes ?? '',
    });

    const title = editing ? `Modifier la session ${session.number}` : 'Nouvelle session';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sessions', href: '/sessions' },
        { title: editing ? session.number : 'Nouvelle session', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('sessions.update', session.id), { preserveScroll: true });
        } else {
            post(route('sessions.store'), { preserveScroll: true });
        }
    };

    const pickTraining = (id: string) => {
        setData((d) => {
            const hours = options.training.find((t) => t.value === id)?.hours;
            // The hours follow the training until typed.
            const typed = d.planned_hours !== '' && d.planned_hours !== hoursInput(options.training.find((t) => t.value === d.training_id)?.hours);

            return { ...d, training_id: id, planned_hours: typed ? d.planned_hours : hoursInput(hours) };
        });
    };

    const pickOrganisation = (e: Entreprise | null) => {
        setOrganisation(e);
        setData('organisation_id', e?.id ?? '');
    };

    const select = (key: keyof SessionForm, label: string, list: Option[], noneLabel: string | null = null) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Select value={data[key] || (noneLabel ? NONE : undefined)} onValueChange={(v) => setData(key, v === NONE ? '' : v)}>
                <SelectTrigger id={key}>
                    <SelectValue placeholder="Choisissez" />
                </SelectTrigger>
                <SelectContent>
                    {noneLabel && <SelectItem value={NONE}>{noneLabel}</SelectItem>}
                    {list.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading title={title} />

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Formation</legend>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="training_id">Formation du catalogue *</Label>
                            <Select value={data.training_id || undefined} onValueChange={pickTraining}>
                                <SelectTrigger id="training_id">
                                    <SelectValue placeholder="Choisissez" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.training.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.training_id} />
                        </div>

                        <fieldset className="grid gap-2 md:col-span-2">
                            <legend className="mb-1 text-sm font-medium">Type *</legend>
                            <div className="flex flex-wrap gap-6">
                                {options.kind.map((k) => (
                                    <label key={k.value} className="flex items-center gap-2 text-sm">
                                        <input type="radio" name="kind" checked={data.kind === k.value} onChange={() => setData('kind', k.value)} />
                                        {KIND_LABELS[k.value] ?? k.label}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.kind} />
                        </fieldset>

                        {data.kind !== 'inter' ? (
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="organisation_search">{data.kind === 'intra' ? 'Entreprise *' : 'Entreprise (facultatif)'}</Label>
                                <EntreprisePicker value={organisation} onPick={pickOrganisation} />
                                <InputError message={errors.organisation_id} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="capacity">Nombre de places *</Label>
                                <Input
                                    id="capacity"
                                    type="number"
                                    min={1}
                                    max={1000}
                                    value={data.capacity}
                                    onChange={(e) => setData('capacity', e.target.value)}
                                />
                                <InputError message={errors.capacity} />
                            </div>
                        )}
                    </fieldset>

                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-3">
                        <legend className="px-2 text-sm font-semibold">Dates et lieu</legend>
                        <div className="grid gap-2">
                            <Label htmlFor="starts_on">Début *</Label>
                            <Input
                                id="starts_on"
                                type="date"
                                value={data.starts_on}
                                onChange={(e) =>
                                    setData((d) => ({ ...d, starts_on: e.target.value, ends_on: d.ends_on === '' ? e.target.value : d.ends_on }))
                                }
                            />
                            <InputError message={errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ends_on">Fin *</Label>
                            <Input
                                id="ends_on"
                                type="date"
                                min={data.starts_on || undefined}
                                value={data.ends_on}
                                onChange={(e) => setData('ends_on', e.target.value)}
                            />
                            <InputError message={errors.ends_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="planned_hours">Heures prévues</Label>
                            <Input
                                id="planned_hours"
                                inputMode="decimal"
                                value={data.planned_hours}
                                onChange={(e) => setData('planned_hours', e.target.value)}
                                placeholder="Durée de la formation"
                            />
                            <InputError message={errors.planned_hours} />
                        </div>
                        {select('modality_id', 'Modalité *', options.modality)}
                        {select('location_kind', 'Lieu *', options.location)}
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="location_text">
                                {data.location_kind === 'address' ? 'Adresse *' : data.location_kind === 'remote' ? 'Lien ou précision' : 'Précision'}
                            </Label>
                            <Input
                                id="location_text"
                                value={data.location_text}
                                maxLength={500}
                                onChange={(e) => setData('location_text', e.target.value)}
                                placeholder={data.location_kind === 'address' ? '12 rue des Écoles, 69001 Lyon' : undefined}
                            />
                            <InputError message={errors.location_text} />
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Suivi</legend>
                        {select('responsible_user_id', 'Responsable', options.responsible, 'Aucun')}
                        {select('status', 'État *', options.status)}
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="notes">Notes</Label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className={textareaClass}
                            />
                            <InputError message={errors.notes} />
                        </div>
                    </fieldset>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={editing ? route('sessions.show', session.id) : route('sessions.index')}>Annuler</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
