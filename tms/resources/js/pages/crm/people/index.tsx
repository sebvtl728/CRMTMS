import { FilterSelect } from '@/components/crm/filter-select';
import { ListToolbar, useListColumns, type ColumnChoice, type SavedFilterRow } from '@/components/crm/filters';
import { ListTable, type TableRow } from '@/components/crm/list-table';
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
import { ChevronLeft, ChevronRight, Plus, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** GDPR badges of a person in the list. */
const personBadges = (p: { prospecting_opt_out: boolean; anonymised: boolean }) =>
    [p.anonymised ? 'Anonymisée' : null, p.prospecting_opt_out ? 'Opposé à la prospection' : null].filter((b): b is string => b !== null);

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
    prospecting_opt_out: boolean;
    anonymised: boolean;
    cells: TableRow['cells'];
}

interface Filters {
    q: string;
    type: string | null;
    status: string | null;
    situation: string | null;
    funding: string | null;
    origin: string | null;
    responsible: string | null;
    training: string | null;
    mine: boolean;
    trashed: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    people: Paginated<Row>;
    filters: Filters;
    options: {
        type: Option[];
        status: Option[];
        situation: Option[];
        funding: Option[];
        origin: Option[];
        responsible: Option[];
        training: Option[];
    };
    can: { create: boolean; delete: boolean; export: boolean; import: boolean };
    savedFilters: SavedFilterRow[];
    activeSavedId: string | null;
    columns: ColumnChoice;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Interlocuteurs', href: '/crm/people' }];

export default function PeopleIndex({ people, filters, options, can, savedFilters, activeSavedId, columns, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);
    const [selected, setSelected] = useListColumns('people', columns, savedFilters, activeSavedId);

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        (['type', 'status', 'situation', 'funding', 'origin', 'responsible', 'training'] as const).forEach((k) => {
            if (next[k]) query[k] = String(next[k]);
        });
        if (next.mine) query.mine = '1';
        if (activeSavedId) query.saved = activeSavedId;
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

    const filtered = Boolean(
        filters.q ||
            filters.type ||
            filters.status ||
            filters.situation ||
            filters.funding ||
            filters.origin ||
            filters.responsible ||
            filters.training ||
            filters.mine ||
            filters.trashed,
    );

    const orgLine = (p: Row) => p.organisations.map((o) => o.name).join(', ');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Interlocuteurs" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Interlocuteurs" description="Particuliers et interlocuteurs de vos entreprises" />
                    <div className="flex flex-wrap gap-2">
                        {can.import && (
                            <Button asChild variant="outline">
                                <Link href={route('crm.import.create')}>
                                    <Upload className="h-4 w-4" />
                                    Importer
                                </Link>
                            </Button>
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link href={route('crm.people.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouvelle personne
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <ListToolbar
                    resource="people"
                    filters={filters}
                    savedFilters={savedFilters}
                    columns={columns}
                    canExport={can.export}
                    activeId={activeSavedId}
                    selected={selected}
                    onSelectedChange={setSelected}
                />

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
                        label="Formation d'intérêt"
                        value={filters.training}
                        options={options.training}
                        onChange={(v) => visit({ training: v })}
                    />
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
                        <ListTable
                            rows={people.data.map((p) => ({ ...p, badges: personBadges(p) }))}
                            options={columns.options}
                            selected={selected}
                            titleKeys={['last_name']}
                            href={(id) => route('crm.people.show', id)}
                            sortKeys={{ last_name: 'name', city: 'city', last_contact_at: 'last_contact_at', created_at: 'created_at' }}
                            sort={filters.sort}
                            dir={filters.dir}
                            onSort={sortBy}
                        />

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
                                            {personBadges(p).map((b) => (
                                                <Badge key={b} variant="outline">
                                                    {b}
                                                </Badge>
                                            ))}
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
