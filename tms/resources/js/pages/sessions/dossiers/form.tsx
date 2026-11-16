import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { displayCents, lineTotalCents } from '@/lib/dossiers';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Picked {
    id: string;
    label: string;
}

interface TrainingOption extends Option {
    title: string;
    das_id: string;
    price_company: string | null;
    price_individual: string | null;
}

type LineForm = {
    id: string | null;
    kind: 'training' | 'fee';
    training_id: string;
    label: string;
    quantity: string;
    unit: string;
    unit_price: string;
    discount_percent: string;
    pricing_mode: string;
};

interface FormProps {
    dossier: {
        id: string;
        number: string;
        cancelled: boolean;
        company_id: string;
        account: { type: 'organisation' | 'person'; id: string; label: string };
        contact: Picked | null;
        responsible_user_id: string | null;
        das_id: string;
        abandon_mode: string | null;
        abandon_dedit_percent: number | null;
        notes: string | null;
        lines: (Omit<LineForm, 'id' | 'training_id' | 'pricing_mode'> & { id: string; training_id: string | null; pricing_mode: string | null })[];
    } | null;
    defaults: { company_id: string | null; responsible_user_id: string } | null;
    options: {
        training: TrainingOption[];
        company: Option[];
        das: Option[];
        responsible: Option[];
        kind: Option[];
        unit: Option[];
        pricing_mode: Option[];
        abandon_rule: string;
    };
}

type DossierForm = {
    company_id: string;
    account_organisation_id: string;
    account_person_id: string;
    contact_person_id: string;
    responsible_user_id: string;
    das_id: string;
    abandon_mode: string;
    abandon_dedit_percent: string;
    notes: string;
    lines: LineForm[];
};

const NONE = 'none';
const ORGANISME = 'organisme';

const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

function emptyLine(kind: 'training' | 'fee'): LineForm {
    return {
        id: null,
        kind,
        training_id: '',
        label: '',
        quantity: '1',
        unit: kind === 'training' ? 'stagiaire' : 'forfait',
        unit_price: '',
        discount_percent: '',
        pricing_mode: kind === 'training' ? 'per_trainee' : '',
    };
}

/**
 * Search in the CRM (identity only, whole organisme): entreprises or people,
 * via the dossier endpoints.
 */
