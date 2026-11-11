import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Option {
    value: string;
    label: string;
}

type CompanyForm = {
    name: string;
    legal_form: string;
    scop_underlying_form: string;
    capital_amount: string;
    capital_variable: boolean;
    siret: string;
    rcs_city: string;
    vat_number: string;
    vat_regime: string;
    nda_number: string;
    address_line1: string;
    address_line2: string;
    address_postal_code: string;
    address_city: string;
    address_country: string;
    email: string;
    phone: string;
    is_default: boolean;
};

interface CompanyData extends Omit<CompanyForm, 'scop_underlying_form' | 'capital_amount'> {
    id: string;
    scop_underlying_form: string | null;
    capital_amount: string | null;
}

interface EditProps {
    company: CompanyData | null;
    mentions: string;
    legalForms: Option[];
    underlyingForms: Option[];
    vatRegimes: Option[];
    vatExemptionNotice: string;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

export default function EditCompany({ company, mentions, legalForms, underlyingForms, vatRegimes, vatExemptionNotice }: EditProps) {
    const { data, setData, post, put, processing, errors } = useForm<CompanyForm>({
        name: company?.name ?? '',
        legal_form: company?.legal_form ?? 'autre',
        scop_underlying_form: company?.scop_underlying_form ?? '',
        capital_amount: company?.capital_amount ?? '',
        capital_variable: company?.capital_variable ?? false,
        siret: company?.siret ?? '',
        rcs_city: company?.rcs_city ?? '',
        vat_number: company?.vat_number ?? '',
        vat_regime: company?.vat_regime ?? 'subject',
        nda_number: company?.nda_number ?? '',
        address_line1: company?.address_line1 ?? '',
        address_line2: company?.address_line2 ?? '',
        address_postal_code: company?.address_postal_code ?? '',
        address_city: company?.address_city ?? '',
        address_country: company?.address_country ?? 'FR',
        email: company?.email ?? '',
        phone: company?.phone ?? '',
        is_default: company?.is_default ?? false,
    });

    const [preview, setPreview] = useState(mentions);
    const isCooperative = data.legal_form === 'scop' || data.legal_form === 'scic';
    const alreadyDefault = company?.is_default ?? false;

    // Live preview: the server builds the line (single source of truth).
    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('admin.companies.preview'), {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({
                    name: data.name,
                    legal_form: data.legal_form,
                    scop_underlying_form: isCooperative ? data.scop_underlying_form : null,
                    capital_amount: data.capital_amount,
                    capital_variable: data.capital_variable,
                    siret: data.siret,
                    rcs_city: data.rcs_city,
                }),
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((json) => json && setPreview(json.mentions))
                .catch(() => undefined);
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [data.name, data.legal_form, data.scop_underlying_form, data.capital_amount, data.capital_variable, data.siret, data.rcs_city, isCooperative]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (company) {
            put(route('admin.companies.update', company.id));
        } else {
            post(route('admin.companies.store'));
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sociétés', href: '/admin/companies' },
        { title: company ? company.name : 'Nouvelle société', href: '#' },
    ];

    const text = (key: keyof CompanyForm) => ({
        id: key,
        value: data[key] as string,
        onChange: (e: React.ChangeEvent<HTMLInputElement>) => setData(key, e.target.value as never),
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={company ? 'Modifier la société' : 'Nouvelle société'} />

            <div className="max-w-3xl p-4">
                <Heading title={company ? 'Modifier la société' : 'Nouvelle société'} description="Identité juridique utilisée sur vos devis et factures." />

                <div className="bg-muted/50 mb-6 rounded-xl border p-4" aria-live="polite">
                    <p className="text-muted-foreground mb-1 text-xs font-medium uppercase">Mentions légales</p>
                    <p className="text-sm">{preview || '—'}</p>
                    {data.vat_regime === 'exempt' && <p className="mt-1 text-sm">{vatExemptionNotice}</p>}
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <Field id="name" label="Raison sociale" error={errors.name}>
                        <Input {...text('name')} required />
                    </Field>

                    <div className="grid gap-6 sm:grid-cols-2">
                        <Field id="legal_form" label="Forme juridique" error={errors.legal_form}>
                            <Select value={data.legal_form} onValueChange={(value) => setData('legal_form', value)}>
                                <SelectTrigger id="legal_form">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {legalForms.map((form) => (
                                        <SelectItem key={form.value} value={form.value}>
                                            {form.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        {isCooperative && (
                            <Field id="scop_underlying_form" label="Forme sous-jacente" error={errors.scop_underlying_form}>
                                <Select value={data.scop_underlying_form} onValueChange={(value) => setData('scop_underlying_form', value)}>
                                    <SelectTrigger id="scop_underlying_form">
                                        <SelectValue placeholder="SARL, SA ou SAS" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {underlyingForms.map((form) => (
                                            <SelectItem key={form.value} value={form.value}>
                                                {form.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                    </div>

                    <div className="grid items-end gap-6 sm:grid-cols-2">
                        <Field id="capital_amount" label="Capital (€)" error={errors.capital_amount}>
                            <Input {...text('capital_amount')} inputMode="decimal" />
                        </Field>
                        <div className="flex items-center gap-2 pb-2">
                            <Checkbox
                                id="capital_variable"
                                checked={data.capital_variable}
                                onCheckedChange={(checked) => setData('capital_variable', checked === true)}
                            />
                            <Label htmlFor="capital_variable">Capital variable</Label>
                        </div>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                        <Field id="siret" label="SIRET" error={errors.siret}>
                            <Input {...text('siret')} inputMode="numeric" required />
                        </Field>
                        <Field id="rcs_city" label="Ville du RCS" error={errors.rcs_city}>
                            <Input {...text('rcs_city')} />
                        </Field>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                        <Field id="vat_regime" label="Régime de TVA" error={errors.vat_regime}>
                            <Select value={data.vat_regime} onValueChange={(value) => setData('vat_regime', value)}>
                                <SelectTrigger id="vat_regime">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {vatRegimes.map((regime) => (
                                        <SelectItem key={regime.value} value={regime.value}>
                                            {regime.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field id="vat_number" label="N° de TVA intracommunautaire" error={errors.vat_number}>
                            <Input {...text('vat_number')} placeholder="FR12345678901" />
                        </Field>
                    </div>

                    <Field id="nda_number" label="N° de déclaration d'activité (NDA)" error={errors.nda_number}>
                        <Input {...text('nda_number')} />
                    </Field>

                    <Field id="address_line1" label="Adresse" error={errors.address_line1}>
                        <Input {...text('address_line1')} />
                    </Field>
                    <Field id="address_line2" label="Complément d'adresse" error={errors.address_line2}>
                        <Input {...text('address_line2')} />
                    </Field>
                    <div className="grid gap-6 sm:grid-cols-3">
                        <Field id="address_postal_code" label="Code postal" error={errors.address_postal_code}>
                            <Input {...text('address_postal_code')} />
                        </Field>
                        <Field id="address_city" label="Ville" error={errors.address_city}>
                            <Input {...text('address_city')} />
                        </Field>
                        <Field id="address_country" label="Pays (code ISO)" error={errors.address_country}>
                            <Input {...text('address_country')} maxLength={2} />
                        </Field>
                    </div>

                    <div className="grid gap-6 sm:grid-cols-2">
                        <Field id="email" label="Adresse e-mail" error={errors.email}>
                            <Input {...text('email')} type="email" />
                        </Field>
                        <Field id="phone" label="Téléphone" error={errors.phone}>
                            <Input {...text('phone')} type="tel" />
                        </Field>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="is_default"
                            checked={data.is_default || alreadyDefault}
                            disabled={alreadyDefault}
                            onCheckedChange={(checked) => setData('is_default', checked === true)}
                        />
                        <Label htmlFor="is_default">
                            Société par défaut
                            {alreadyDefault && <span className="text-muted-foreground"> (choisissez une autre société pour changer)</span>}
                        </Label>
                    </div>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                        <Link href={route('admin.companies.index')} className="text-muted-foreground text-sm underline-offset-4 hover:underline">
                            Annuler
                        </Link>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
