import { DuplicateWarning, type DuplicateCandidate } from '@/components/crm/duplicate-warning';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { itemError, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

type PersonForm = {
    civility: string;
    first_name: string;
    last_name: string;
    is_individual: boolean;
    status: string;
    email: string;
    email_secondary: string;
    phone: string;
    mobile: string;
    address_line1: string;
    address_line2: string;
    postal_code: string;
    city: string;
    country: string;
    birth_date: string;
    professional_situation_id: string;
    funding_mode_id: string;
    origin_id: string;
    responsible_user_id: string;
    das_ids: string[];
    project: string;
};

interface PersonData extends Record<string, string | boolean | string[] | null> {
    id: string;
    is_individual: boolean;
    das_ids: string[];
}

interface FormProps {
    person: PersonData | null;
    options: {
        status: Option[];
        professional_situation: Option[];
        funding_mode: Option[];
        origin: Option[];
        das: Option[];
        responsible: Option[];
    };
    duplicates: DuplicateCandidate[];
    canMerge: boolean;
}

const NONE = 'none';

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

export default function PersonFormPage({ person, options, duplicates, canMerge }: FormProps) {
    const editing = person !== null;
    const str = (key: string, fallback = '') => (person?.[key] as string | null | undefined) ?? fallback;

    const { data, setData, post, put, processing, errors, transform } = useForm<PersonForm>({
        civility: str('civility'),
        first_name: str('first_name'),
        last_name: str('last_name'),
        // A person created from the « Personnes » menu is most often a particulier.
        is_individual: person?.is_individual ?? true,
        status: str('status', 'prospect'),
        email: str('email'),
        email_secondary: str('email_secondary'),
        phone: str('phone'),
        mobile: str('mobile'),
        address_line1: str('address_line1'),
        address_line2: str('address_line2'),
        postal_code: str('postal_code'),
        city: str('city'),
        country: str('country', 'FR'),
        birth_date: str('birth_date'),
        professional_situation_id: str('professional_situation_id'),
        funding_mode_id: str('funding_mode_id'),
        origin_id: str('origin_id'),
        responsible_user_id: str('responsible_user_id'),
        das_ids: person?.das_ids ?? [],
        project: str('project'),
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Personnes', href: '/crm/people' },
        { title: editing ? 'Modifier' : 'Nouvelle personne', href: '#' },
    ];

    const duplicateError = (usePage().props.errors as Record<string, string | undefined>).duplicates;

    /** Saves; `extra` carries the answer to the duplicate warning (confirm_duplicate, merge_into). */
    const send = (extra: Record<string, string> = {}) => {
        transform((d) => ({ ...d, ...extra }));
        if (editing) {
            put(route('crm.people.update', person.id), { preserveScroll: true });
        } else {
            post(route('crm.people.store'), { preserveScroll: true });
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send();
    };

    const text = (key: keyof PersonForm & string, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <Field id={key} label={label} error={errors[key]}>
            <Input id={key} value={data[key] as string} onChange={(e) => setData(key, e.target.value as never)} {...props} />
        </Field>
    );

    const pick = (key: keyof PersonForm & string, label: string, list: Option[], noneLabel = 'Non renseigné') => (
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
            <Head title={editing ? 'Modifier la personne' : 'Nouvelle personne'} />

            <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6 p-4" noValidate>
                <Heading
                    title={editing ? 'Modifier la personne' : 'Nouvelle personne'}
                    description="Seul le nom est obligatoire : le reste peut être complété plus tard. Pour ajouter un interlocuteur, utilisez plutôt la fiche de son entreprise."
                />

                <Section title="Identité">
                    {pick(
                        'civility',
                        'Civilité',
                        [
                            { value: 'M.', label: 'M.' },
                            { value: 'Mme', label: 'Mme' },
                        ],
                        'Non précisée',
                    )}
                    <div className="hidden md:block" />
                    {text('first_name', 'Prénom', { autoComplete: 'off' })}
                    <Field id="last_name" label="Nom *" error={errors.last_name}>
                        <Input
                            id="last_name"
                            value={data.last_name}
                            onChange={(e) => setData('last_name', e.target.value)}
                            required
                            autoFocus
                            maxLength={255}
                            autoComplete="off"
                        />
                    </Field>
                    <Label className="flex items-center gap-2 font-normal md:col-span-2">
                        <Checkbox checked={data.is_individual} onCheckedChange={(c) => setData('is_individual', c === true)} />
                        Particulier (client ou prospect à titre personnel, sans entreprise)
                    </Label>
                    {pick('status', 'Statut', options.status, 'Prospect')}
                    {pick('responsible_user_id', 'Responsable', options.responsible, 'Sans responsable')}
                </Section>

                <Section title="Contact">
                    {text('email', 'E-mail', { type: 'email', autoComplete: 'off' })}
                    {text('email_secondary', 'E-mail secondaire', { type: 'email', autoComplete: 'off' })}
                    {text('phone', 'Téléphone', { type: 'tel', maxLength: 30, autoComplete: 'off' })}
                    {text('mobile', 'Mobile', { type: 'tel', maxLength: 30, autoComplete: 'off' })}
                </Section>

                <Section title="Adresse">
                    <div className="md:col-span-2">{text('address_line1', 'Adresse')}</div>
                    <div className="md:col-span-2">{text('address_line2', "Complément d'adresse")}</div>
                    {text('postal_code', 'Code postal', { maxLength: 10 })}
                    {text('city', 'Ville')}
                    {text('country', 'Pays (code)', { maxLength: 2 })}
                </Section>

                <Section title="Profil et projet">
                    {pick('professional_situation_id', 'Situation professionnelle', options.professional_situation)}
                    {pick('funding_mode_id', 'Mode de financement', options.funding_mode)}
                    {pick('origin_id', 'Origine', options.origin)}
                    {text('birth_date', 'Date de naissance', { type: 'date', max: new Date().toISOString().slice(0, 10) })}
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
                    <Field id="project" label="Projet" error={errors.project} className="md:col-span-2">
                        <textarea
                            id="project"
                            rows={4}
                            value={data.project}
                            onChange={(e) => setData('project', e.target.value)}
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                        />
                    </Field>
                </Section>

                {duplicateError && (
                    <DuplicateWarning
                        type="person"
                        duplicates={duplicates}
                        canMerge={canMerge}
                        currentId={editing ? person.id : null}
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
                        <Link href={editing ? route('crm.people.show', person.id) : route('crm.people.index')}>Annuler</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
