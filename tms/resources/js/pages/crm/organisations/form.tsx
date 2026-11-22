import { DuplicateWarning, type DuplicateCandidate } from '@/components/crm/duplicate-warning';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cleanSiret, isValidSiret, itemError, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

type OrganisationForm = {
    name: string;
    siret: string;
    naf_code: string;
    idcc: string;
    status: string;
    legal_form_id: string;
    sector_id: string;
    establishment_type_id: string;
    company_size_id: string;
    origin_id: string;
    responsible_user_id: string;
    das_ids: string[];
    email: string;
    phone: string;
    website: string;
    address_line1: string;
    address_line2: string;
    postal_code: string;
    city: string;
    country: string;
    notes_summary: string;
};

interface OrganisationData extends Record<string, string | string[] | null> {
    id: string;
    das_ids: string[];
}

interface FormProps {
    organisation: OrganisationData | null;
    options: {
        status: Option[];
        legal_form: Option[];
        sector: Option[];
        establishment_type: Option[];
        company_size: Option[];
        origin: Option[];
        das: Option[];
        responsible: Option[];
    };
    duplicates: DuplicateCandidate[];
    canMerge: boolean;
}

const NONE = 'none';

interface SireneData {
    name: string;
    naf_code: string | null;
    address_line1: string | null;
    postal_code: string | null;
    city: string | null;
    legal_form_id: string | null;
    company_size_id: string | null;
}

/** Form field => label shown in the « Champs complétés » message. */
const SIRENE_FIELDS: Array<[keyof SireneData & keyof OrganisationForm, string]> = [
    ['name', 'raison sociale'],
    ['naf_code', 'code NAF'],
    ['legal_form_id', 'forme juridique'],
    ['company_size_id', 'taille'],
    ['address_line1', 'adresse'],
    ['postal_code', 'code postal'],
    ['city', 'ville'],
];

function Field({
    id,
    label,
    error,
    children,
    className,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid gap-2 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
            <legend className="px-2 text-sm font-semibold">{title}</legend>
            {children}
        </fieldset>
    );
}

