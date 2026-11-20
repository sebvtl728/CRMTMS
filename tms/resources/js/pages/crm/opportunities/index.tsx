import {
    isFiltered,
    OpportunityExportButton,
    OpportunityFilters,
    opportunityQuery,
    type OpportunityFilterOptions,
    type OpportunityFilterValues,
} from '@/components/crm/opportunity-filters';
import { OpportunityCards, StageBadge, type OpportunityRow } from '@/components/crm/opportunity-list';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDate, formatDay, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Columns3, Plus } from 'lucide-react';

interface IndexProps {
    opportunities: Paginated<OpportunityRow>;
    totals: { amount: string; weighted_amount: string };
    filters: OpportunityFilterValues;
    options: OpportunityFilterOptions;
    can: { create: boolean; export: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Opportunités', href: '/crm/opportunities' }];

export default function OpportunitiesIndex({ opportunities, totals, filters, options, can, status }: IndexProps) {
    const visit = (changes: Partial<OpportunityFilterValues>) =>
        router.get(route('crm.opportunities.index'), opportunityQuery({ ...filters, ...changes }), { preserveState: true, replace: true });

    const sortBy = (column: string) => visit({ sort: column, dir: filters.sort === column && filters.dir === 'asc' ? 'desc' : 'asc' });
    const sortHeader = (column: string, label: string, className = '') => (
        <th
            className={`px-3 py-2 font-medium ${className}`}
            aria-sort={filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
        >
            <button type="button" onClick={() => sortBy(column)} className="inline-flex items-center gap-1 hover:underline">
                {label}
                {filters.sort === column && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
            </button>
        </th>
    );

    const filtered = isFiltered(filters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opportunités" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Opportunités" description="Affaires en cours, gagnées et perdues" />
                    <div className="flex flex-wrap items-start gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('crm.opportunities.kanban', opportunityQuery(filters))}>
                                <Columns3 className="h-4 w-4" />
                                Vue pipeline
                            </Link>
                        </Button>
                        {can.export && <OpportunityExportButton filters={filters} />}
                        {can.create && (
                            <Button asChild>
                                <Link href={route('crm.opportunities.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouvelle opportunité
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <OpportunityFilters routeName="crm.opportunities.index" filters={filters} options={options} />

                <p className="text-sm">
                    {opportunities.total} opportunité{opportunities.total > 1 ? 's' : ''} · Montant {formatAmount(totals.amount)} · Pondéré{' '}
                    {formatAmount(totals.weighted_amount)}
                </p>

                {opportunities.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune opportunité ne correspond à ces filtres.' : 'Aucune opportunité pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Objet</th>
                                        <th className="px-3 py-2 font-medium">Compte</th>
                                        <th className="px-3 py-2 font-medium">Étape</th>
                                        <th className="px-3 py-2 font-medium">DAS</th>
                                        {sortHeader('amount', 'Montant', 'text-right')}
                                        {sortHeader('weighted_amount', 'Pondéré', 'text-right')}
                                        {sortHeader('expected_close_on', 'Clôture prévue')}
                                        <th className="px-3 py-2 font-medium">Responsable</th>
                                        {sortHeader('created_at', 'Créée le')}
                                    </tr>
                                </thead>
                                <tbody>
                                    {opportunities.data.map((o) => (
                                        <tr key={o.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2">
                                                <Link href={route('crm.opportunities.show', o.id)} className="font-medium hover:underline">
                                                    {o.title}
                                                </Link>
                                                {o.priority && <div className="text-muted-foreground text-xs">{o.priority}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                {o.account.name ?? '-'}
                                                <div className="text-muted-foreground text-xs">
                                                    {o.target === 'individual' ? 'Particulier' : 'Entreprise'}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2">
                                                <StageBadge stage={o.stage} />{' '}
                                                <span className="text-muted-foreground text-xs">{o.probability} %</span>
                                            </td>
                                            <td className="px-3 py-2">{o.das ?? '-'}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{formatAmount(o.amount)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{formatAmount(o.weighted_amount)}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDay(o.expected_close_on)}</td>
                                            <td className="px-3 py-2">{o.responsible ?? '-'}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDate(o.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="md:hidden">
                            <OpportunityCards rows={opportunities.data} />
                        </div>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {opportunities.from}–{opportunities.to} sur {opportunities.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!opportunities.prev_page_url}
                                    onClick={() => opportunities.prev_page_url && router.get(opportunities.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!opportunities.next_page_url}
                                    onClick={() => opportunities.next_page_url && router.get(opportunities.next_page_url)}
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
