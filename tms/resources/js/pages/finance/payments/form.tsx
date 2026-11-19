import { OptionSelect, TextField, textareaClass } from '@/components/admissions/fields';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDay, type Option } from '@/lib/crm';
import { centsInput, formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Payer {
    type: 'organisation' | 'person';
    id: string;
    label: string;
}

interface OpenInvoice {
    id: string;
    number: string;
    issued_on: string | null;
    due_on: string | null;
    ttc_cents: number;
    remaining_cents: number;
    dossier_number: string;
}

interface PaymentForm {
    id: string | null;
    payer: Payer | null;
    company_id: string | null;
    received_on: string;
    amount: string;
    method_id: string | null;
    reference: string;
    notes: string;
    allocations: { invoice_id: string; amount: string }[];
    from_invoice: string | null;
}

interface PaymentFormProps {
    payment: PaymentForm;
    open_invoices: OpenInvoice[];
    options: { methods: Option[]; company: Option[] };
}

/** « 1 200,50 » → 120050; null when it is not an amount. */
function parseCents(text: string): number | null {
    const clean = text.replace(/[\s\u00a0\u202f\u20ac]/g, '').replace(',', '.');
    if (!/^\d{1,10}(\.\d{1,2})?$/.test(clean)) return null;
    const [units, decimals = ''] = clean.split('.');

    return Number(units) * 100 + Number(decimals.padEnd(2, '0'));
}

/** A payment: who paid, how much, when, and the invoices it settles. */
export default function PaymentFormPage({ payment, open_invoices, options }: PaymentFormProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [form, setForm] = useState<PaymentForm>(payment);
    const [invoices, setInvoices] = useState<OpenInvoice[]>(open_invoices);
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<Payer[]>([]);
    const [saving, setSaving] = useState(false);
    const title = payment.id ? 'Modifier le règlement' : 'Nouveau règlement';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Règlements', href: '/finance/payments' },
        { title, href: '#' },
    ];

    useEffect(() => {
        if (query.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/finance/payments/payers?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((data: { payers: Payer[] }) => setFound(data.payers));
        }, 250);

        return () => clearTimeout(timer);
    }, [query]);

    const loadInvoices = (payer: Payer | null, companyId: string | null) => {
        if (!payer || !companyId) {
            setInvoices([]);
            return;
        }
        const params = new URLSearchParams({ payer_type: payer.type, payer_id: payer.id, company_id: companyId });
        fetch(`/finance/payments/open-invoices?${params.toString()}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data: { invoices: OpenInvoice[] }) => setInvoices(data.invoices));
    };

    const allocated = (invoiceId: string) => form.allocations.find((a) => a.invoice_id === invoiceId)?.amount ?? '';
    const setAllocated = (invoiceId: string, amount: string) => {
        const others = form.allocations.filter((a) => a.invoice_id !== invoiceId);
        setForm({ ...form, allocations: amount.trim() === '' ? others : [...others, { invoice_id: invoiceId, amount }] });
    };
    // « Répartir »: the amount over the invoices, oldest due first.
    const spread = () => {
        let left = parseCents(form.amount) ?? 0;
        const allocations: PaymentForm['allocations'] = [];
        for (const invoice of invoices) {
            if (left <= 0) break;
            const cents = Math.min(left, invoice.remaining_cents);
            if (cents > 0) allocations.push({ invoice_id: invoice.id, amount: centsInput(cents) });
            left -= cents;
        }
        setForm({ ...form, allocations });
    };
    const amountCents = parseCents(form.amount) ?? 0;
    const allocatedCents = form.allocations.reduce((sum, a) => sum + (parseCents(a.amount) ?? 0), 0);

    const submit = () => {
        const data = {
            company_id: form.company_id,
            received_on: form.received_on,
            amount: form.amount,
            method_id: form.method_id,
            reference: form.reference,
            notes: form.notes,
            from_invoice: form.from_invoice,
            payer_type: form.payer?.type ?? null,
            payer_id: form.payer?.id ?? null,
            allocations: invoices.filter((i) => allocated(i.id) !== '').map((i) => ({ invoice_id: i.id, amount: allocated(i.id) })),
        };
        const options = { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) };
        if (payment.id) router.put(`/finance/payments/${payment.id}`, data, options);
        else router.post('/finance/payments', data, options);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading title={title} description="Un règlement peut couvrir plusieurs factures du même payeur ; le reste reste « non affecté »." />

                <section className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2">
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor="payer">Payeur *</Label>
                        {form.payer ? (
                            <div className="flex items-center gap-2 text-sm">
                                <span className="font-medium">{form.payer.label}</span>
                                {!payment.id && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            setForm({ ...form, payer: null, allocations: [] });
                                            setInvoices([]);
                                        }}
                                    >
                                        Changer
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <>
                                <Input
                                    id="payer"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Nom de l'entreprise ou de la personne"
                                />
                                {found.length > 0 && (
                                    <ul className="grid gap-1 rounded-md border p-1 text-sm">
                                        {found.map((p) => (
                                            <li key={`${p.type}:${p.id}`}>
                                                <button
                                                    type="button"
                                                    className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                                                    onClick={() => {
                                                        setForm({ ...form, payer: p, allocations: [] });
                                                        setQuery('');
                                                        loadInvoices(p, form.company_id);
                                                    }}
                                                >
                                                    {p.label}{' '}
                                                    <span className="text-muted-foreground text-xs">
                                                        {p.type === 'person' ? 'particulier' : 'entreprise'}
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                        <InputError message={errors.payer_id} />
                    </div>
                    <OptionSelect
                        id="company_id"
                        label="Société *"
                        value={form.company_id}
                        options={options.company}
                        onChange={(v) => {
                            setForm({ ...form, company_id: v, allocations: [] });
                            loadInvoices(form.payer, v);
                        }}
                        error={errors.company_id}
                        disabled={Boolean(payment.id)}
                    />
                    <TextField
                        id="received_on"
                        label="Date du règlement *"
                        type="date"
                        value={form.received_on}
                        onChange={(v) => setForm({ ...form, received_on: v })}
                        error={errors.received_on}
                    />
                    <TextField
                        id="amount"
                        label="Montant (€) *"
                        value={form.amount}
                        onChange={(v) => setForm({ ...form, amount: v })}
                        error={errors.amount}
                    />
                    <OptionSelect
                        id="method_id"
                        label="Mode de règlement *"
                        value={form.method_id}
                        options={options.methods}
                        onChange={(v) => setForm({ ...form, method_id: v })}
                        error={errors.method_id}
                    />
                    <TextField
                        id="reference"
                        label="Référence (n° de chèque, libellé du virement)"
                        value={form.reference}
                        onChange={(v) => setForm({ ...form, reference: v })}
                        error={errors.reference}
                    />
                    <div className="grid gap-1 sm:col-span-2">
                        <Label htmlFor="notes">Notes</Label>
                        <textarea
                            id="notes"
                            rows={2}
                            className={textareaClass}
                            value={form.notes}
                            onChange={(e) => setForm({ ...form, notes: e.target.value })}
                        />
                        <InputError message={errors.notes} />
                    </div>
                </section>

                <section className="grid gap-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">Factures réglées</h2>
                        <Button type="button" variant="outline" size="sm" onClick={spread} disabled={invoices.length === 0 || amountCents === 0}>
                            Répartir
                        </Button>
                    </div>
                    {invoices.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            {form.payer
                                ? 'Aucune facture à régler pour ce payeur et cette société.'
                                : 'Choisissez le payeur pour voir ses factures à régler.'}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left">
                                    <tr>
                                        <th className="px-2 py-1 font-medium">Facture</th>
                                        <th className="px-2 py-1 font-medium">Échéance</th>
                                        <th className="px-2 py-1 text-right font-medium">TTC</th>
                                        <th className="px-2 py-1 text-right font-medium">Reste dû</th>
                                        <th className="px-2 py-1 font-medium">Affecté (€)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((invoice) => {
                                        // The index the server answers with: the rows sent, in this order.
                                        const index = invoices.filter((i) => allocated(i.id) !== '').findIndex((i) => i.id === invoice.id);
                                        return (
                                            <tr key={invoice.id} className="border-t">
                                                <td className="px-2 py-1 whitespace-nowrap">
                                                    {invoice.number}{' '}
                                                    {invoice.dossier_number && (
                                                        <span className="text-muted-foreground text-xs">{invoice.dossier_number}</span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-1 whitespace-nowrap">{formatDay(invoice.due_on)}</td>
                                                <td className="px-2 py-1 text-right tabular-nums">{formatCents(invoice.ttc_cents)}</td>
                                                <td className="px-2 py-1 text-right tabular-nums">{formatCents(invoice.remaining_cents)}</td>
                                                <td className="px-2 py-1">
                                                    <Input
                                                        aria-label={`Montant affecté à ${invoice.number}`}
                                                        className="w-32"
                                                        value={allocated(invoice.id)}
                                                        onChange={(e) => setAllocated(invoice.id, e.target.value)}
                                                    />
                                                    <InputError
                                                        message={
                                                            index >= 0
                                                                ? (errors[`allocations.${index}.amount`] ?? errors[`allocations.${index}.invoice_id`])
                                                                : undefined
                                                        }
                                                    />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <p className="text-sm">
                        Affecté : <span className="tabular-nums">{formatCents(allocatedCents)}</span> - non affecté :{' '}
                        <span className="tabular-nums">{formatCents(Math.max(0, amountCents - allocatedCents))}</span>
                    </p>
                    <InputError message={errors.allocations} />
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button type="button" onClick={submit} disabled={saving}>
                        Enregistrer
                    </Button>
                    {payment.id && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => confirm('Supprimer ce règlement et sa répartition ?') && router.delete(`/finance/payments/${payment.id}`)}
                        >
                            <Trash2 className="h-4 w-4" />
                            Supprimer
                        </Button>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
