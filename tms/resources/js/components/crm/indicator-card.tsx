import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';

export interface IndicatorCardData {
    key: string;
    label: string;
    formula: string;
    value: number | null;
    previous: number | null;
    delta: number | null;
    unit: 'count' | 'cents' | 'percent' | 'days';
    breakdown: { label: string; value: number | null; extra?: number | null }[] | null;
    target_cents: number | null;
    note: string | null;
    detail_url: string | null;
}

const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });

/** A value in its unit, « - » when it has no meaning. */
export function formatIndicator(value: number | null, unit: IndicatorCardData['unit']): string {
    if (value === null) return '-';
    switch (unit) {
        case 'cents':
            return euros.format(value / 100);
        case 'percent':
            return `${number.format(value)} %`;
        case 'days':
            return `${number.format(value)} j`;
        default:
            return number.format(value);
    }
}

/** A dashboard card: value, previous period and difference, formula, breakdown and the list behind it. */
export function IndicatorCard({ card }: { card: IndicatorCardData }) {
    const Trend = card.delta === null || card.delta === 0 ? ArrowRight : card.delta > 0 ? ArrowUpRight : ArrowDownRight;
    const deltaText =
        card.delta === null ? null : `${card.delta > 0 ? '+' : ''}${formatIndicator(card.delta, card.unit === 'percent' ? 'percent' : card.unit)}`;

    return (
        <section className="flex flex-col gap-2 rounded-xl border p-4" aria-labelledby={`card-${card.key}`} data-testid={`indicator-${card.key}`}>
            <h2 id={`card-${card.key}`} className="text-muted-foreground text-sm font-medium">
                {card.label}
            </h2>
            <p className="text-2xl font-semibold">{formatIndicator(card.value, card.unit)}</p>
            <p className="text-muted-foreground flex items-center gap-1 text-xs">
                <Trend className="h-3.5 w-3.5" />
                Période précédente : {formatIndicator(card.previous, card.unit)}
                {deltaText && ` (${deltaText})`}
            </p>
            {card.target_cents !== null && <p className="text-xs">Objectif : {euros.format(card.target_cents / 100)}</p>}
            {card.breakdown && card.breakdown.length > 0 && (
                <ul className="mt-1 grid gap-1 text-sm">
                    {card.breakdown.map((row) => (
                        <li key={row.label} className="flex justify-between gap-2">
                            <span className="truncate">{row.label}</span>
                            <span className="text-muted-foreground shrink-0">{formatIndicator(row.value, card.unit)}</span>
                        </li>
                    ))}
                </ul>
            )}
            {card.note && <p className="text-muted-foreground text-xs">{card.note}</p>}
            <p className="text-muted-foreground mt-auto border-t pt-2 text-xs">{card.formula}</p>
            {card.detail_url && (
                <Link href={card.detail_url} className="text-primary text-xs underline-offset-4 hover:underline">
                    Voir le détail
                </Link>
            )}
        </section>
    );
}