function FichePicker({
    id,
    kind,
    organisationId,
    value,
    onPick,
    placeholder,
}: {
    id: string;
    kind: 'organisation' | 'person';
    organisationId?: string;
    value: Picked | null;
    onPick: (p: Picked | null) => void;
    placeholder: string;
}) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<Picked[]>([]);

    useEffect(() => {
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            const params: Record<string, string> = { q: term };
            if (organisationId) params.organisation = organisationId;
            fetch(`${route(kind === 'organisation' ? 'dossiers.organisations' : 'dossiers.people')}?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => {
                    if (kind === 'organisation') {
                        setHits((json?.entreprises ?? []) as Picked[]);
                    } else {
                        const people = (json?.personnes ?? []) as {
                            id: string;
                            last_name: string;
                            first_name: string | null;
                            birth_date: string | null;
                            organisation_label: string | null;
                        }[];
                        setHits(
                            people.map((p) => ({
                                id: p.id,
                                label: [[p.first_name, p.last_name].filter(Boolean).join(' '), p.organisation_label].filter(Boolean).join(' · '),
                            })),
                        );
                    }
                })
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, kind, organisationId]);

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
            <Input id={id} type="search" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={placeholder} />
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
        </div>
    );
}

export default function DossierFormPage({ dossier, defaults, options }: FormProps) {
    const editing = dossier !== null;
    const [accountType, setAccountType] = useState<'organisation' | 'person'>(dossier?.account.type ?? 'organisation');
    const [account, setAccount] = useState<Picked | null>(dossier ? { id: dossier.account.id, label: dossier.account.label } : null);
    const [contact, setContact] = useState<Picked | null>(dossier?.contact ?? null);

    const { data, setData, post, put, processing, errors } = useForm<DossierForm>({
        company_id: dossier?.company_id ?? defaults?.company_id ?? '',
        account_organisation_id: dossier?.account.type === 'organisation' ? dossier.account.id : '',
        account_person_id: dossier?.account.type === 'person' ? dossier.account.id : '',
        contact_person_id: dossier?.contact?.id ?? '',
        responsible_user_id: dossier?.responsible_user_id ?? defaults?.responsible_user_id ?? '',
        das_id: dossier?.das_id ?? '',
        abandon_mode: dossier?.abandon_mode ?? '',
        abandon_dedit_percent: dossier?.abandon_dedit_percent ? String(dossier.abandon_dedit_percent) : '',
        notes: dossier?.notes ?? '',
        lines: dossier
            ? dossier.lines.map((l) => ({ ...l, training_id: l.training_id ?? '', pricing_mode: l.pricing_mode ?? '' }))
            : [emptyLine('training')],
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const title = editing ? `Modifier le dossier ${dossier.number}` : 'Nouveau dossier';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dossiers', href: '/dossiers' },
        { title: editing ? dossier.number : 'Nouveau dossier', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('dossiers.update', dossier.id), { preserveScroll: true });
        } else {
            post(route('dossiers.store'), { preserveScroll: true });
        }
    };

    const pickAccount = (type: 'organisation' | 'person', p: Picked | null) => {
        setAccountType(type);
        setAccount(p);
        setData((d) => ({
            ...d,
            account_organisation_id: type === 'organisation' ? (p?.id ?? '') : '',
            account_person_id: type === 'person' ? (p?.id ?? '') : '',
        }));
    };

    const pickContact = (p: Picked | null) => {
        setContact(p);
        setData('contact_person_id', p?.id ?? '');
    };

    const setLine = (index: number, changes: Partial<LineForm>) =>
        setData((d) => ({ ...d, lines: d.lines.map((l, i) => (i === index ? { ...l, ...changes } : l)) }));

    const titleOf = (id: string) => options.training.find((t) => t.value === id)?.title ?? '';

    const pickTraining = (index: number, id: string) => {
        const training = options.training.find((t) => t.value === id);
        setData((d) => {
            const line = d.lines[index];
            const price = accountType === 'person' ? training?.price_individual : training?.price_company;
            const lines = d.lines.map((l, i) =>
                i === index
                    ? { ...l, training_id: id, label: l.label === '' || l.label === titleOf(line.training_id) ? (training?.title ?? '') : l.label, unit_price: l.unit_price || (price ?? '') }
                    : l,
            );
            // The DAS of the dossier is proposed from the training of its first line.
            const das = d.das_id === '' && index === 0 && training ? training.das_id : d.das_id;

            return { ...d, lines, das_id: das };
        });
    };

    const total = data.lines.reduce<bigint | null>((sum, l) => {
        const line = lineTotalCents(l.quantity, l.unit_price, l.discount_percent);

        return sum === null || line === null ? null : sum + line;
    }, 0n);

    const select = (key: 'company_id' | 'das_id' | 'responsible_user_id', label: string, list: Option[], noneLabel: string | null = null) => (
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

            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading title={title} />

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Contractant</legend>
                        <fieldset className="grid gap-2 md:col-span-2">
                            <legend className="mb-1 text-sm font-medium">Type *</legend>
                            <div className="flex flex-wrap gap-6">
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" name="account_type" checked={accountType === 'organisation'} onChange={() => pickAccount('organisation', null)} />
                                    Entreprise
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="radio" name="account_type" checked={accountType === 'person'} onChange={() => pickAccount('person', null)} />
                                    Particulier
                                </label>
                            </div>
                        </fieldset>
                        <div className="grid gap-2">
                            <Label htmlFor="account_search">{accountType === 'organisation' ? 'Entreprise *' : 'Particulier *'}</Label>
                            <FichePicker
                                key={accountType}
                                id="account_search"
                                kind={accountType}
                                value={account}
                                onPick={(p) => pickAccount(accountType, p)}
                                placeholder={accountType === 'organisation' ? 'Nom, ville ou SIRET (2 lettres au moins)' : 'Nom ou e-mail (2 lettres au moins)'}
                            />
                            <InputError message={fieldErrors.account ?? errors.account_organisation_id ?? errors.account_person_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contact_search">Contact</Label>
                            <FichePicker
                                id="contact_search"
                                kind="person"
                                organisationId={accountType === 'organisation' ? account?.id : undefined}
                                value={contact}
                                onPick={pickContact}
                                placeholder="Nom ou e-mail (2 lettres au moins)"
                            />
                            <InputError message={errors.contact_person_id} />
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-3 rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Lignes</legend>
                        <InputError message={errors.lines} />
                        {data.lines.map((line, i) => (
                            <div key={line.id ?? `new-${i}`} className="grid gap-3 rounded-lg border p-3 md:grid-cols-6">
                                <div className="flex items-center justify-between gap-2 md:col-span-6">
                                    <span className="text-sm font-medium">{line.kind === 'training' ? 'Formation' : 'Frais'}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Supprimer la ligne ${i + 1}`}
                                        onClick={() => setData((d) => ({ ...d, lines: d.lines.filter((_, j) => j !== i) }))}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                                {line.kind === 'training' && (
                                    <div className="grid gap-1 md:col-span-3">
                                        <Label htmlFor={`lines.${i}.training_id`} className="text-xs">
                                            Formation du catalogue *
                                        </Label>
                                        <Select value={line.training_id || undefined} onValueChange={(v) => pickTraining(i, v)}>
                                            <SelectTrigger id={`lines.${i}.training_id`}>
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
                                        <InputError message={fieldErrors[`lines.${i}.training_id`]} />
                                    </div>
                                )}
                                <div className={`grid gap-1 ${line.kind === 'training' ? 'md:col-span-3' : 'md:col-span-6'}`}>
                                    <Label htmlFor={`lines.${i}.label`} className="text-xs">
                                        Libellé *
                                    </Label>
                                    <Input id={`lines.${i}.label`} value={line.label} maxLength={255} onChange={(e) => setLine(i, { label: e.target.value })} />
                                    <InputError message={fieldErrors[`lines.${i}.label`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`lines.${i}.quantity`} className="text-xs">
                                        Quantité *
                                    </Label>
                                    <Input id={`lines.${i}.quantity`} inputMode="decimal" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                                    <InputError message={fieldErrors[`lines.${i}.quantity`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`lines.${i}.unit`} className="text-xs">
                                        Unité *
                                    </Label>
                                    <Select
                                        value={line.unit}
                                        onValueChange={(v) =>
                                            setLine(i, line.kind === 'training' ? { unit: v, pricing_mode: v === 'stagiaire' ? 'per_trainee' : 'group' } : { unit: v })
                                        }
                                    >
                                        <SelectTrigger id={`lines.${i}.unit`}>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.unit.map((o) => (
                                                <SelectItem key={o.value} value={o.value}>
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={fieldErrors[`lines.${i}.unit`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`lines.${i}.unit_price`} className="text-xs">
                                        Prix unitaire HT (€) *
                                    </Label>
                                    <Input
                                        id={`lines.${i}.unit_price`}
                                        inputMode="decimal"
                                        value={line.unit_price}
                                        onChange={(e) => setLine(i, { unit_price: e.target.value })}
                                    />
                                    <InputError message={fieldErrors[`lines.${i}.unit_price`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`lines.${i}.discount_percent`} className="text-xs">
                                        Remise (%)
                                    </Label>
                                    <Input
                                        id={`lines.${i}.discount_percent`}
                                        inputMode="decimal"
                                        value={line.discount_percent}
                                        onChange={(e) => setLine(i, { discount_percent: e.target.value })}
                                    />
                                    <InputError message={fieldErrors[`lines.${i}.discount_percent`]} />
                                </div>
                                {line.kind === 'training' ? (
                                    <div className="grid gap-1">
                                        <Label htmlFor={`lines.${i}.pricing_mode`} className="text-xs">
                                            Mode de prix
                                        </Label>
                                        <Select value={line.pricing_mode || undefined} onValueChange={(v) => setLine(i, { pricing_mode: v })}>
                                            <SelectTrigger id={`lines.${i}.pricing_mode`}>
                                                <SelectValue placeholder="Selon l'unité" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.pricing_mode.map((o) => (
                                                    <SelectItem key={o.value} value={o.value}>
                                                        {o.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={fieldErrors[`lines.${i}.pricing_mode`]} />
                                    </div>
                                ) : (
                                    <div />
                                )}
                                <div className="grid content-end gap-1 text-right text-sm">
                                    <span className="text-muted-foreground text-xs">Total HT</span>
                                    {(() => {
                                        const cents = lineTotalCents(line.quantity, line.unit_price, line.discount_percent);

                                        return cents === null ? '—' : displayCents(cents);
                                    })()}
                                </div>
                            </div>
                        ))}
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={() => setData((d) => ({ ...d, lines: [...d.lines, emptyLine('training')] }))}>
                                    <Plus className="h-4 w-4" />
                                    Ligne de formation
                                </Button>
                                <Button type="button" variant="outline" size="sm" onClick={() => setData((d) => ({ ...d, lines: [...d.lines, emptyLine('fee')] }))}>
                                    <Plus className="h-4 w-4" />
                                    Frais (déplacement, repas…)
                                </Button>
                            </div>
                            <div className="text-sm font-semibold">Prix du dossier HT : {total === null ? '—' : displayCents(total)}</div>
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Suivi</legend>
                        {select('company_id', 'Société émettrice *', options.company)}
                        {select('das_id', 'DAS du dossier *', options.das)}
                        {select('responsible_user_id', 'Responsable', options.responsible, 'Aucun')}
                        <div className="grid gap-2">
                            <Label htmlFor="abandon_mode">Règle en cas d'abandon</Label>
                            <Select
                                value={data.abandon_mode || ORGANISME}
                                onValueChange={(v) => setData((d) => ({ ...d, abandon_mode: v === ORGANISME ? '' : v, abandon_dedit_percent: v === 'prorata_dedit' ? d.abandon_dedit_percent : '' }))}
                            >
                                <SelectTrigger id="abandon_mode">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ORGANISME}>Règle de l'organisme ({options.abandon_rule})</SelectItem>
                                    <SelectItem value="prorata">Prorata seulement</SelectItem>
                                    <SelectItem value="prorata_dedit">Prorata et dédit</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.abandon_mode} />
                        </div>
                        {data.abandon_mode === 'prorata_dedit' && (
                            <div className="grid gap-2">
                                <Label htmlFor="abandon_dedit_percent">Dédit (%) *</Label>
                                <Input
                                    id="abandon_dedit_percent"
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={data.abandon_dedit_percent}
                                    onChange={(e) => setData('abandon_dedit_percent', e.target.value)}
                                />
                                <InputError message={errors.abandon_dedit_percent} />
                            </div>
                        )}
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="notes">Notes</Label>
                            <textarea id="notes" rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} className={textareaClass} />
                            <InputError message={errors.notes} />
                        </div>
                    </fieldset>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={editing ? route('dossiers.show', dossier.id) : route('dossiers.index')}>Annuler</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