export default function OrganisationFormPage({ organisation, options, duplicates, canMerge }: FormProps) {
    const editing = organisation !== null;
    const str = (key: string, fallback = '') => (organisation?.[key] as string | null | undefined) ?? fallback;

    const { data, setData, post, put, processing, errors, transform } = useForm<OrganisationForm>({
        name: str('name'),
        siret: str('siret'),
        naf_code: str('naf_code'),
        idcc: str('idcc'),
        status: str('status', 'prospect'),
        legal_form_id: str('legal_form_id'),
        sector_id: str('sector_id'),
        establishment_type_id: str('establishment_type_id'),
        company_size_id: str('company_size_id'),
        origin_id: str('origin_id'),
        responsible_user_id: str('responsible_user_id'),
        das_ids: organisation?.das_ids ?? [],
        email: str('email'),
        phone: str('phone'),
        website: str('website'),
        address_line1: str('address_line1'),
        address_line2: str('address_line2'),
        postal_code: str('postal_code'),
        city: str('city'),
        country: str('country', 'FR'),
        notes_summary: str('notes_summary'),
    });

    const [sirene, setSirene] = useState<{ loading: boolean; error: string | null; filled: string[] | null }>({
        loading: false,
        error: null,
        filled: null,
    });

    /** Asks the directory for the SIRET and fills ONLY the empty fields; the user validates by saving. */
    const completeFromSiret = async () => {
        setSirene({ loading: true, error: null, filled: null });
        try {
            const response = await fetch(`/crm/sirene/${cleanSiret(data.siret)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                setSirene({
                    loading: false,
                    error: body.message ?? "Service de l'annuaire indisponible, saisissez les informations manuellement.",
                    filled: null,
                });

                return;
            }

            const found: SireneData = body.data;
            const filled: string[] = [];
            SIRENE_FIELDS.forEach(([key, label]) => {
                const value = found[key];
                if (value && !data[key]) {
                    setData(key, value as never);
                    filled.push(label);
                }
            });
            setSirene({ loading: false, error: null, filled });
        } catch {
            setSirene({
                loading: false,
                error: "Service de l'annuaire indisponible, saisissez les informations manuellement.",
                filled: null,
            });
        }
    };

    const existingId = (usePage().props.errors as Record<string, string | undefined>).siret_existing_id;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Entreprises', href: '/crm/organisations' },
        { title: editing ? 'Modifier' : 'Nouvelle entreprise', href: '#' },
    ];

    const duplicateError = (usePage().props.errors as Record<string, string | undefined>).duplicates;

    /** Saves; `extra` carries the answer to the duplicate warning (confirm_duplicate, merge_into). */
    const send = (extra: Record<string, string> = {}) => {
        transform((d) => ({ ...d, ...extra }));
        if (editing) {
            put(route('crm.organisations.update', organisation.id), { preserveScroll: true });
        } else {
            post(route('crm.organisations.store'), { preserveScroll: true });
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send();
    };

    const text = (key: keyof OrganisationForm & string, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <Field id={key} label={label} error={errors[key]}>
            <Input id={key} value={data[key] as string} onChange={(e) => setData(key, e.target.value as never)} {...props} />
        </Field>
    );

    const pick = (key: keyof OrganisationForm & string, label: string, list: Option[], noneLabel = 'Non renseigné') => (
        <Field id={key} label={label} error={errors[key]}>
            <Select value={(data[key] as string) || NONE} onValueChange={(v) => setData(key, (v === NONE ? '' : v) as never)}>
                <SelectTrigger id={key}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{noneLabel}</SelectItem>
                    {list.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );

    const toggleDas = (id: string, checked: boolean) => setData('das_ids', checked ? [...data.das_ids, id] : data.das_ids.filter((d) => d !== id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? "Modifier l'entreprise" : 'Nouvelle entreprise'} />

            <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6 p-4" noValidate>
                <Heading
                    title={editing ? "Modifier l'entreprise" : 'Nouvelle entreprise'}
                    description="Seul le nom est obligatoire : le reste peut être complété plus tard."
                />

                <Section title="Identité">
                    <Field id="name" label="Raison sociale *" error={errors.name} className="md:col-span-2">
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus maxLength={255} />
                    </Field>
                    <Field id="siret" label="SIRET" error={errors.siret}>
                        <Input
                            id="siret"
                            value={data.siret}
                            onChange={(e) => setData('siret', e.target.value)}
                            inputMode="numeric"
                            placeholder="14 chiffres"
                        />
                        {existingId && (
                            <Link href={route('crm.organisations.show', existingId)} className="text-sm underline">
                                Ouvrir l'entreprise existante
                            </Link>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            disabled={!isValidSiret(data.siret) || sirene.loading}
                            onClick={completeFromSiret}
                        >
                            {sirene.loading ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
                            Compléter depuis le SIRET
                        </Button>
                        <div aria-live="polite">
                            {sirene.error && <p className="text-sm text-red-600 dark:text-red-400">{sirene.error}</p>}
                            {sirene.filled !== null && (
                                <p className="text-muted-foreground text-sm">
                                    {sirene.filled.length > 0
                                        ? `Champs complétés : ${sirene.filled.join(', ')}. Vérifiez avant d'enregistrer.`
                                        : 'Aucun champ à compléter : les champs concernés sont déjà renseignés.'}
                                </p>
                            )}
                        </div>
                    </Field>
                    {text('naf_code', 'Code NAF', { placeholder: '5610A', maxLength: 6 })}
                    {text('idcc', 'IDCC (convention collective)', { placeholder: '1979', maxLength: 4 })}
                    {pick('status', 'Statut', options.status, 'Prospect')}
                    {pick('responsible_user_id', 'Responsable', options.responsible, 'Sans responsable')}
                </Section>

                <Section title="Classification">
                    {pick('legal_form_id', 'Forme juridique', options.legal_form)}
                    {pick('sector_id', "Secteur d'activité", options.sector)}
                    {pick('establishment_type_id', "Type d'établissement", options.establishment_type)}
                    {pick('company_size_id', 'Taille', options.company_size)}
                    {pick('origin_id', 'Origine', options.origin)}
                    <div className="grid gap-2">
                        <Label>DAS</Label>
                        <div className="grid gap-2">
                            {options.das.map((d) => (
                                <Label key={d.value} className="flex items-center gap-2 font-normal">
                                    <Checkbox checked={data.das_ids.includes(d.value)} onCheckedChange={(c) => toggleDas(d.value, c === true)} />
                                    {d.label}
                                </Label>
                            ))}
                        </div>
                        <InputError message={errors.das_ids ?? itemError(errors, 'das_ids')} />
                    </div>
                </Section>

                <Section title="Contact">
                    {text('email', 'E-mail', { type: 'email', autoComplete: 'off' })}
                    {text('phone', 'Téléphone', { type: 'tel', maxLength: 30 })}
                    {text('website', 'Site web', { placeholder: 'www.exemple.fr' })}
                </Section>

                <Section title="Adresse">
                    <div className="md:col-span-2">{text('address_line1', 'Adresse')}</div>
                    <div className="md:col-span-2">{text('address_line2', "Complément d'adresse")}</div>
                    {text('postal_code', 'Code postal', { maxLength: 10 })}
                    {text('city', 'Ville')}
                    {text('country', 'Pays (code)', { maxLength: 2 })}
                </Section>

                <Section title="Notes">
                    <Field id="notes_summary" label="Résumé" error={errors.notes_summary} className="md:col-span-2">
                        <textarea
                            id="notes_summary"
                            rows={4}
                            value={data.notes_summary}
                            onChange={(e) => setData('notes_summary', e.target.value)}
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                        />
                    </Field>
                </Section>

                {duplicateError && (
                    <DuplicateWarning
                        type="organisation"
                        duplicates={duplicates}
                        canMerge={canMerge}
                        currentId={editing ? organisation.id : null}
                        processing={processing}
                        onConfirm={() => send({ confirm_duplicate: '1' })}
                        onCreateAndMerge={(id) => send({ confirm_duplicate: '1', merge_into: id })}
                    />
                )}

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Enregistrer
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link href={editing ? route('crm.organisations.show', organisation.id) : route('crm.organisations.index')}>Annuler</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
