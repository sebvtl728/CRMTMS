import { lineInput, QuoteLinesField, type QuoteLineInput, type QuoteTrainingOption } from '@/components/crm/quote-lines';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface StoredLine {
    training_id: string | null;
    label: string;
    description: string | null;
    quantity: string;
    unit: string;
    unit_price: string | null;
    discount_percent: string;
    vat_rate: string;
}

interface CompanyOption extends Option {
    vat_exempt: boolean;
    validity_days: number;
}

interface FormProps {
    quote: {
        id: string | null;
        number: string | null;
        company_id: string;
        contact_person_id: string | null;
        funding_mode_id: string | null;
        issued_on: string;
        valid_until: string;
        notes: string | null;
        lines: StoredLine[];
    };
    missing_prices: string[];
    vat_exempt: boolean;
    /** A draft saved under the other VAT regime of its company. */
    vat_regime_changed: boolean;
    opportunity: { id: string; title: string; account: { type: 'organisation' | 'person'; id: string | null; name: string | null } };
    options: {
        companies: CompanyOption[];
        contacts: Option[];
        funding_modes: Option[];
        units: Option[];
        vat_rates: Option[];
        training: QuoteTrainingOption[];
    };
}

type QuoteForm = {
    opportunity_id: string;
    company_id: string;
    contact_person_id: string;
    funding_mode_id: string;
    issued_on: string;
    valid_until: string;
    notes: string;
    lines: QuoteLineInput[];
};

const NONE = 'none';

/** YYYY-MM-DD plus a number of days (calendar dates, no time zone shift). */
function addDays(iso: string, days: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return date.toISOString().slice(0, 10);
}

export default function QuoteFormPage({
    quote,
    missing_prices: missing,
    vat_exempt: storedExempt,
    vat_regime_changed: regimeChanged,
    opportunity,
    options,
}: FormProps) {
    const editing = quote.id !== null;

    const { data, setData, post, put, processing, errors } = useForm<QuoteForm>({
        opportunity_id: opportunity.id,
        company_id: quote.company_id,
        contact_person_id: quote.contact_person_id ?? '',
        funding_mode_id: quote.funding_mode_id ?? '',
        issued_on: quote.issued_on,
        valid_until: quote.valid_until,
        notes: quote.notes ?? '',
        lines: quote.lines.map(lineInput),
    });

    const company = options.companies.find((c) => c.value === data.company_id);
    // On edit the company is fixed: its regime as it is now (a draft follows it).
    const vatExempt = editing ? storedExempt : (company?.vat_exempt ?? false);
    const allErrors = errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Devis', href: route('crm.quotes.index') },
        { title: editing ? (quote.number ?? 'Modifier') : 'Nouveau devis', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing && quote.id) {
            put(route('crm.quotes.update', quote.id), { preserveScroll: true });
        } else {
            post(route('crm.quotes.store'), { preserveScroll: true });
        }
    };

    const [rateHint, setRateHint] = useState<string | null>(null);

    // The rates follow the regime of the company: 0 % when exempt, 20 % back when subject.
    const changeCompany = (id: string) => {
        const next = options.companies.find((c) => c.value === id);
        const regimeChanged = next !== undefined && next.vat_exempt !== (company?.vat_exempt ?? false);
        setData((d) => ({
            ...d,
            company_id: id,
            valid_until: next ? addDays(d.issued_on, next.validity_days) : d.valid_until,
            lines: regimeChanged ? d.lines.map((l) => ({ ...l, vat_rate: next.vat_exempt ? '0.00' : '20.00' })) : d.lines,
        }));
        if (regimeChanged) {
            setRateHint(
                next.vat_exempt
                    ? 'Société exonérée : les lignes passent à 0 %.'
                    : 'Société assujettie : les lignes passent à 20 %, modifiez le taux si besoin.',
            );
        }
    };

    const cancelHref = editing && quote.id ? route('crm.quotes.show', quote.id) : route('crm.opportunities.show', opportunity.id);
    const title = editing ? `Modifier le devis ${quote.number ?? ''}` : 'Nouveau devis';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading title={title} description={`Opportunité « ${opportunity.title} » · ${opportunity.account.name ?? '-'}`} />

                {regimeChanged && (
                    <div
                        role="alert"
                        className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
                    >
                        Le régime de TVA de la société a changé depuis l'enregistrement de ce brouillon
                        {storedExempt ? ' (exonérée) : les lignes passent à 0 %.' : ' (assujettie) : choisissez le taux de TVA de chaque ligne.'}{' '}
                        Vérifiez les taux puis enregistrez le devis avant de l'envoyer.
                    </div>
                )}

                {missing.length > 0 && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50">
                        Tarif manquant pour : {missing.join(', ')}. Indiquez le prix unitaire de ces lignes.
                    </div>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <InputError message={allErrors.opportunity_id} />

                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Devis</legend>
                        {editing ? (
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">Société émettrice</span>
                                <p className="text-sm">{company?.label ?? '-'} (fixée à la création : elle donne le numéro)</p>
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="company_id">Société émettrice *</Label>
                                <Select value={data.company_id} onValueChange={changeCompany}>
                                    <SelectTrigger id="company_id">
                                        <SelectValue placeholder="Choisissez" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.companies.map((c) => (
                                            <SelectItem key={c.value} value={c.value}>
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.company_id} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="contact_person_id">Destinataire</Label>
                            <Select value={data.contact_person_id || NONE} onValueChange={(v) => setData('contact_person_id', v === NONE ? '' : v)}>
                                <SelectTrigger id="contact_person_id">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Non renseigné</SelectItem>
                                    {options.contacts.map((c) => (
                                        <SelectItem key={c.value} value={c.value}>
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.contact_person_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="funding_mode_id">Financement envisagé</Label>
                            <Select value={data.funding_mode_id || NONE} onValueChange={(v) => setData('funding_mode_id', v === NONE ? '' : v)}>
                                <SelectTrigger id="funding_mode_id">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Non renseigné</SelectItem>
                                    {options.funding_modes.map((m) => (
                                        <SelectItem key={m.value} value={m.value}>
                                            {m.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">Repris de l'opportunité, imprimé sur le devis ; le dossier créé depuis le devis part de ce financement.</p>
                            <InputError message={errors.funding_mode_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="issued_on">Date du devis *</Label>
                            <Input id="issued_on" type="date" value={data.issued_on} onChange={(e) => setData('issued_on', e.target.value)} />
                            <InputError message={errors.issued_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="valid_until">Valable jusqu'au *</Label>
                            <Input id="valid_until" type="date" value={data.valid_until} onChange={(e) => setData('valid_until', e.target.value)} />
                            <InputError message={errors.valid_until} />
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="notes">Mentions particulières</Label>
                            <textarea
                                id="notes"
                                value={data.notes}
                                maxLength={5000}
                                rows={3}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="border-input placeholder:text-muted-foreground focus-visible:ring-ring rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                            />
                            <InputError message={errors.notes} />
                        </div>
                        {rateHint && (
                            <p role="status" className="text-sm text-amber-700 md:col-span-2 dark:text-amber-300">
                                {rateHint}
                            </p>
                        )}
                        {vatExempt && (
                            <p className="text-muted-foreground text-sm md:col-span-2">Société exonérée de TVA : toutes les lignes sont à 0 %.</p>
                        )}
                    </fieldset>

                    <QuoteLinesField
                        lines={data.lines}
                        options={options}
                        vatExempt={vatExempt}
                        errors={allErrors}
                        onChange={(lines) => setData('lines', lines)}
                    />

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer le brouillon
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={cancelHref}>Annuler</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
