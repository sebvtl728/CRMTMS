import { Badge } from '@/components/ui/badge';
import { formatAmount, formatDay } from '@/lib/crm';
import { Link } from '@inertiajs/react';

export interface OpportunityRow {
    id: string;
    title: string;
    target: 'organisation' | 'individual';
    account: { type: 'organisation' | 'person'; id: string; name: string | null };
    das: string | null;
    stage: { id: string; label: string | null; outcome: 'won' | 'lost' | null };
    probability: number;
    amount: string | null;
    weighted_amount: string | null;
    expected_close_on: string | null;
    priority: string | null;
    responsible: string | null;
    created_at: string | null;
}

export function StageBadge({ stage }: { stage: OpportunityRow['stage'] }) {
    const variant = stage.outcome === 'won' ? 'default' : stage.outcome === 'lost' ? 'destructive' : 'secondary';

    return <Badge variant={variant}>{stage.label ?? '-'}</Badge>;
}

/** Cards of opportunities (« Opportunités » tab of a fiche, small screens). */
export function OpportunityCards({ rows, showAccount = true }: { rows: OpportunityRow[]; showAccount?: boolean }) {
    return (
        <ul className="grid gap-3">
            {rows.map((o) => (
                <li key={o.id}>
                    <Link href={route('crm.opportunities.show', o.id)} className="hover:bg-muted/30 block rounded-xl border p-4">
                        <div className="flex items-start justify-between gap-2">
                            <span className="font-medium">{o.title}</span>
                            <StageBadge stage={o.stage} />
                        </div>
                        {showAccount && (
                            <p className="text-muted-foreground mt-1 text-sm">
                                {o.account.name ?? '-'} · {o.target === 'individual' ? 'Particulier' : 'Entreprise'}
                            </p>
                        )}
                        <p className="mt-1 text-sm">
                            {formatAmount(o.amount)} · {o.probability} % · pondéré {formatAmount(o.weighted_amount)}
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            {[o.das, o.priority, o.responsible ?? 'Sans responsable'].filter(Boolean).join(' · ')} · Clôture prévue :{' '}
                            {formatDay(o.expected_close_on)}
                        </p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** « Opportunités » tab of an entreprise or a person. */
export function FicheOpportunities({ rows, createHref }: { rows: OpportunityRow[]; createHref: string | null }) {
    return (
        <div role="tabpanel" id="panel-Opportunités" aria-labelledby="tab-Opportunités" className="grid gap-3">
            {createHref && (
                <div>
                    <Link href={createHref} className="text-sm font-medium underline">
                        Nouvelle opportunité
                    </Link>
                </div>
            )}
            {rows.length === 0 ? (
                <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucune opportunité pour cette fiche.</div>
            ) : (
                <OpportunityCards rows={rows} showAccount={false} />
            )}
        </div>
    );
}
