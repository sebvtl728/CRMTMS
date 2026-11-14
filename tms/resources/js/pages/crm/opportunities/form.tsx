import { OpportunityTrainingsField, quantityInput, type TrainingLineInput, type TrainingOption } from '@/components/crm/opportunity-trainings';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { amountInput, itemError, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Account {
    type: 'organisation' | 'person';
    id: string;
    name: string | null;
    individual: boolean;
}

interface StageOption extends Option {
    probability: number;
}

interface FormProps {
    opportunity: {
        id: string;
        title: string;
        das_id: string;
        stage: string | null;
        probability: number;
        amount: string | null;
        expected_close_on: string | null;
        priority_id: string | null;
        funding_mode_id: string | null;
        responsible_user_id: string | null;
        contact_ids: string[];
        training_lines: TrainingLineInput[];
    } | null;
    account: Account | null;
    defaults: {
        das_id: string | null;
        stage_id: string | null;
        funding_mode_id: string | null;
        responsible_user_id: string | null;
        contact_ids: string[];
    } | null;
    contactOptions: Option[];
    options: {
        das: Option[];
        stage: StageOption[];
        priority: Option[];
        funding_mode: Option[];
        responsible: Option[];
        training: TrainingOption[];
    };
}

type OpportunityForm = {
    organisation_id: string;
    person_id: string;
    title: string;
    das_id: string;
    stage_id: string;
    probability: string;
    amount: string;
    expected_close_on: string;
    priority_id: string;
    funding_mode_id: string;
    responsible_user_id: string;
    contact_ids: string[];
    training_lines: TrainingLineInput[];
};

const NONE = 'none';

interface SearchHit {
    id: string;
    name: string;
    subtitle: string | null;
}

/** Choice of the entreprise or the particulier (creation from the list). */
function AccountPicker() {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<{ organisations: SearchHit[]; people: SearchHit[] }>({ organisations: [], people: [] });

    useEffect(() => {
        if (term.trim().length < 2) {
            setHits({ organisations: [], people: [] });
            return;
        }
        const controller = new AbortController();
        const get = (url: string) =>
            fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal }).then((r) => (r.ok ? r.json() : null));
        const timer = setTimeout(() => {
            // Entreprises from the global search; people: particuliers only (an interlocuteur's affaire belongs to his entreprise).
            Promise.all([
                get(`${route('crm.search')}?${new URLSearchParams({ q: term })}`),
                get(`${route('crm.people.search')}?${new URLSearchParams({ q: term, individual: '1' })}`),
            ])
                .then(([global, people]) =>
                    setHits({
                        organisations: global?.organisations ?? [],
                        people: ((people?.people ?? []) as { id: string; name: string; email: string | null }[]).map((p) => ({
                            id: p.id,
                            name: p.name,
                            subtitle: p.email,
                        })),
                    }),
                )
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    const pick = (key: 'organisation' | 'person', id: string) => router.get(route('crm.opportunities.create'), { [key]: id });

    return (
        <fieldset className="grid gap-3 rounded-xl border p-4">
            <legend className="px-2 text-sm font-semibold">Compte</legend>
            <Label htmlFor="account_search">Entreprise ou particulier *</Label>
            <Input
                id="account_search"
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder="Tapez au moins 2 lettres"
                autoFocus
            />
            <ul className="grid gap-1 text-sm">
                {hits.organisations.map((h) => (
                    <li key={h.id}>
                        <button
                            type="button"
                            className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                            onClick={() => pick('organisation', h.id)}
                        >
                            {h.name} <span className="text-muted-foreground">· Entreprise{h.subtitle ? ` · ${h.subtitle}` : ''}</span>
                        </button>
                    </li>
                ))}
                {hits.people.map((h) => (
                    <li key={h.id}>
                        <button type="button" className="hover:bg-muted w-full rounded px-2 py-1 text-left" onClick={() => pick('person', h.id)}>
                            {h.name} <span className="text-muted-foreground">· Particulier{h.subtitle ? ` · ${h.subtitle}` : ''}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </fieldset>
    );
}

export default function OpportunityFormPage({ opportunity, account, defaults, contactOptions, options }: FormProps) {
    const editing = opportunity !== null;

    const { data, setData, post, put, processing, errors } = useForm<OpportunityForm>({
        organisation_id: account?.type === 'organisation' ? account.id : '',
        person_id: account?.type === 'person' ? account.id : '',
        title: opportunity?.title ?? '',
        das_id: opportunity?.das_id ?? defaults?.das_id ?? '',
        stage_id: defaults?.stage_id ?? '',
        probability: opportunity ? String(opportunity.probability) : '',
        amount: amountInput(opportunity?.amount),
        expected_close_on: opportunity?.expected_close_on ?? '',
        priority_id: opportunity?.priority_id ?? '',
        funding_mode_id: opportunity?.funding_mode_id ?? defaults?.funding_mode_id ?? '',
        responsible_user_id: opportunity?.responsible_user_id ?? defaults?.responsible_user_id ?? '',
        contact_ids: opportunity?.contact_ids ?? defaults?.contact_ids ?? [],
        training_lines: (opportunity?.training_lines ?? []).map((l) => ({ training_id: l.training_id, quantity: quantityInput(l.quantity) })),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Opportunités', href: '/crm/opportunities' },
        { title: editing ? 'Modifier' : 'Nouvelle opportunité', href: '#' },
    ];
    const stageDefault = options.stage.find((s) => s.value === data.stage_id)?.probability;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('crm.opportunities.update', opportunity.id), { preserveScroll: true });
        } else {
            post(route('crm.opportunities.store'), { preserveScroll: true });
        }
    };

    const pick = (key: keyof OpportunityForm & string, label: string, list: Option[], noneLabel: string | null = 'Non renseigné') => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Select value={(data[key] as string) || NONE} onValueChange={(v) => setData(key, (v === NONE ? '' : v) as never)}>
                <SelectTrigger id={key}>
                    <SelectValue placeholder="Choisissez" />
                </SelectTrigger>
                <SelectContent>
                    {noneLabel !== null && <SelectItem value={NONE}>{noneLabel}</SelectItem>}
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

    const toggleContact = (id: string, checked: boolean) =>
        setData('contact_ids', checked ? [...data.contact_ids, id] : data.contact_ids.filter((c) => c !== id));

    const cancelHref = editing
        ? route('crm.opportunities.show', opportunity.id)
        : account
          ? account.type === 'organisation'
              ? route('crm.organisations.show', account.id)
              : route('crm.people.show', account.id)
          : route('crm.opportunities.index');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? "Modifier l'opportunité" : 'Nouvelle opportunité'} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={editing ? "Modifier l'opportunité" : 'Nouvelle opportunité'}
                    description={
                        account
                            ? `${account.type === 'organisation' ? 'Entreprise' : 'Personne'} : ${account.name ?? '—'}`
                            : 'Choisissez d’abord le compte.'
                    }
                />

                {!account ? (
                    <AccountPicker />
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                        <InputError message={(errors as Record<string, string | undefined>).account ?? errors.organisation_id ?? errors.person_id} />

                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                            <legend className="px-2 text-sm font-semibold">Affaire</legend>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="title">Objet *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    maxLength={200}
                                    required
                                    autoFocus
                                    placeholder="Ex. Formation HACCP 3 jours"
                                />
                                <InputError message={errors.title} />
                            </div>
                            {pick('das_id', 'DAS *', options.das, null)}
                            {editing ? (
                                <div className="grid gap-2">
                                    <Label>Étape</Label>
                                    <p className="text-sm">{opportunity.stage ?? '—'} (changez-la depuis la fiche)</p>
                                </div>
                            ) : (
                                pick('stage_id', 'Étape *', options.stage, null)
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="amount">Montant estimé (€)</Label>
                                <Input
                                    id="amount"
                                    inputMode="decimal"
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    placeholder="12 500,00"
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="probability">Probabilité (%)</Label>
                                <Input
                                    id="probability"
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={data.probability}
                                    onChange={(e) => setData('probability', e.target.value)}
                                    placeholder={stageDefault !== undefined ? `${stageDefault} (selon l'étape)` : 'Selon l’étape'}
                                />
                                <InputError message={errors.probability} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="expected_close_on">Clôture prévue</Label>
                                <Input
                                    id="expected_close_on"
                                    type="date"
                                    value={data.expected_close_on}
                                    onChange={(e) => setData('expected_close_on', e.target.value)}
                                />
                                <InputError message={errors.expected_close_on} />
                            </div>
                            {pick('priority_id', 'Priorité', options.priority)}
                            {pick(
                                'funding_mode_id',
                                account.individual ? 'Mode de financement (particulier)' : 'Mode de financement',
                                options.funding_mode,
                            )}
                            {pick('responsible_user_id', 'Responsable', options.responsible, 'Sans responsable')}
                        </fieldset>

                        <OpportunityTrainingsField
                            lines={data.training_lines}
                            options={options.training}
                            errors={errors as Record<string, string | undefined>}
                            opportunityId={opportunity?.id ?? null}
                            onChange={(lines) => setData('training_lines', lines)}
                            // No DAS chosen yet: the DAS of the first training (the server does the same).
                            onAdded={(option) => setData((d) => (d.das_id ? d : { ...d, das_id: option.das_id }))}
                            onApplyAmount={(amount) => setData('amount', amount)}
                        />

                        {contactOptions.length > 0 && (
                            <fieldset className="grid gap-2 rounded-xl border p-4">
                                <legend className="px-2 text-sm font-semibold">Interlocuteurs</legend>
                                {contactOptions.map((c) => (
                                    <Label key={c.value} className="flex items-center gap-2 font-normal">
                                        <Checkbox
                                            checked={data.contact_ids.includes(c.value)}
                                            onCheckedChange={(v) => toggleContact(c.value, v === true)}
                                        />
                                        {c.label}
                                    </Label>
                                ))}
                                <InputError message={errors.contact_ids ?? itemError(errors, 'contact_ids')} />
                            </fieldset>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Enregistrer
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <Link href={cancelHref}>Annuler</Link>
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
