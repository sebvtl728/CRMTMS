import { decimalInput, rateLabel } from '@/components/crm/quote-lines';
import { QuoteStatusBadge, type QuoteRow } from '@/components/crm/quote-list';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDateTime, formatDay } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Copy, FileText, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface QuoteLineRow {
    id: string;
    training_id: string | null;
    das: string | null;
    label: string;
    description: string | null;
    quantity: string;
    unit: string;
    unit_label: string;
    unit_price: string;
    discount_percent: string;
    vat_rate: string;
    total_ht: string;
}

interface QuoteData extends QuoteRow {
    contact: { id: string; name: string } | null;
    notes: string | null;
    vat_exempt: boolean;
    vat_notice: string | null;
    sent_at: string | null;
    created_at: string | null;
    lines: QuoteLineRow[];
    totals: { ht: string; vat: string; ttc: string; vat_by_rate: { rate: string; base: string; vat: string }[] };
}

interface ShowProps {
    quote: QuoteData;
    can: { update: boolean; delete: boolean; duplicate: boolean };
    status?: string;
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children || '—'}</dd>
        </div>
    );
}

export default function QuoteShow({ quote: q, can, status }: ShowProps) {
    const [confirming, setConfirming] = useState(false);
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Devis', href: route('crm.quotes.index') },
        { title: q.number, href: route('crm.quotes.show', q.id) },
    ];
    const accountHref =
        q.account.id === null
            ? null
            : q.account.type === 'organisation'
              ? route('crm.organisations.show', q.account.id)
              : route('crm.people.show', q.account.id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Devis ${q.number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">Devis {q.number}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <QuoteStatusBadge status={q.status} />
                            {q.das && <Badge variant="outline">{q.das}</Badge>}
                            {q.vat_exempt && <Badge variant="outline">Exonéré de TVA</Badge>}
                        </div>
                        <p className="text-sm">
                            <Link href={route('crm.opportunities.show', q.opportunity.id)} className="underline">
                                {q.opportunity.title ?? '—'}
                            </Link>
                            {' · '}
                            {accountHref ? (
                                <Link href={accountHref} className="underline">
                                    {q.account.name ?? '—'}
                                </Link>
                            ) : (
                                (q.account.name ?? '—')
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* A draft is previewed (watermark); a sent quote opens its archived PDF. */}
                        <Button asChild variant="outline">
                            <a href={route('crm.quotes.pdf', q.id)} target="_blank" rel="noopener noreferrer">
                                <FileText className="h-4 w-4" />
                                {q.status.value === 'draft' ? 'Aperçu PDF' : 'PDF'}
                            </a>
                        </Button>
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('crm.quotes.edit', q.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Modifier
                                </Link>
                            </Button>
                        )}
                        {can.duplicate && (
                            <Button variant="outline" onClick={() => router.post(route('crm.quotes.duplicate', q.id))}>
                                <Copy className="h-4 w-4" />
                                Nouvelle version
                            </Button>
                        )}
                        {can.delete && (
                            <Button variant="outline" onClick={() => setConfirming(true)}>
                                <Trash2 className="h-4 w-4" />
                                Supprimer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {/* A new version refused (numbering): the reason, in French. */}
                <InputError message={errors.company_id ?? errors.lines} />

                <section className="rounded-xl border p-4">
                    <h2 className="mb-3 text-sm font-semibold">Devis</h2>
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Item label="Société émettrice">{q.company}</Item>
                        <Item label="Destinataire">
                            {q.contact && (
                                <Link href={route('crm.people.show', q.contact.id)} className="underline">
                                    {q.contact.name}
                                </Link>
                            )}
                        </Item>
                        <Item label="Date du devis">{formatDay(q.issued_on)}</Item>
                        <Item label="Valable jusqu'au">{formatDay(q.valid_until)}</Item>
                        <Item label="Responsable de l'opportunité">{q.responsible}</Item>
                        <Item label="Créé le">{formatDateTime(q.created_at)}</Item>
                        {q.sent_at && <Item label="Envoyé le">{formatDateTime(q.sent_at)}</Item>}
                    </dl>
                    {q.notes && <p className="mt-3 text-sm whitespace-pre-line">{q.notes}</p>}
                </section>

                <section className="rounded-xl border p-4">
                    <h2 className="mb-3 text-sm font-semibold">Lignes</h2>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left">
                                <tr>
                                    <th className="py-2 pr-3 font-medium">Libellé</th>
                                    <th className="px-3 py-2 text-right font-medium">Quantité</th>
                                    <th className="px-3 py-2 text-right font-medium">Prix unitaire HT</th>
                                    <th className="px-3 py-2 text-right font-medium">Remise</th>
                                    {!q.vat_exempt && <th className="px-3 py-2 text-right font-medium">TVA</th>}
                                    <th className="py-2 pl-3 text-right font-medium">Total HT</th>
                                </tr>
                            </thead>
                            <tbody>
                                {q.lines.map((l) => (
                                    <tr key={l.id} className="border-t align-top">
                                        <td className="py-2 pr-3">
                                            <span className="font-medium">{l.label}</span>
                                            {l.das && <span className="text-muted-foreground"> · {l.das}</span>}
                                            {l.description && <p className="text-muted-foreground whitespace-pre-line">{l.description}</p>}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {decimalInput(l.quantity)} {l.unit_label}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">{formatAmount(l.unit_price)}</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {Number(l.discount_percent) > 0 ? `${decimalInput(l.discount_percent)} %` : '—'}
                                        </td>
                                        {!q.vat_exempt && <td className="px-3 py-2 text-right whitespace-nowrap">{rateLabel(l.vat_rate)}</td>}
                                        <td className="py-2 pl-3 text-right whitespace-nowrap tabular-nums">{formatAmount(l.total_ht)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="grid gap-2 md:hidden">
                        {q.lines.map((l) => (
                            <li key={l.id} className="rounded-lg border p-3 text-sm">
                                <div className="font-medium">{l.label}</div>
                                {l.description && <p className="text-muted-foreground whitespace-pre-line">{l.description}</p>}
                                <p>
                                    {decimalInput(l.quantity)} {l.unit_label} × {formatAmount(l.unit_price)}
                                    {Number(l.discount_percent) > 0 ? ` − ${decimalInput(l.discount_percent)} %` : ''}
                                    {!q.vat_exempt ? ` · TVA ${rateLabel(l.vat_rate)}` : ''}
                                </p>
                                <p className="font-medium tabular-nums">{formatAmount(l.total_ht)} HT</p>
                            </li>
                        ))}
                    </ul>

                    <dl className="mt-4 ml-auto grid w-full max-w-sm gap-1 text-sm">
                        <div className="flex justify-between">
                            <dt>Total HT</dt>
                            <dd className="tabular-nums">{formatAmount(q.totals.ht)}</dd>
                        </div>
                        {q.totals.vat_by_rate.map((r) => (
                            <div key={r.rate} className="text-muted-foreground flex justify-between">
                                <dt>
                                    TVA {rateLabel(r.rate)} sur {formatAmount(r.base)}
                                </dt>
                                <dd className="tabular-nums">{formatAmount(r.vat)}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between font-semibold">
                            <dt>Total TTC</dt>
                            <dd className="tabular-nums">{formatAmount(q.totals.ttc)}</dd>
                        </div>
                        {q.vat_notice && <p className="text-muted-foreground text-xs">{q.vat_notice}</p>}
                    </dl>
                </section>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Supprimer ce brouillon ?</DialogTitle>
                        <DialogDescription>Le devis {q.number} disparaît de la liste. Son numéro n'est pas réattribué.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Annuler
                        </Button>
                        <Button variant="destructive" onClick={() => router.delete(route('crm.quotes.destroy', q.id))}>
                            Supprimer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
