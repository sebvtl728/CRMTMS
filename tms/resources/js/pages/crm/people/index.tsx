import { FilterSelect } from '@/components/crm/filter-select';
import { ListToolbar, type ColumnChoice, type SavedFilterRow } from '@/components/crm/filters';
import { PersonTypeBadges } from '@/components/crm/person-type-badges';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatPhone, statusVariant, type Option, type Paginated, type StatusValue } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Row {
    id: string;
    civility: string | null;
    first_name: string | null;
    last_name: string;
    name: string;
    is_individual: boolean;
    organisations: { id: string; name: string; job_title: string | null }[];
    email: string | null;
    phone: string | null;
    city: string | null;
    status: StatusValue;
    responsible: string | null;
    last_contact_at: string | null;
    created_at: string | null;
    deleted: boolean;
}

interface Filters {
    q: string;
    type: string | null;
    status: string | null;
    situation: string | null;
    funding: string | null;
    origin: string | null;
    responsible: string | null;
    mine: boolean;
    trashed: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    people: Paginated<Row>;
    filters: Filters;
    options: { type: Option[]; status: Option[]; situation: Option[]; funding: Option[]; origin: Option[]; responsible: Option[] };
    can: { create: boolean; delete: boolean; export: boolean };
    savedFilters: SavedFilterRow[];
    columns: ColumnChoice;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Personnes', href: '/crm/people' }];

export default function PeopleIndex({ people, filters, options, can, savedFilters, columns, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        (['type', 'status', 'situation', 'funding', 'origin', 'responsible'] as const).forEach((k) => {
            if (next[k]) query[k] = String(next[k]);
        });
        if (next.mine) query.mine = '1';
        if (next.trashed) query.trashed = '1';
        if (next.sort !== 'name' || next.dir !== 'asc') {
            query.sort = next.sort;
            query.dir = next.dir;
        }
        router.get(route('crm.people.index'), query, { preserveState: true, replace: true });
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
        filters.q ||
            filters.type ||
            filters.status ||
            filters.situation ||
            filters.funding ||
            filters.origin ||
            filters.responsible ||
            filters.mine ||
            filters.trashed,
    );

    const orgLine = (p: Row) => p.organisations.map((o) => o.name).join(', ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Personnes" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Personnes" description="Particuliers et interlocuteurs de vos entreprises" />
                    {can.create && (
                        <Button asChild>
                            <Link href={route('crm.people.create')}>
                                <Plus className="h-4 w-4" />
                                Nouvelle personne
                            </Link>
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <ListToolbar resource="people" filters={filters} savedFilters={savedFilters} columns={columns} canExport={can.export} />

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (nom, e-mail, téléphone)
                        </Label>
                        <Input id="q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ex. dupont, 06 12 34…" />
                    </div>
                    <FilterSelect label="Type" value={filters.type} options={options.type} onChange={(v) => visit({ type: v })} />
                    <FilterSelect label="Statut" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <FilterSelect label="Situation" value={filters.situation} options={options.situation} onChange={(v) => visit({ situation: v })} />
                    <FilterSelect label="Financement" value={filters.funding} options={options.funding} onChange={(v) => visit({ funding: v })} />
                    <FilterSelect label="Origine" value={filters.origin} options={options.origin} onChange={(v) => visit({ origin: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
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
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('crm.people.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                {people.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune personne ne correspond à ces filtres.' : 'Aucune personne pour le moment.'}
                        {!filtered && can.create && (
                            <div className="mt-3">
                                <Button asChild>
                                    <Link href={route('crm.people.create')}>Créer la première personne</Link>
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
                                        <th className="px-4 py-2 font-medium">Type</th>
                                        <th className="px-4 py-2 font-medium">Entreprise</th>
                                        <th className="px-4 py-2 font-medium">E-mail</th>
                                        <th className="px-4 py-2 font-medium">Téléphone</th>
                                        <th className="px-4 py-2 font-medium">Statut</th>
                                        <th className="px-4 py-2 font-medium">Responsable</th>
                                        <SortHeader column="last_contact_at">Dernier contact</SortHeader>
                                    </tr>
                                </thead>
                                <tbody>
                                    {people.data.map((p) => (
                                        <tr key={p.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-4 py-2 font-medium">
                                                <Link href={route('crm.people.show', p.id)} className="hover:underline">
                                                    {p.civility ? `${p.civility} ` : ''}
                                                    {p.name}
                                                </Link>
                                                {p.deleted && (
                                                    <Badge variant="destructive" className="ml-2">
                                                        Supprimée
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                <div className="flex flex-wrap gap-1">
                                                    <PersonTypeBadges individual={p.is_individual} contact={p.organisations.length > 0} />
                                                </div>
                                            </td>
                                            <td className="px-4 py-2">{orgLine(p) || '—'}</td>
                                            <td className="px-4 py-2 break-all">{p.email ?? '—'}</td>
                                            <td className="px-4 py-2 whitespace-nowrap">{formatPhone(p.phone)}</td>
                                            <td className="px-4 py-2">
                                                <Badge variant={statusVariant(p.status.value)}>{p.status.label}</Badge>
                                            </td>
                                            <td className="px-4 py-2">{p.responsible ?? '—'}</td>
                                            <td className="px-4 py-2">{formatDate(p.last_contact_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Cards below 768 px */}
                        <ul className="grid gap-3 md:hidden">
                            {people.data.map((p) => (
                                <li key={p.id}>
                                    <Link href={route('crm.people.show', p.id)} className="hover:bg-muted/30 block rounded-xl border p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="font-medium">
                                                {p.civility ? `${p.civility} ` : ''}
                                                {p.name}
                                            </span>
                                            <Badge variant={statusVariant(p.status.value)}>{p.status.label}</Badge>
                                        </div>
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            <PersonTypeBadges individual={p.is_individual} contact={p.organisations.length > 0} />
                                        </div>
                                        {orgLine(p) && <p className="text-muted-foreground mt-1 text-sm">{orgLine(p)}</p>}
                                        <p className="text-muted-foreground mt-1 text-sm break-all">
                                            {[p.email, p.phone ? formatPhone(p.phone) : null].filter(Boolean).join(' · ') || '—'}
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-xs">
                                            {p.responsible ?? 'Sans responsable'} · Dernier contact : {formatDate(p.last_contact_at)}
                                        </p>
                                        {p.deleted && (
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
                                {people.from}–{people.to} sur {people.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!people.prev_page_url}
                                    onClick={() => people.prev_page_url && router.get(people.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!people.next_page_url}
                                    onClick={() => people.next_page_url && router.get(people.next_page_url)}
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
