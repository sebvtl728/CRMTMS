import { Badge } from '@/components/ui/badge';
import { formatAmount, formatDay, type Option } from '@/lib/crm';
import { Link } from '@inertiajs/react';

export interface QuoteRow {
    id: string;
    number: string;
    status: Option;
    company: string | null;
    opportunity: { id: string; title: string | null };
    account: { type: 'organisation' | 'person'; id: string | null; name: string | null };
    das: string | null;
    responsible: string | null;
    issued_on: string;
    valid_until: string;
    total_ht: string;
    total_ttc: string;
}

export function QuoteStatusBadge({ status }: { status: Option }) {
    const variant = status.value === 'accepted' ? 'default' : status.value === 'refused' || status.value === 'expired' ? 'destructive' : 'secondary';

    return <Badge variant={status.value === 'draft' ? 'outline' : variant}>{status.label}</Badge>;
}

/** Cards of quotes (« Devis » tab of a fiche or an opportunity, small screens). */
export function QuoteCards({ rows, showAccount = true }: { rows: QuoteRow[]; showAccount?: boolean }) {
    return (
        <ul className="grid gap-3">
            {rows.map((q) => (
                <li key={q.id}>
                    <Link href={route('crm.quotes.show', q.id)} className="hover:bg-muted/30 block rounded-xl border p-4">
                        <div className="flex items-start justify-between gap-2">
                            <span className="font-medium">{q.number}</span>
                            <QuoteStatusBadge status={q.status} />
                        </div>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {[showAccount ? q.account.name : null, q.opportunity.title, q.company].filter(Boolean).join(' · ')}
                        </p>
                        <p className="mt-1 text-sm">
                            {formatAmount(q.total_ht)} HT · {formatAmount(q.total_ttc)} TTC
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Du {formatDay(q.issued_on)}, valable jusqu'au {formatDay(q.valid_until)}
                        </p>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** « Devis » tab of an entreprise or a person: quotes are created from an opportunity. */
export function FicheQuotes({ rows }: { rows: QuoteRow[] }) {
    return (
        <div role="tabpanel" id="panel-Devis" aria-labelledby="tab-Devis" className="grid gap-3">
            <p className="text-muted-foreground text-sm">Un devis se crée depuis l'une des opportunités de la fiche.</p>
            {rows.length === 0 ? (
                <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucun devis pour cette fiche.</div>
            ) : (
                <QuoteCards rows={rows} />
            )}
        </div>
    );
}
