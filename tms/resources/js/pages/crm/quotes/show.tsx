import { decimalInput, rateLabel } from '@/components/crm/quote-lines';
import { QuoteStatusBadge, type QuoteRow } from '@/components/crm/quote-list';
import { QuoteAnswerDialog, QuoteOutcomeDialog, type ProposeWon, type QuotePipeline } from '@/components/crm/quote-outcome-dialog';
import { QuoteSendDialog, type BrochureChoice, type QuoteMessageDefaults, type SentBrochure } from '@/components/crm/quote-send-dialog';
import { QuoteSignatureButton, type QuoteSignature } from '@/components/crm/quote-signature';
import { QuoteSignedButton, type SignedCopy } from '@/components/crm/quote-signed';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDateTime, formatDay } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BellRing, Check, Copy, FileText, FolderOpen, Pencil, RotateCw, Send, Trash2, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

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
    reminded_at: string | null;
    accepted_on: string | null;
    refused_on: string | null;
    refusal_reason: string | null;
    /** Signed copy sent back by the customer (accepted quote). */
    signed: SignedCopy | null;
    signature: QuoteSignature | null;
    replaced_by: { id: string; number: string } | null;
    email: { status: 'pending' | 'sent' | 'failed'; error: string | null; sent_at: string | null } | null;
    created_at: string | null;
    lines: QuoteLineRow[];
    totals: { ht: string; vat: string; ttc: string; vat_by_rate: { rate: string; base: string; vat: string }[] };
}

interface ShowProps {
    quote: QuoteData;
    can: {
        update: boolean;
        delete: boolean;
        duplicate: boolean;
        send: boolean;
        remind: boolean;
        resend: boolean;
        answer: boolean;
        attachSigned: boolean;
        sign: boolean;
    };
    brochure_choices: BrochureChoice[];
    brochures_sent: SentBrochure[];
    send: QuoteMessageDefaults | null;
    remind: QuoteMessageDefaults | null;
    pipeline: QuotePipeline;
    propose: { won: ProposeWon | null; lost: boolean };
    /** False for a sent quote whose archived PDF is gone: no link, no reminder. */
    pdf_available: boolean;
    /** Actions of other modules on an accepted quote (« Créer le dossier », « Voir le dossier »). */
    links: { key: string; label: string; href: string }[];
    /** Why this draft cannot be sent as it is (VAT regime changed, validity past). */
    send_blocked: string | null;
    vat_regime_changed: boolean;
    status?: string;
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children || '-'}</dd>
        </div>
    );
}

