import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Download, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface TrainingRow {
    id: string;
    code: string | null;
    title: string;
    das: string | null;
    duration_hours: string | null;
    duration_days: string | null;
    /** French text (« 1 250,50 »), see ExportCell::display. */
    price_company: string | null;
    price_individual: string | null;
    funding_modes: string[];
    is_active: boolean;
}

interface Filters {
    q: string;
    das: string | null;
    status: string | null;
    funding: string | null;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    trainings: Paginated<TrainingRow>;
    filters: Filters;
    options: { das: Option[]; funding: Option[]; status: Option[]; sort: Option[] };
    can: { manage: boolean; delete: boolean; export: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Catalogue', href: '/catalogue' }];

/** Query string of the filters (list and export read the same keys). */
function catalogueQuery(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.q) query.q = filters.q;
    (['das', 'status', 'funding'] as const).forEach((k) => {
        if (filters[k]) query[k] = String(filters[k]);
    });
    if (filters.sort !== 'title' || filters.dir !== 'asc') {
        query.sort = filters.sort;
        query.dir = filters.dir;
    }

    return query;
}

const euros = (value: string | null) => (value ? `${value} €` : '—');

function duration(row: TrainingRow): string {
    const parts = [row.duration_hours && `${row.duration_hours} h`, row.duration_days && `${row.duration_days} j`].filter(Boolean);

    return parts.length > 0 ? parts.join(' · ') : '—';
}

export default function CatalogueIndex({ trainings, filters, options, can, status }: IndexProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) =>
        router.get(route('catalogue.index'), catalogueQuery({ ...filters, ...changes }), { preserveState: true, replace: true });

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

    const filtered = Boolean(filters.q || filters.das || filters.status || filters.funding);

    const toggle = (row: TrainingRow) => router.post(route('catalogue.toggle', row.id), {}, { preserveScroll: true });
    const destroy = (row: TrainingRow) => {
        if (window.confirm(`Supprimer la formation « ${row.title} » ?`)) {
            router.delete(route('catalogue.destroy', row.id), { preserveScroll: true });
        }
    };

    const actions = (row: TrainingRow) =>
        can.manage && (
            <div className="flex flex-wrap gap-1">
                <Button asChild variant="outline" size="sm">
                    <Link href={route('catalogue.edit', row.id)}>Modifier</Link>
                </Button>
                <Button type="button" variant="outline" size="sm" onClick={() => toggle(row)}>
                    {row.is_active ? 'Désactiver' : 'Activer'}
                </Button>
                {can.delete && (
                    <Button type="button" variant="ghost" size="sm" className="text-destructive" onClick={() => destroy(row)}>
                        Supprimer
                    </Button>
                )}
            </div>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Catalogue" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Catalogue" description="Formations proposées, par DAS, avec leurs tarifs HT par stagiaire" />
                    <div className="flex flex-wrap items-start gap-2">
                        {can.export && (
                            <Button asChild variant="outline" className="hidden md:inline-flex">
                                <a href={route('catalogue.export', catalogueQuery(filters))}>
                                    <Download className="h-4 w-4" />
                                    Exporter (.xlsx)
                                </a>
                            </Button>
                        )}
                        {can.manage && (
                            <Button asChild>
                                <Link href={route('catalogue.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouvelle formation
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                <InputError message={errors.training ?? errors.export ?? errors.columns} />

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (code, intitulé)
                        </Label>
                        <Input id="q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ex. HACCP" />
                    </div>
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect label="Financement" value={filters.funding} options={options.funding} onChange={(v) => visit({ funding: v })} />
                    <FilterSelect label="État" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <div className="flex items-end">
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('catalogue.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                <p className="text-sm">
                    {trainings.total} formation{trainings.total > 1 ? 's' : ''}
                </p>

                {trainings.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune formation ne correspond à ces filtres.' : 'Aucune formation dans le catalogue pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        {sortHeader('code', 'Code')}
                                        {sortHeader('title', 'Intitulé')}
                                        <th className="px-3 py-2 font-medium">DAS</th>
                                        <th className="px-3 py-2 font-medium">Durée</th>
                                        {sortHeader('price_company', 'Tarif entreprise HT', 'text-right')}
                                        <th className="px-3 py-2 text-right font-medium">Tarif particulier HT</th>
                                        <th className="px-3 py-2 font-medium">Financements</th>
                                        {can.manage && <th className="px-3 py-2 font-medium">Actions</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {trainings.data.map((t) => (
                                        <tr key={t.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">{t.code ?? '—'}</td>
                                            <td className="px-3 py-2">
                                                <span className="font-medium">{t.title}</span>{' '}
                                                {!t.is_active && <Badge variant="outline">Désactivée</Badge>}
                                            </td>
                                            <td className="px-3 py-2">{t.das ?? '—'}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{duration(t)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{euros(t.price_company)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{euros(t.price_individual)}</td>
                                            <td className="px-3 py-2">{t.funding_modes.length > 0 ? t.funding_modes.join(', ') : '—'}</td>
                                            {can.manage && <td className="px-3 py-2">{actions(t)}</td>}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-2 md:hidden">
                            {trainings.data.map((t) => (
                                <li key={t.id} className="grid gap-1 rounded-xl border p-3 text-sm">
                                    <div className="font-medium">
                                        {t.title} {!t.is_active && <Badge variant="outline">Désactivée</Badge>}
                                    </div>
                                    <div className="text-muted-foreground text-xs">{[t.code, t.das, duration(t)].filter(Boolean).join(' · ')}</div>
                                    <div>
                                        Entreprise {euros(t.price_company)} · Particulier {euros(t.price_individual)}
                                    </div>
                                    {actions(t)}
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {trainings.from}–{trainings.to} sur {trainings.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!trainings.prev_page_url}
                                    onClick={() => trainings.prev_page_url && router.get(trainings.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!trainings.next_page_url}
                                    onClick={() => trainings.next_page_url && router.get(trainings.next_page_url)}
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
