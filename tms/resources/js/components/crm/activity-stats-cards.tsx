import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface MonthCounts {
    month: string;
    created: number;
    done: number;
    meetings: number;
}

export interface ActivityStats {
    current: MonthCounts;
    previous: MonthCounts;
    months: { key: string; label: string; created: number; done: number; meetings: number }[];
}

const numberFormat = new Intl.NumberFormat('fr-FR');

function Trend({ now, before }: { now: number; before: number }) {
    const diff = now - before;
    const sign = diff > 0 ? '+' : diff < 0 ? '−' : '±';

    return (
        <span className="text-muted-foreground text-xs">
            {sign}
            {numberFormat.format(Math.abs(diff))} par rapport au mois précédent
        </span>
    );
}

/** Bars of the last 12 months (one or two series), the current month last. */
function Bars({
    months,
    series,
}: {
    months: ActivityStats['months'];
    series: { key: 'created' | 'done' | 'meetings'; label: string; className: string }[];
}) {
    const max = Math.max(1, ...months.flatMap((m) => series.map((s) => m[s.key])));

    return (
        <div className="grid gap-1">
            <div className="flex h-20 items-end gap-1" role="img" aria-label={`Évolution sur 12 mois : ${series.map((s) => s.label).join(', ')}`}>
                {months.map((m) => (
                    <div
                        key={m.key}
                        className="flex h-full flex-1 items-end gap-px"
                        title={`${m.label} : ${series.map((s) => `${m[s.key]} ${s.label}`).join(', ')}`}
                    >
                        {series.map((s) => (
                            <div
                                key={s.key}
                                className={`${s.className} w-full rounded-t-sm`}
                                style={{ height: `${(m[s.key] / max) * 100}%`, minHeight: m[s.key] > 0 ? 2 : 0 }}
                            />
                        ))}
                    </div>
                ))}
            </div>
            <div className="text-muted-foreground flex gap-1 text-[10px]">
                {months.map((m) => (
                    <span key={m.key} className="flex-1 truncate text-center">
                        {m.label.slice(0, 3)}
                    </span>
                ))}
            </div>
            {series.length > 1 && (
                <div className="text-muted-foreground flex gap-3 text-xs">
                    {series.map((s) => (
                        <span key={s.key} className="inline-flex items-center gap-1">
                            <span className={`${s.className} inline-block size-2 rounded-sm`} />
                            {s.label}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * « Actions » (created and done) and « Rendez-vous » (fixed) of the month,
 * compared with the previous one, with the last 12 months.
 */
export function ActivityStatsCards({ stats }: { stats: ActivityStats }) {
    const { current, previous, months } = stats;

    return (
        <section aria-label="Actions et rendez-vous du mois" className="grid gap-4 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardDescription>Actions · {current.month}</CardDescription>
                    <CardTitle className="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                        <span>
                            <span className="text-3xl">{numberFormat.format(current.created)}</span>{' '}
                            <span className="text-muted-foreground text-sm font-normal">créée{current.created > 1 ? 's' : ''}</span>
                        </span>
                        <span>
                            <span className="text-3xl">{numberFormat.format(current.done)}</span>{' '}
                            <span className="text-muted-foreground text-sm font-normal">réalisée{current.done > 1 ? 's' : ''}</span>
                        </span>
                    </CardTitle>
                    <CardDescription>
                        Réalisées : <Trend now={current.done} before={previous.done} />
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Bars
                        months={months}
                        series={[
                            { key: 'created', label: 'créées', className: 'bg-muted-foreground/40' },
                            { key: 'done', label: 'réalisées', className: 'bg-primary' },
                        ]}
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Rendez-vous pris · {current.month}</CardDescription>
                    <CardTitle className="text-3xl">{numberFormat.format(current.meetings)}</CardTitle>
                    <CardDescription>
                        <Trend now={current.meetings} before={previous.meetings} />
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Bars months={months} series={[{ key: 'meetings', label: 'rendez-vous', className: 'bg-primary' }]} />
                </CardContent>
            </Card>
        </section>
    );
}
