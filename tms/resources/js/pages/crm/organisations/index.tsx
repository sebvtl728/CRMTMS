import { FilterSelect } from '@/components/crm/filter-select';
import { ListToolbar, type ColumnChoice, type SavedFilterRow } from '@/components/crm/filters';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatSiret, statusVariant, type Option, type Paginated, type StatusValue } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Row {
    id: string;
    name: string;
    siret: string | null;
    city: string | null;
    status: StatusValue;
    sector: string | null;
    responsible: string | null;
    das: string[];
    last_contact_at: string | null;
    created_at: string | null;
    deleted: boolean;
}

interface Filters {
    q: string;
    status: string | null;
    das: string | null;
    sector: string | null;
    responsible: string | null;
    origin: string | null;
    mine: boolean;
    trashed: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    organisations: Paginated<Row>;
    filters: Filters;
    options: { status: Option[]; das: Option[]; sector: Option[]; origin: Option[]; responsible: Option[] };
    can: { create: boolean; delete: boolean; export: boolean };
    savedFilters: SavedFilterRow[];
    columns: ColumnChoice;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Entreprises', href: '/crm/organisations' }];

export default function OrganisationsIndex({ organisations, filters, options, can, savedFilters, columns, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        (['status', 'das', 'sector', 'responsible', 'origin'] as const).forEach((k) => {
            if (next[k]) query[k] = String(next[k]);
        });
        if (next.mine) query.mine = '1';
        if (next.trashed) query.trashed = '1';
        if (next.sort !== 'name' || next.dir !== 'asc') {
            query.sort = next.sort;
            query.dir = next.dir;
        }
        router.get(route('crm.organisations.index'), query, { preserveState: true, replace: true });
    };

    // Debounced text search.
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

    const SortHeader = ({ column, children }: { column: string; children: string }) => (
        <th className="px-4 py-2 font-medium" aria-sort={filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
            <button type="button" className="inline-flex items-center gap-1 hover:underline" onClick={() => sortBy(column)}>
                {children}
                {filters.sort === column && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
            </button>
        </th>
    );

    const filtered = Boolean(
        filters.q || filters.status || filters.das || filters.sector || filters.responsible || filters.origin || filters.mine || filters.trashed,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Entreprises" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Entreprises" description="Clients et prospects de votre organisme" />
                    {can.create && (
                        <Button asChild>
                            <Link href={route('crm.organisations.create')}>
                                <Plus className="h-4 w-4" />
                                Nouvelle entreprise
                            </Link>
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <ListToolbar resource="organisations" filters={filters} savedFilters={savedFilters} columns={columns} canExport={can.export} />

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (nom, ville, SIRET)
                        </Label>
                        <Input
                            id="q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Ex. hôtel, Lyon, 732 829…"
                        />
                    </div>
                    <FilterSelect label="Statut" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect label="Secteur" value={filters.sector} options={options.sector} onChange={(v) => visit({ sector: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <FilterSelect label="Origine" value={filters.origin} options={options.origin} onChange={(v) => visit({ origin: v })} />
                    <div className="flex items-end gap-4 sm:col-span-2">
                        <Label className="flex items-center gap-2 text-sm font-normal">
                            <Checkbox checked={filters.mine} onCheckedChange={(c) => visit({ mine: c === true })} />
                            Mes fiches
                        </Label>
                        {can.delete && (
                            <Label className="flex items-center gap-2 text-sm font-normal">
                                <Checkbox checked={filters.trashed} onCheckedChange={(c) => visit({ trashed: c === true })} />
                                Corbeille
                            </Label>
                        )}
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('crm.organisations.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                {organisations.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune entreprise ne correspond à ces filtres.' : 'Aucune entreprise pour le moment.'}
                        {!filtered && can.create && (
                            <div className="mt-3">
                                <Button asChild>
                                    <Link href={route('crm.organisations.create')}>Créer la première entreprise</Link>
                                </Button>
                            </div>
                        )}
                    </div>
                ) : (
                    <>
                        {/* Table from 768 px */}
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <SortHeader column="name">Nom</SortHeader>
                                        <SortHeader column="city">Ville</SortHeader>
                                        <th className="px-4 py-2 font-medium">SIRET</th>
                                        <th className="px-4 py-2 font-medium">Statut</th>
                                        <th className="px-4 py-2 font-medium">DAS</th>
                                        <th className="px-4 py-2 font-medium">Responsable</th>
                                        <SortHeader column="last_contact_at">Dernier contact</SortHeader>
                                        <SortHeader column="created_at">Création</SortHeader>
                                    </tr>
                                </thead>
                                <tbody>
                                    {organisations.data.map((o) => (
                                        <tr key={o.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-4 py-2 font-medium">
                                                <Link href={route('crm.organisations.show', o.id)} className="hover:underline">
                                                    {o.name}
                                                </Link>
                                                {o.deleted && (
                                                    <Badge variant="destructive" className="ml-2">
                                                        Supprimée
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2">{o.city ?? '—'}</td>
                                            <td className="px-4 py-2 whitespace-nowrap">{formatSiret(o.siret)}</td>
                                            <td className="px-4 py-2">
                                                <Badge variant={statusVariant(o.status.value)}>{o.status.label}</Badge>
                                            </td>
                                            <td className="px-4 py-2">{o.das.length ? o.das.join(', ') : '—'}</td>
                                            <td className="px-4 py-2">{o.responsible ?? '—'}</td>
                                            <td className="px-4 py-2">{formatDate(o.last_contact_at)}</td>
                                            <td className="px-4 py-2">{formatDate(o.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Cards below 768 px */}
                        <ul className="grid gap-3 md:hidden">
                            {organisations.data.map((o) => (
                                <li key={o.id}>
                                    <Link href={route('crm.organisations.show', o.id)} className="hover:bg-muted/30 block rounded-xl border p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="font-medium">{o.name}</span>
                                            <Badge variant={statusVariant(o.status.value)}>{o.status.label}</Badge>
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-sm">{[o.city, o.sector].filter(Boolean).join(' · ') || '—'}</p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {o.responsible ?? 'Sans responsable'} · Dernier contact : {formatDate(o.last_contact_at)}
                                        </p>
                                        {o.deleted && (
                                            <Badge variant="destructive" className="mt-2">
                                                Supprimée
                                            </Badge>
                                        )}
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {organisations.from}–{organisations.to} sur {organisations.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!organisations.prev_page_url}
                                    onClick={() => organisations.prev_page_url && router.get(organisations.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!organisations.next_page_url}
                                    onClick={() => organisations.next_page_url && router.get(organisations.next_page_url)}
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
