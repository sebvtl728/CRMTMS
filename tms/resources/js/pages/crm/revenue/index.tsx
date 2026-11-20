import { RevenueTargetsDialog } from '@/components/crm/revenue-targets-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/crm';
import { formatCents, formatCentsWhole, formatRate, type RevenuePeriod, type RevenueRow } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Target } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Totals {
    quotes_cents: number;
    opportunities_cents: number;
    total_cents: number;
    target_cents: number | null;
    /** Revenue of the DAS that have a target: what the target is compared with. */
    targeted_total_cents: number;
    /** Percent of the total target reached (server side, one decimal), null without target. */
    rate: number | null;
}

interface Source {
    kind: 'quote' | 'opportunity';
    id: string;
    number_or_title: string;
    account_label: string;
    date: string;
    amount_cents: number;
    url: string;
}

interface Props {
    period: RevenuePeriod;
    rows: RevenueRow[];
    totals: Totals;
    /** « Ses fiches »: only the user's own quotes and opportunities, no targets. */
    own: boolean;
    can: { manage: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Chiffre d'affaires", href: '/crm/revenue' }];

const TYPES: { value: RevenuePeriod['type']; label: string }[] = [
    { value: 'month', label: 'Mois' },
    { value: 'quarter', label: 'Trimestre' },
    { value: 'year', label: 'Année' },
];

/** Bar and percentage of a target reached (the bar stops at 100 %). */
function Progress({ rate, label }: { rate: number | null; label: string }) {
    if (rate === null) return <span className="text-muted-foreground">-</span>;

    return (
        <div className="flex min-w-32 items-center gap-2">
            <div
                role="progressbar"
                aria-label={`Objectif atteint, ${label}`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.min(100, Math.round(rate))}
                className="bg-muted h-2 flex-1 overflow-hidden rounded-full"
            >
                <div className={`h-full rounded-full ${rate >= 100 ? 'bg-green-600' : 'bg-primary'}`} style={{ width: `${Math.min(100, rate)}%` }} />
            </div>
            <span className="w-14 text-right tabular-nums">{formatRate(rate)}</span>
        </div>
    );
}

/** Side panel: the quotes and won opportunities making one DAS line. */
function SourcesPanel({ row, period, onClose }: { row: RevenueRow | null; period: RevenuePeriod; onClose: () => void }) {
    const [sources, setSources] = useState<Source[] | null>(null);
    const [failed, setFailed] = useState(false);
    const dasId = row?.das_id ?? null;

    useEffect(() => {
        if (dasId === null) return;
        let current = true;
        setSources(null);
        setFailed(false);
        fetch(route('crm.revenue.sources', { das: dasId, period: period.type, key: period.key }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? (response.json() as Promise<{ sources: Source[] }>) : Promise.reject(new Error('refused'))))
            .then((json) => current && setSources(json.sources))
            .catch(() => current && setFailed(true));

        return () => {
            current = false;
        };
    }, [dasId, period.type, period.key]);

    return (
        <Sheet open={row !== null} onOpenChange={(o) => !o && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>{row?.das_label}</SheetTitle>
                    <SheetDescription>Détail du chiffre d'affaires HT · {period.label}</SheetDescription>
                </SheetHeader>
                <div className="mt-4">
                    {failed ? (
                        <p role="alert" className="text-destructive text-sm">
                            Le détail n'a pas pu être chargé, réessayez.
                        </p>
                    ) : sources === null ? (
                        <p className="text-muted-foreground text-sm">Chargement…</p>
                    ) : sources.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucun devis accepté ni opportunité gagnée sur cette période.</p>
                    ) : (
                        <ul className="divide-y">
                            {sources.map((source) => (
                                <li key={`${source.kind}-${source.id}`}>
                                    <Link href={source.url} className="hover:bg-muted/50 flex items-start justify-between gap-3 rounded-md px-2 py-3">
                                        <span className="min-w-0">
                                            <span className="block font-medium break-words">{source.number_or_title}</span>
                                            <span className="text-muted-foreground block text-sm break-words">
                                                {source.kind === 'quote' ? 'Devis accepté' : 'Opportunité gagnée'}
                                                {source.account_label ? ` · ${source.account_label}` : ''} · {formatDay(source.date)}
                                            </span>
                                        </span>
                                        <span className="shrink-0 tabular-nums">{formatCents(source.amount_cents)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

export default function RevenueIndex({ period, rows, totals, own, can, status }: Props) {
    const [detail, setDetail] = useState<RevenueRow | null>(null);
    const [targetsOpen, setTargetsOpen] = useState(false);

    const go = (type: string, key?: string) => route('crm.revenue.index', key ? { period: type, key } : { period: type });
    // Some DAS without target: the total « Atteint » only compares the DAS that have one.
    const partialTarget = totals.target_cents !== null && totals.targeted_total_cents !== totals.total_cents;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chiffre d'affaires" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Chiffre d'affaires" description="Devis acceptés et opportunités gagnées sans devis, par DAS (HT)" />
                    {can.manage && (
                        <Button type="button" variant="outline" onClick={() => setTargetsOpen(true)}>
                            <Target className="h-4 w-4" />
                            Objectifs
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Type de période" className="flex gap-1">
                        {TYPES.map((t) => (
                            <Button key={t.value} asChild size="sm" variant={t.value === period.type ? 'default' : 'outline'}>
                                <Link href={go(t.value)} aria-pressed={t.value === period.type} preserveScroll>
                                    {t.label}
                                </Link>
                            </Button>
                        ))}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button asChild size="icon" variant="outline">
                            <Link href={go(period.type, period.previous_key)} aria-label="Période précédente" preserveScroll>
                                <ChevronLeft className="h-4 w-4" />
                            </Link>
                        </Button>
                        <span className="min-w-36 text-center font-medium capitalize" aria-live="polite">
                            {period.label}
                        </span>
                        <Button asChild size="icon" variant="outline">
                            <Link href={go(period.type, period.next_key)} aria-label="Période suivante" preserveScroll>
                                <ChevronRight className="h-4 w-4" />
                            </Link>
                        </Button>
                    </div>
                </div>

                {own && <p className="text-muted-foreground text-sm">Vos devis et opportunités uniquement.</p>}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">DAS</th>
                                <th className="px-4 py-2 text-right font-medium">CA devis acceptés</th>
                                <th className="px-4 py-2 text-right font-medium">CA opportunités gagnées sans devis</th>
                                <th className="px-4 py-2 text-right font-medium">Total</th>
                                {!own && <th className="px-4 py-2 text-right font-medium">Objectif</th>}
                                {!own && <th className="px-4 py-2 font-medium">Atteint</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={own ? 4 : 6} className="text-muted-foreground px-4 py-6 text-center">
                                        Aucun DAS à afficher.
                                    </td>
                                </tr>
                            )}
                            {rows.map((row) => (
                                <tr key={row.das_id} className="hover:bg-muted/30 border-t">
                                    <th scope="row" className="px-4 py-2 text-left font-medium">
                                        <button type="button" className="text-left hover:underline" onClick={() => setDetail(row)}>
                                            {row.das_label}
                                        </button>
                                    </th>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(row.quotes_cents)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(row.opportunities_cents)}</td>
                                    <td className="px-4 py-2 text-right font-medium tabular-nums">{formatCentsWhole(row.total_cents)}</td>
                                    {!own && <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(row.target_cents)}</td>}
                                    {!own && (
                                        <td className="px-4 py-2">
                                            <Progress rate={row.rate} label={row.das_label} />
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-muted/50 border-t font-semibold">
                                <th scope="row" className="px-4 py-2 text-left">
                                    Total
                                </th>
                                <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(totals.quotes_cents)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(totals.opportunities_cents)}</td>
                                <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(totals.total_cents)}</td>
                                {!own && <td className="px-4 py-2 text-right tabular-nums">{formatCentsWhole(totals.target_cents)}</td>}
                                {!own && (
                                    <td className="px-4 py-2">
                                        <Progress rate={totals.rate} label="total" />
                                    </td>
                                )}
                            </tr>
                        </tfoot>
                    </table>
                </div>
                {!own && partialTarget && (
                    <p className="text-muted-foreground text-sm">
                        « Atteint » du total : calculé sur les seuls DAS ayant un objectif ({formatCentsWhole(totals.targeted_total_cents)} signés sur{' '}
                        {formatCentsWhole(totals.total_cents)}).
                    </p>
                )}
            </div>

            <SourcesPanel row={detail} period={period} onClose={() => setDetail(null)} />
            {can.manage && <RevenueTargetsDialog open={targetsOpen} period={period} rows={rows} onClose={() => setTargetsOpen(false)} />}
        </AppLayout>
    );
}
