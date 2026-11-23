import { FilterSelect } from '@/components/crm/filter-select';
import { QuoteCards, QuoteStatusBadge, type QuoteRow } from '@/components/crm/quote-list';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDay, type Option, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Filters {
    q: string;
    status: string | null;
    company: string | null;
    das: string | null;
    responsible: string | null;
    issued_from: string | null;
    issued_to: string | null;
    sort: string;
    dir: 'asc' | 'desc';
}

interface SuccessRate {
    sent: number;
    accepted: number;
    /** In %, null when no quote was sent in the period. */
    rate: number | null;
    period: string;
    label: string;
    periods: Option[];
}

interface IndexProps {
    quotes: Paginated<QuoteRow>;
    filters: Filters;
    options: { status: Option[]; company: Option[]; das: Option[]; responsible: Option[]; sort: Option[] };
    success_rate: SuccessRate;
    can: { export: boolean };
    status?: string;
}

const percent = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
const count = new Intl.NumberFormat('fr-FR');

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Devis', href: '/crm/quotes' }];

function quoteQuery(filters: Filters, period?: string): Record<string, string> {
    const query: Record<string, string> = {};
    if (period && period !== 'month') query.period = period;
    if (filters.q) query.q = filters.q;
    (['status', 'company', 'das', 'responsible', 'issued_from', 'issued_to'] as const).forEach((k) => {
        if (filters[k]) query[k] = String(filters[k]);
    });
    if (filters.sort !== 'issued_on' || filters.dir !== 'desc') {
        query.sort = filters.sort;
        query.dir = filters.dir;
    }

    return query;
}

/**
 * Excel export of the filtered quotes (default columns). A JSON pre-flight
 * tells first when the export is refused (too many rows, throttle).
 */
function QuoteExportButton({ filters }: { filters: Filters }) {
    const [checking, setChecking] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);

    const download = async () => {
        setRefusal(null);
        setChecking(true);
        try {
            const response = await fetch(route('crm.quotes.export.check', quoteQuery(filters)), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = (await response.json().catch(() => ({}))) as { allowed?: boolean; message?: string | null };
            if (!response.ok || json.allowed === false) {
                setRefusal(json.message ?? 'Export impossible pour le moment.');

                return;
            }
            window.location.assign(route('crm.quotes.export', quoteQuery(filters)));
        } catch {
            setRefusal("L'export a échoué, réessayez.");
        } finally {
            setChecking(false);
        }
    };

    return (
        <div className="hidden md:block">
            <Button type="button" variant="outline" onClick={download} disabled={checking}>
                <Download className="h-4 w-4" />
                Exporter (.xlsx)
            </Button>
            {refusal && (
                <p role="alert" className="text-destructive mt-1 text-sm">
                    {refusal}
                </p>
            )}
        </div>
    );
}

/** « Taux de réussite » of the quotes sent in the period (within the list filters). */
function SuccessRateBanner({ rate, onPeriod }: { rate: SuccessRate; onPeriod: (period: string) => void }) {
    return (
        <section aria-label="Taux de réussite" className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3">
            <div className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <div>
                    <span className="text-muted-foreground text-sm">Taux de réussite · {rate.label}</span>{' '}
                    <span className="text-2xl font-semibold tabular-nums">{rate.rate === null ? '-' : `${percent.format(rate.rate)} %`}</span>
                </div>
                <span className="text-sm">
                    {count.format(rate.sent)} envoyé{rate.sent > 1 ? 's' : ''}, {count.format(rate.accepted)} accepté{rate.accepted > 1 ? 's' : ''}
                </span>
            </div>
            <div role="group" aria-label="Période" className="flex gap-1">
                {rate.periods.map((p) => (
                    <Button
                        key={p.value}
                        type="button"
                        size="sm"
                        variant={p.value === rate.period ? 'default' : 'outline'}
                        aria-pressed={p.value === rate.period}
                        onClick={() => onPeriod(p.value)}
                    >
                        {p.label}
                    </Button>
                ))}
            </div>
        </section>
    );
}

export default function QuotesIndex({ quotes, filters, options, success_rate, can }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>, period = success_rate.period) =>
        router.get(route('crm.quotes.index'), quoteQuery({ ...filters, ...changes }, period), { preserveState: true, replace: true });

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const sortBy = (column: string) =>
        visit({ sort: column, dir: filters.sort === column ? (filters.dir === 'asc' ? 'desc' : 'asc') : column === 'issued_on' ? 'desc' : 'asc' });
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
        filters.q || filters.status || filters.company || filters.das || filters.responsible || filters.issued_from || filters.issued_to,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Devis" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Devis" description="Propositions commerciales, créées depuis les opportunités" />
                    {can.export && <QuoteExportButton filters={filters} />}
                </div>

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (numéro, opportunité, entreprise ou personne)
                        </Label>
                        <Input
                            id="q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Ex. DEV-2026 ou Bistrot"
                        />
                    </div>
                    <FilterSelect label="Statut" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    <FilterSelect label="Société" value={filters.company} options={options.company} onChange={(v) => visit({ company: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="issued_from" className="text-xs">
                            Émis à partir du
                        </Label>
                        <Input
                            id="issued_from"
                            type="date"
                            value={filters.issued_from ?? ''}
                            onChange={(e) => visit({ issued_from: e.target.value || null })}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="issued_to" className="text-xs">
                            Émis jusqu'au
                        </Label>
                        <Input
                            id="issued_to"
                            type="date"
                            value={filters.issued_to ?? ''}
                            onChange={(e) => visit({ issued_to: e.target.value || null })}
                        />
                    </div>
                    <div className="flex items-end">
                        {filtered && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.get(route('crm.quotes.index'), success_rate.period === 'month' ? {} : { period: success_rate.period })
                                }
                            >
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                <SuccessRateBanner rate={success_rate} onPeriod={(period) => visit({}, period)} />

                <p className="text-sm">{quotes.total} devis</p>

                {quotes.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucun devis ne correspond à ces filtres.' : 'Aucun devis pour le moment : créez-en un depuis une opportunité.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        {sortHeader('number', 'Numéro')}
                                        {sortHeader('issued_on', 'Date')}
                                        <th className="px-3 py-2 font-medium">Compte</th>
                                        <th className="px-3 py-2 font-medium">Opportunité</th>
                                        <th className="px-3 py-2 font-medium">Statut</th>
                                        {sortHeader('total', 'Total HT', 'text-right')}
                                        <th className="px-3 py-2 text-right font-medium">Total TTC</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {quotes.data.map((q) => (
                                        <tr key={q.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={route('crm.quotes.show', q.id)} className="font-medium underline">
                                                    {q.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDay(q.issued_on)}</td>
                                            <td className="px-3 py-2">{q.account.name ?? '-'}</td>
                                            <td className="px-3 py-2">{q.opportunity.title ?? '-'}</td>
                                            <td className="px-3 py-2">
                                                <QuoteStatusBadge status={q.status} />
                                            </td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatAmount(q.total_ht)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatAmount(q.total_ttc)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="md:hidden">
                            <QuoteCards rows={quotes.data} />
                        </div>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {quotes.from}–{quotes.to} sur {quotes.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!quotes.prev_page_url}
                                    onClick={() => quotes.prev_page_url && router.get(quotes.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!quotes.next_page_url}
                                    onClick={() => quotes.next_page_url && router.get(quotes.next_page_url)}
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
