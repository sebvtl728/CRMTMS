import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/crm';
import { formatCents } from '@/lib/revenue';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

export interface InvoiceBalance {
    ttc_cents: number;
    credited_cents: number;
    paid_cents: number;
    remaining_cents: number;
    status: 'unpaid' | 'partial' | 'paid';
    status_label: string;
    overdue: boolean;
    overpaid: boolean;
}

export interface InvoicePayment {
    id: string;
    received_on: string;
    method: string;
    reference: string | null;
    amount_cents: number;
    payment_cents: number;
    url: string;
}

export function paymentStatusVariant(status: InvoiceBalance['status'], overdue: boolean): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (overdue) return 'destructive';

    return status === 'paid' ? 'default' : status === 'partial' ? 'secondary' : 'outline';
}

/** « Règlements » of an issued invoice: what is paid, what is left, and the payments. */
export function PaymentsPanel({
    invoiceId,
    balance,
    payments,
    canRecord,
}: {
    invoiceId: string;
    balance: InvoiceBalance;
    payments: InvoicePayment[];
    canRecord: boolean;
}) {
    return (
        <section aria-label="Règlements" className="grid gap-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold">Règlements</h2>
                    <Badge variant={paymentStatusVariant(balance.status, balance.overdue)}>
                        {balance.overdue ? 'En retard' : balance.status_label}
                    </Badge>
                    {balance.overpaid && <Badge variant="outline">Trop-perçu</Badge>}
                </div>
                {canRecord && balance.remaining_cents > 0 && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={`/finance/payments/create?invoice=${invoiceId}`}>
                            <Plus className="h-4 w-4" />
                            Enregistrer un règlement
                        </Link>
                    </Button>
                )}
            </div>
            <dl className="grid gap-2 sm:grid-cols-4">
                <div>
                    <dt className="text-muted-foreground text-xs">TTC</dt>
                    <dd className="tabular-nums">{formatCents(balance.ttc_cents)}</dd>
                </div>
                {balance.credited_cents > 0 && (
                    <div>
                        <dt className="text-muted-foreground text-xs">Avoirs</dt>
                        <dd className="tabular-nums">−{formatCents(balance.credited_cents)}</dd>
                    </div>
                )}
                <div>
                    <dt className="text-muted-foreground text-xs">Réglé</dt>
                    <dd className="tabular-nums">{formatCents(balance.paid_cents)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">{balance.overpaid ? 'Trop-perçu' : 'Reste dû'}</dt>
                    <dd className="font-medium tabular-nums">{formatCents(Math.abs(balance.remaining_cents))}</dd>
                </div>
            </dl>
            {payments.length > 0 && (
                <ul className="grid gap-1">
                    {payments.map((p) => (
                        <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-2 py-1">
                            <span>
                                {formatDay(p.received_on)} - {p.method}
                                {p.reference ? ` (${p.reference})` : ''}
                            </span>
                            <span className="tabular-nums">
                                {formatCents(p.amount_cents)}
                                {p.payment_cents !== p.amount_cents && (
                                    <span className="text-muted-foreground text-xs"> sur {formatCents(p.payment_cents)}</span>
                                )}
                                {canRecord && (
                                    <Link href={p.url} className="ml-2 underline">
                                        Modifier
                                    </Link>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
