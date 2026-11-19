import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDay, type Option, type Paginated } from '@/lib/crm';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';

interface PaymentRow {
    id: string;
    received_on: string;
    payer: string;
    method: string;
    reference: string | null;
    amount_cents: number;
    allocated_cents: number;
    unallocated_cents: number;
    url: string;
}

interface Filters {
    unallocated: boolean;
    company: string | null;
}

interface PaymentsIndexProps {
    payments: Paginated<PaymentRow>;
    filters: Filters;
    options: { company: Option[] };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Règlements', href: '/finance/payments' }];

/** Finance › Règlements: the payments received and how much of each settles invoices. */
export default function PaymentsIndex({ payments, filters, options, status }: PaymentsIndexProps) {
    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.unallocated) query.unallocated = '1';
        if (next.company) query.company = next.company;
        router.get('/finance/payments', query, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Règlements" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Règlements" description="Les sommes reçues et leur répartition sur les factures." />
                    <Button asChild>
                        <Link href="/finance/payments/create">
                            <Plus className="h-4 w-4" />
                            Nouveau règlement
                        </Link>
                    </Button>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FilterSelect label="Société" value={filters.company} options={options.company} onChange={(v) => visit({ company: v })} />
                    <Label className="flex items-end gap-2 pb-2 font-normal">
                        <input type="checkbox" checked={filters.unallocated} onChange={(e) => visit({ unallocated: e.target.checked })} />
                        Avec un reste non affecté
                    </Label>
                </section>

                {payments.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucun règlement pour le moment.</div>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Date</th>
                                        <th className="px-3 py-2 font-medium">Payeur</th>
                                        <th className="px-3 py-2 font-medium">Mode</th>
                                        <th className="px-3 py-2 font-medium">Référence</th>
                                        <th className="px-3 py-2 text-right font-medium">Montant</th>
                                        <th className="px-3 py-2 text-right font-medium">Non affecté</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payments.data.map((p) => (
                                        <tr key={p.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={p.url} className="font-medium underline">
                                                    {formatDay(p.received_on)}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">{p.payer}</td>
                                            <td className="px-3 py-2">{p.method}</td>
                                            <td className="px-3 py-2">{p.reference ?? '-'}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(p.amount_cents)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {p.unallocated_cents > 0 ? formatCents(p.unallocated_cents) : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {payments.from}–{payments.to} sur {payments.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!payments.prev_page_url}
                                    onClick={() => payments.prev_page_url && router.get(payments.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!payments.next_page_url}
                                    onClick={() => payments.next_page_url && router.get(payments.next_page_url)}
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
