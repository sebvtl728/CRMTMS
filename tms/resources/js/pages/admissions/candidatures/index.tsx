import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { type AdmissionOptions, type CandidatureRow, stageVariant } from '@/types/admissions';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Tab = 'candidats' | 'stagiaires' | 'alternants' | 'tous';

interface Filters {
    onglet: Tab;
    etape: string | null;
    formation: string | null;
    das: string | null;
    responsable: string | null;
    contrat: string | null;
    parcours: string | null;
    q: string | null;
}

interface IndexProps {
    rows: Paginated<CandidatureRow>;
    filters: Filters;
    counts: Record<Tab, number>;
    options: AdmissionOptions;
    can: { create: boolean; export: boolean };
    status?: string;
}

const TABS: { value: Tab; label: string }[] = [
    { value: 'candidats', label: 'Candidats' },
    { value: 'stagiaires', label: 'Stagiaires' },
    { value: 'alternants', label: 'Alternants' },
    { value: 'tous', label: 'Tous' },
];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Candidats', href: '/admissions/candidatures' }];

function listQuery(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};
    (Object.keys(filters) as (keyof Filters)[]).forEach((key) => {
        const value = filters[key];
        if (value && !(key === 'onglet' && value === 'candidats')) query[key] = String(value);
    });

    return query;
}

export default function CandidaturesIndex({ rows, filters, counts, options, can, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q ?? '');
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) =>
        router.get('/admissions/candidatures', listQuery({ ...filters, ...changes }), { preserveState: true, replace: true });

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search || null }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filtered = Boolean(
        filters.q || filters.etape || filters.formation || filters.das || filters.responsable || filters.contrat || filters.parcours,
    );
    const exportUrl = `/admissions/candidatures/export?${new URLSearchParams(listQuery(filters))}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Candidats" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Candidats" description="Candidatures, stagiaires et alternants" />
                    <div className="flex flex-wrap gap-2">
                        {can.export && (
                            <Button asChild variant="outline">
                                <a href={exportUrl}>
                                    <Download className="h-4 w-4" />
                                    Exporter (.csv)
                                </a>
                            </Button>
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link href="/admissions/candidatures/create">
                                    <Plus className="h-4 w-4" />
                                    Nouvelle candidature
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <div role="tablist" aria-label="Vues" className="flex flex-wrap gap-1 border-b">
                    {TABS.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            role="tab"
                            aria-selected={filters.onglet === tab.value}
                            onClick={() => visit({ onglet: tab.value })}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm ${filters.onglet === tab.value ? 'border-primary font-medium' : 'text-muted-foreground border-transparent'}`}
                        >
                            {tab.label} <span className="text-muted-foreground">({counts[tab.value]})</span>
                        </button>
                    ))}
                </div>

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-3">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (nom, numéro)
                        </Label>
                        <Input
                            id="q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Ex. Durand ou C-2026-0004"
                        />
                    </div>
                    <FilterSelect label="Étape" value={filters.etape} options={options.statuses} onChange={(v) => visit({ etape: v })} />
                    <FilterSelect
                        label="Formation"
                        value={filters.formation}
                        options={options.training_options}
                        onChange={(v) => visit({ formation: v })}
                    />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsable}
                        options={options.users}
                        onChange={(v) => visit({ responsable: v })}
                    />
                    <FilterSelect
                        label="Parcours"
                        value={filters.parcours}
                        options={[...options.pathways, { value: 'a_definir', label: 'À définir' }]}
                        onChange={(v) => visit({ parcours: v })}
                    />
                    <FilterSelect label="Contrat" value={filters.contrat} options={options.contract_types} onChange={(v) => visit({ contrat: v })} />
                    {filtered && (
                        <div className="flex items-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.get(
                                        '/admissions/candidatures',
                                        listQuery({
                                            ...filters,
                                            etape: null,
                                            formation: null,
                                            das: null,
                                            responsable: null,
                                            contrat: null,
                                            parcours: null,
                                            q: null,
                                        }),
                                    )
                                }
                            >
                                Réinitialiser
                            </Button>
                        </div>
                    )}
                </section>

                {rows.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune candidature ne correspond à ces filtres.' : 'Aucune candidature dans cette vue.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Numéro</th>
                                        <th className="px-3 py-2 font-medium">Candidat</th>
                                        <th className="px-3 py-2 font-medium">Formation</th>
                                        <th className="px-3 py-2 font-medium">Parcours</th>
                                        <th className="px-3 py-2 font-medium">Responsable</th>
                                        <th className="px-3 py-2 font-medium">Étape</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.data.map((r) => (
                                        <tr key={r.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={`/admissions/candidatures/${r.id}`} className="font-medium hover:underline">
                                                    {r.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">
                                                {r.person}
                                                {r.kind && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        {r.kind}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {r.training ?? '—'}
                                                {r.rncp_code && <div className="text-muted-foreground text-xs">{r.rncp_code}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                {r.has_contract ? (
                                                    <>
                                                        {r.contract_type}
                                                        <div className="text-muted-foreground text-xs">
                                                            {[r.employer, r.contract_starts_on && `du ${formatDate(r.contract_starts_on)}`]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </div>
                                                    </>
                                                ) : (
                                                    (r.pathway_label ?? '—')
                                                )}
                                            </td>
                                            <td className="px-3 py-2">{r.responsible ?? '—'}</td>
                                            <td className="px-3 py-2">
                                                <Badge variant={stageVariant(r.status)}>{r.status_label}</Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-2 md:hidden">
                            {rows.data.map((r) => (
                                <li key={r.id} className="grid gap-1 rounded-xl border p-3 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <Link href={`/admissions/candidatures/${r.id}`} className="font-medium hover:underline">
                                            {r.person}
                                        </Link>
                                        <Badge variant={stageVariant(r.status)}>{r.status_label}</Badge>
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {[r.number, r.training, r.kind, r.contract_type].filter(Boolean).join(' · ')}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {rows.from}–{rows.to} sur {rows.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!rows.prev_page_url}
                                    onClick={() => rows.prev_page_url && router.get(rows.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!rows.next_page_url}
                                    onClick={() => rows.next_page_url && router.get(rows.next_page_url)}
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