export default function QuoteShow({
    quote: q,
    can,
    send,
    remind,
    brochure_choices: brochureChoices,
    brochures_sent: brochuresSent,
    pipeline,
    propose,
    pdf_available: pdfAvailable,
    links,
    send_blocked: sendBlocked,
    vat_regime_changed: regimeChanged,
    status,
}: ShowProps) {
    const [confirming, setConfirming] = useState(false);
    const [mailing, setMailing] = useState<'send' | 'remind' | null>(null);
    const [answer, setAnswer] = useState<'accept' | 'refuse' | null>(null);
    const [proposal, setProposal] = useState(propose);
    // A new answer (flashed by the server) opens the pipeline proposal again.
    useEffect(() => setProposal(propose), [propose]);
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
                                {q.opportunity.title ?? '-'}
                            </Link>
                            {' · '}
                            {accountHref ? (
                                <Link href={accountHref} className="underline">
                                    {q.account.name ?? '-'}
                                </Link>
                            ) : (
                                (q.account.name ?? '-')
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {links.map((l) => (
                            <Button key={l.key} asChild>
                                <Link href={l.href}>
                                    <FolderOpen className="h-4 w-4" />
                                    {l.label}
                                </Link>
                            </Button>
                        ))}
                        {/* A draft is previewed (watermark); a sent quote opens its archived PDF. */}
                        {pdfAvailable ? (
                            <Button asChild variant="outline">
                                <a href={route('crm.quotes.pdf', q.id)} target="_blank" rel="noopener noreferrer">
                                    <FileText className="h-4 w-4" />
                                    {q.status.value === 'draft' ? 'Aperçu PDF' : 'PDF'}
                                </a>
                            </Button>
                        ) : (
                            <Button variant="outline" disabled title="Le PDF archivé de ce devis n’est plus disponible.">
                                <FileText className="h-4 w-4" />
                                PDF indisponible
                            </Button>
                        )}
                        {can.send && send && (
                            <Button onClick={() => setMailing('send')} disabled={sendBlocked !== null} title={sendBlocked ?? undefined}>
                                <Send className="h-4 w-4" />
                                Envoyer
                            </Button>
                        )}
                        {can.remind && remind && (
                            <Button variant="outline" onClick={() => setMailing('remind')}>
                                <BellRing className="h-4 w-4" />
                                Relancer
                            </Button>
                        )}
                        {can.answer && (
                            <>
                                <Button variant="outline" onClick={() => setAnswer('accept')}>
                                    <Check className="h-4 w-4" />
                                    Accepté
                                </Button>
                                <Button variant="outline" onClick={() => setAnswer('refuse')}>
                                    <X className="h-4 w-4" />
                                    Refusé
                                </Button>
                            </>
                        )}
                        {can.sign && <QuoteSignatureButton quoteId={q.id} number={q.number} signature={q.signature} />}
                        {can.attachSigned && <QuoteSignedButton quoteId={q.id} number={q.number} signed={q.signed} />}
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
                {can.send && sendBlocked && (
                    <p
                        role="alert"
                        className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                        {sendBlocked}
                    </p>
                )}
                {!can.send && regimeChanged && (
                    <p className="text-muted-foreground text-sm">Le régime de TVA de la société a changé depuis l'enregistrement de ce brouillon.</p>
                )}
                {!pdfAvailable && (
                    <p className="text-muted-foreground text-sm">Le PDF archivé de ce devis n’est plus disponible : ni relance ni renvoi possible.</p>
                )}
                {q.email?.status === 'failed' && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                    >
                        <p className="flex gap-2">
                            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>L'e-mail n'est pas parti. {q.email.error}</span>
                        </p>
                        {can.resend && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.post(route('crm.quotes.resend', q.id), {}, { preserveScroll: true })}
                            >
                                <RotateCw className="h-4 w-4" />
                                Renvoyer
                            </Button>
                        )}
                    </div>
                )}
                {q.email?.status === 'pending' && <p className="text-muted-foreground text-sm">E-mail en cours d'envoi…</p>}
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
                        {q.reminded_at && <Item label="Relancé le">{formatDateTime(q.reminded_at)}</Item>}
                        {q.accepted_on && <Item label="Accepté le">{formatDay(q.accepted_on)}</Item>}
                        {q.signed && (
                            <Item label="Devis signé">
                                <a href={q.signed.url} target="_blank" rel="noopener noreferrer" className="underline">
                                    {q.signed.name ?? 'Ouvrir'}
                                </a>
                                {q.signed.at && <span className="text-muted-foreground"> · ajouté le {formatDateTime(q.signed.at)}</span>}
                            </Item>
                        )}
                        {q.refused_on && <Item label="Refusé le">{formatDay(q.refused_on)}</Item>}
                        {q.refusal_reason && <Item label="Motif du refus">{q.refusal_reason}</Item>}
                        {q.replaced_by && (
                            <Item label="Remplacé par">
                                <Link href={route('crm.quotes.show', q.replaced_by.id)} className="underline">
                                    {q.replaced_by.number}
                                </Link>
                            </Item>
                        )}
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
                                            {Number(l.discount_percent) > 0 ? `${decimalInput(l.discount_percent)} %` : '-'}
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

            {send && (
                <QuoteSendDialog
                    quoteId={q.id}
                    number={q.number}
                    kind="send"
                    defaults={send}
                    choices={brochureChoices}
                    open={mailing === 'send'}
                    onClose={() => setMailing(null)}
                />
            )}
            {remind && (
                <QuoteSendDialog
                    quoteId={q.id}
                    number={q.number}
                    kind="remind"
                    defaults={remind}
                    sent={brochuresSent}
                    open={mailing === 'remind'}
                    onClose={() => setMailing(null)}
                />
            )}
            <QuoteAnswerDialog quoteId={q.id} number={q.number} issuedOn={q.issued_on} answer={answer} onClose={() => setAnswer(null)} />
            <QuoteOutcomeDialog
                quoteId={q.id}
                pipeline={pipeline}
                proposeWon={proposal.won}
                proposeLost={proposal.lost}
                onClose={() => setProposal({ won: null, lost: false })}
            />

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
