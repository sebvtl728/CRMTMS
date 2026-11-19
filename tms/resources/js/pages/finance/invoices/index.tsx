import { FilterSelect } from '@/components/crm/filter-select';
import { paymentStatusVariant } from '@/components/finance/payments-panel';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDay, type Option, type Paginated } from '@/lib/crm';
import { invoiceStatusVariant, type InvoiceRow } from '@/lib/finance';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface Filters {
    status: string | null;
    kind: string | null;
    company: string | null;
    payment: string | null;
}

interface InvoicesIndexProps {
    invoices: Paginated<InvoiceRow>;
    filters: Filters;
    options: { status: Option[]; kind: Option[]; company: Option[]; payment: Option[] };
    can: { export: boolean; integrity: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Factures', href: '/finance/invoices' }];

export default function InvoicesIndex({ invoices, filters, options, can, status }: InvoicesIndexProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [checking, setChecking] = useState(false);
    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        (Object.keys(next) as (keyof Filters)[]).forEach((k) => {
            if (next[k]) query[k] = String(next[k]);
        });
        router.get(route('finance.invoices.index'), query, { preserveState: true, replace: true });
    };
    const exportQuery = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
    const filtered = Boolean(filters.status || filters.kind || filters.company || filters.payment);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Factures" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Factures" description="Factures et avoirs des dossiers ; les brouillons se préparent depuis « À facturer »." />
                    <div className="flex flex-wrap gap-2">
                        {can.integrity && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={checking}
                                onClick={() =>
                                    router.post(
                                        route('finance.invoices.integrity'),
                                        {},
                                        { preserveScroll: true, onStart: () => setChecking(true), onFinish: () => setChecking(false) },
                                    )
                                }
                            >
                                <ShieldCheck className="h-4 w-4" />
                                Vérifier l'intégrité
                            </Button>
                        )}
                        {can.export && (
                            <Button variant="outline" asChild>
                                <a href={route('finance.invoices.export') + (exportQuery ? `?${exportQuery}` : '')}>
                                    <Download className="h-4 w-4" />
                                    Exporter (CSV)
                                </a>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={route('finance.to-invoice.index')}>À facturer</Link>
                        </Button>
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {errors.integrity && (
                    <div role="alert" className="text-destructive text-sm font-medium">
                        {errors.integrity}
                    </div>
                )}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FilterSelect label="État" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <FilterSelect label="Type" value={filters.kind} options={options.kind} onChange={(v) => visit({ kind: v })} />
                    <FilterSelect label="Société" value={filters.company} options={options.company} onChange={(v) => visit({ company: v })} />
                    <FilterSelect label="Paiement" value={filters.payment} options={options.payment} onChange={(v) => visit({ payment: v })} />
                    <div className="flex items-end">
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('finance.invoices.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                <p className="text-sm">
                    {invoices.total} facture{invoices.total > 1 ? 's' : ''} et avoir{invoices.total > 1 ? 's' : ''}
                </p>

                {invoices.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune facture ne correspond à ces filtres.' : 'Aucune facture pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Numéro</th>
                                        <th className="px-3 py-2 font-medium">Date</th>
                                        <th className="px-3 py-2 font-medium">Destinataire</th>
                                        <th className="px-3 py-2 font-medium">Dossier</th>
                                        <th className="px-3 py-2 text-right font-medium">HT</th>
                                        <th className="px-3 py-2 text-right font-medium">TTC</th>
                                        <th className="px-3 py-2 text-right font-medium">Reste dû</th>
                                        <th className="px-3 py-2 font-medium">État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.data.map((invoice) => (
                                        <tr key={invoice.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={invoice.url} className="font-medium underline">
                                                    {invoice.title}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDay(invoice.issued_on ?? invoice.created_on)}</td>
                                            <td className="px-3 py-2">{invoice.recipient}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={invoice.dossier.url} className="underline">
                                                    {invoice.dossier.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {formatCents(invoice.total_ht_cents)}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {formatCents(invoice.total_ttc_cents)}
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {invoice.remaining_cents === null ? '—' : formatCents(Math.max(0, invoice.remaining_cents))}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    <Badge variant={invoiceStatusVariant(invoice.status)}>{invoice.status_label}</Badge>
                                                    {invoice.payment_status && (
                                                        <Badge variant={paymentStatusVariant(invoice.payment_status, invoice.overdue)}>
                                                            {invoice.overdue ? 'En retard' : invoice.payment_status_label}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {invoices.from}–{invoices.to} sur {invoices.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!invoices.prev_page_url}
                                    onClick={() => invoices.prev_page_url && router.get(invoices.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!invoices.next_page_url}
                                    onClick={() => invoices.next_page_url && router.get(invoices.next_page_url)}
                                >
                                    Suivant
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </nav>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
