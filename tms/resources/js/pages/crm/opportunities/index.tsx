import { FilterSelect } from '@/components/crm/filter-select';
import { OpportunityCards, StageBadge, type OpportunityRow } from '@/components/crm/opportunity-list';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDate, formatDay, type Option, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Filters {
    q: string;
    das: string | null;
    target: string | null;
    responsible: string | null;
    priority: string | null;
    stage: string | null;
    close_from: string | null;
    close_to: string | null;
    mine: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    opportunities: Paginated<OpportunityRow>;
    totals: { amount: string; weighted_amount: string };
    filters: Filters;
    options: { target: Option[]; das: Option[]; stage: Option[]; priority: Option[]; responsible: Option[]; sort: Option[] };
    can: { create: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Opportunités', href: '/crm/opportunities' }];

export default function OpportunitiesIndex({ opportunities, totals, filters, options, can, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        (['das', 'target', 'responsible', 'priority', 'stage', 'close_from', 'close_to'] as const).forEach((k) => {
            if (next[k]) query[k] = String(next[k]);
        });
        if (next.mine) query.mine = '1';
        if (next.sort !== 'expected_close_on' || next.dir !== 'asc') {
            query.sort = next.sort;
            query.dir = next.dir;
        }
        router.get(route('crm.opportunities.index'), query, { preserveState: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

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

    const filtered = Boolean(
        filters.q ||
            filters.das ||
            filters.target ||
            filters.responsible ||
            filters.priority ||
            filters.stage ||
            filters.close_from ||
            filters.close_to ||
            filters.mine,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opportunités" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Opportunités" description="Affaires en cours, gagnées et perdues" />
                    {can.create && (
                        <Button asChild>
                            <Link href={route('crm.opportunities.create')}>
                                <Plus className="h-4 w-4" />
                                Nouvelle opportunité
                            </Link>
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (objet, entreprise, personne)
                        </Label>
                        <Input id="q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ex. HACCP, bistrot…" />
                    </div>
                    <FilterSelect label="Cible" value={filters.target} options={options.target} onChange={(v) => visit({ target: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect label="Étape" value={filters.stage} options={options.stage} onChange={(v) => visit({ stage: v })} />
                    <FilterSelect label="Priorité" value={filters.priority} options={options.priority} onChange={(v) => visit({ priority: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="close_from" className="text-xs">
                            Clôture prévue à partir du
                        </Label>
                        <Input
                            id="close_from"
                            type="date"
                            value={filters.close_from ?? ''}
                            onChange={(e) => visit({ close_from: e.target.value || null })}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="close_to" className="text-xs">
                            Clôture prévue jusqu'au
                        </Label>
                        <Input
                            id="close_to"
                            type="date"
                            value={filters.close_to ?? ''}
                            onChange={(e) => visit({ close_to: e.target.value || null })}
                        />
                    </div>
                    <div className="flex items-end gap-4">
                        <Label className="flex items-center gap-2 text-sm font-normal">
                            <Checkbox checked={filters.mine} onCheckedChange={(c) => visit({ mine: c === true })} />
                            Mes opportunités
                        </Label>
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('crm.opportunities.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

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
                                                {o.account.name ?? '—'}
                                                <div className="text-muted-foreground text-xs">
                                                    {o.target === 'individual' ? 'Particulier' : 'Entreprise'}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2">
                                                <StageBadge stage={o.stage} />{' '}
                                                <span className="text-muted-foreground text-xs">{o.probability} %</span>
                                            </td>
                                            <td className="px-3 py-2">{o.das ?? '—'}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{formatAmount(o.amount)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{formatAmount(o.weighted_amount)}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDay(o.expected_close_on)}</td>
                                            <td className="px-3 py-2">{o.responsible ?? '—'}</td>
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
