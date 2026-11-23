import Heading from '@/components/heading';
import { type IndicatorRow, STATUS_STYLES } from '@/components/qualite/qualiopi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';

interface Props {
    criteria: { number: number; title: string; indicators: IndicatorRow[] }[];
    progress: { ready: number; applicable: number };
    decree: string;
    verify_notice: string;
    statuses: Record<string, string>;
    can: { manage: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Qualiopi', href: '/qualite/qualiopi' }];

/** The Qualiopi file: the 33 indicators by criterion, their state and the audit export. */
export default function QualiopiIndex({ criteria, progress, decree, verify_notice, statuses }: Props) {
    const [filter, setFilter] = useState<string>('');
    const percent = progress.applicable === 0 ? 0 : Math.round((progress.ready * 100) / progress.applicable);
    const shown = (i: IndicatorRow) => filter === '' || (filter === 'na' ? !i.applicable : i.applicable && i.status === filter);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Qualiopi" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Qualiopi" description={`Référentiel national qualité - ${decree}`} />
                    <Button asChild variant="outline">
                        <a href="/qualite/qualiopi/export">
                            <Download className="h-4 w-4" />
                            Exporter le dossier d’audit
                        </a>
                    </Button>
                </div>
                <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    {verify_notice}
                </p>

                <div className="grid gap-1">
                    <div className="text-sm">
                        <strong>
                            {progress.ready} / {progress.applicable}
                        </strong>{' '}
                        indicateurs applicables prêts ({percent} %)
                    </div>
                    <div className="bg-muted h-2 w-full max-w-md overflow-hidden rounded-full">
                        <div className="h-full bg-green-600" style={{ width: `${percent}%` }} />
                    </div>
                </div>

                <div className="flex flex-wrap gap-1" role="group" aria-label="Filtrer par statut">
                    {[['', 'Tous'], ...Object.entries(statuses), ['na', 'Non applicables']].map(([key, label]) => (
                        <Button key={key} size="sm" variant={filter === key ? 'default' : 'outline'} onClick={() => setFilter(key)}>
                            {label}
                        </Button>
                    ))}
                </div>

                {criteria.map((c) => {
                    const rows = c.indicators.filter(shown);
                    if (rows.length === 0) {
                        return null;
                    }
                    const ready = c.indicators.filter((i) => i.applicable && i.status === 'ready').length;
                    const applicable = c.indicators.filter((i) => i.applicable).length;

                    return (
                        <section key={c.number} className="grid gap-2">
                            <h2 className="text-base font-semibold">
                                Critère {c.number} - {c.title}{' '}
                                <span className="text-muted-foreground text-sm font-normal">
                                    ({ready} / {applicable} prêts)
                                </span>
                            </h2>
                            <ul className="divide-y rounded-xl border">
                                {rows.map((i) => (
                                    <li key={i.number}>
                                        <Link
                                            href={`/qualite/qualiopi/${i.number}`}
                                            className="hover:bg-muted/50 flex flex-wrap items-center gap-2 p-3"
                                        >
                                            <span className="w-10 shrink-0 font-semibold">{i.number}</span>
                                            <span className="min-w-0 flex-1">{i.title}</span>
                                            {i.change_label && <Badge variant="outline">{i.change_label}</Badge>}
                                            {i.scope !== 'all' && <Badge variant="outline">{i.scope_label}</Badge>}
                                            {i.review_on && <span className="text-muted-foreground text-xs">Revue le {formatDate(i.review_on)}</span>}
                                            {i.applicable ? (
                                                <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLES[i.status]}`}>
                                                    {statuses[i.status]}
                                                </span>
                                            ) : (
                                                <span className="rounded-full border px-2 py-0.5 text-xs">Non applicable</span>
                                            )}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
            </div>
        </AppLayout>
    );
}
