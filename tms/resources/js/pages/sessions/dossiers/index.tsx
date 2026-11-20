import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option, type Paginated } from '@/lib/crm';
import { dossierStatusVariant, type DossierRow } from '@/lib/dossiers';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Download, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Filters {
    q: string;
    status: string | null;
    das: string | null;
    responsible: string | null;
    trashed: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

interface IndexProps {
    dossiers: Paginated<DossierRow>;
    filters: Filters;
    options: { status: Option[]; das: Option[]; responsible: Option[]; sort: Option[] };
    can: { create: boolean; trash: boolean; export: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dossiers', href: '/dossiers' }];

function dossiersQuery(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.q) query.q = filters.q;
    (['status', 'das', 'responsible'] as const).forEach((k) => {
        if (filters[k]) query[k] = String(filters[k]);
    });
    if (filters.trashed) query.trashed = '1';
    if (filters.sort !== 'created_at' || filters.dir !== 'desc') {
        query.sort = filters.sort;
        query.dir = filters.dir;
    }

    return query;
}

export default function DossiersIndex({ dossiers, filters, options, can, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const [checking, setChecking] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) =>
        router.get(route('dossiers.index'), dossiersQuery({ ...filters, ...changes }), { preserveState: true, replace: true });

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const sortHeader = (column: string, label: string) => (
        <th className="px-3 py-2 font-medium" aria-sort={filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button
                type="button"
                onClick={() => visit({ sort: column, dir: filters.sort === column && filters.dir === 'asc' ? 'desc' : 'asc' })}
                className="inline-flex items-center gap-1 hover:underline"
            >
                {label}
                {filters.sort === column && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
            </button>
        </th>
    );

    const filtered = Boolean(filters.q || filters.status || filters.das || filters.responsible || filters.trashed);
    const query = new URLSearchParams(dossiersQuery(filters));
    const exportError = refusal ?? errors.export ?? errors.columns ?? null;

    /** A JSON pre-flight first: the export may be refused (too many rows, throttle). */
    const download = async () => {
        setRefusal(null);
        setChecking(true);
        try {
            const response = await fetch(`${route('dossiers.export.check')}?${query}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = (await response.json().catch(() => ({}))) as { allowed?: boolean; message?: string | null };
            if (!response.ok || json.allowed === false) {
                setRefusal(json.message ?? 'Export impossible pour le moment.');

                return;
            }
            window.location.assign(`${route('dossiers.export')}?${query}`);
        } catch {
            setRefusal("L'export a échoué, réessayez.");
        } finally {
            setChecking(false);
        }
    };

    const statusBadge = (row: DossierRow) => (
        <Badge variant={dossierStatusVariant(row.status)} className="whitespace-nowrap">
            {row.status_label}
        </Badge>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dossiers" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Dossiers" description="Dossiers de formation : contractant, lignes, prix et état" />
                    <div className="flex flex-wrap items-start gap-2">
                        {can.export && (
                            <div className="grid gap-1">
                                <Button type="button" variant="outline" onClick={download} disabled={checking}>
                                    <Download className="h-4 w-4" />
                                    Exporter (.csv)
                                </Button>
                                {exportError && (
                                    <p role="alert" className="text-destructive max-w-xs text-sm">
                                        {exportError}
                                    </p>
                                )}
                            </div>
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link href={route('dossiers.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouveau dossier
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (numéro, contractant, formation)
                        </Label>
                        <Input
                            id="q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Ex. D-2026-0012 ou Hôtel du Parc"
                        />
                    </div>
                    <FilterSelect label="État" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <div className="flex items-end gap-3">
                        {can.trash && (
                            <Label className="flex items-center gap-2 text-sm font-normal">
                                <Checkbox checked={filters.trashed} onCheckedChange={(v) => visit({ trashed: v === true })} />
                                Corbeille
                            </Label>
                        )}
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('dossiers.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                <p className="text-sm">
                    {dossiers.total} dossier{dossiers.total > 1 ? 's' : ''}
                </p>

                {dossiers.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucun dossier ne correspond à ces filtres.' : 'Aucun dossier pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        {sortHeader('number', 'Numéro')}
                                        <th className="px-3 py-2 font-medium">Contractant</th>
                                        <th className="px-3 py-2 font-medium">Formations</th>
                                        <th className="px-3 py-2 text-right font-medium">Prix HT</th>
                                        <th className="px-3 py-2 text-right font-medium">Reste à financer</th>
                                        <th className="px-3 py-2 font-medium">Responsable</th>
                                        <th className="px-3 py-2 font-medium">État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {dossiers.data.map((d) => (
                                        <tr key={d.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={route('dossiers.show', d.id)} className="font-medium hover:underline">
                                                    {d.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">
                                                {d.account.label}
                                                <div className="text-muted-foreground text-xs">
                                                    {d.account.type === 'organisation' ? 'Entreprise' : 'Particulier'}
                                                    {d.das && ` · ${d.das}`}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2">{d.training_titles.length > 0 ? d.training_titles.join(', ') : '-'}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">{d.price}</td>
                                            <td
                                                className={`px-3 py-2 text-right ${d.remaining_cents < 0 ? 'text-destructive' : d.remaining_cents > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}
                                            >
                                                {d.remaining}
                                            </td>
                                            <td className="px-3 py-2">{d.responsible ?? '-'}</td>
                                            <td className="px-3 py-2">{statusBadge(d)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-2 md:hidden">
                            {dossiers.data.map((d) => (
                                <li key={d.id} className="grid gap-1 rounded-xl border p-3 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <Link href={route('dossiers.show', d.id)} className="font-medium hover:underline">
                                            {d.number}
                                        </Link>
                                        {statusBadge(d)}
                                    </div>
                                    <div>{d.account.label}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {[
                                            d.training_titles.join(', '),
                                            d.price,
                                            d.remaining_cents > 0 ? `Reste à financer : ${d.remaining}` : d.remaining_cents < 0 ? d.remaining : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {dossiers.from}–{dossiers.to} sur {dossiers.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!dossiers.prev_page_url}
                                    onClick={() => dossiers.prev_page_url && router.get(dossiers.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!dossiers.next_page_url}
                                    onClick={() => dossiers.next_page_url && router.get(dossiers.next_page_url)}
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
