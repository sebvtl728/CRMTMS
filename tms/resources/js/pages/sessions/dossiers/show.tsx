import { DossierBlockPanel, type DossierBlock } from '@/components/dossier-blocks';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { EnrolDialog, type EnrolSessionOption } from '@/components/sessions/enrolment-dialog';
import { EnrolmentList, type EnrolmentReferences, type EnrolmentRow } from '@/components/sessions/enrolment-list';
import { FundingSection, type FundingBalance, type FundingOptions, type FundingRow } from '@/components/sessions/funding-section';
import { HistoryList, type HistoryEntry } from '@/components/sessions/history-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDay, type Option } from '@/lib/crm';
import { dossierStatusVariant, ficheRoute, type DossierRow } from '@/lib/dossiers';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Pencil, UserPlus } from 'lucide-react';
import { FormEventHandler, useState, type ReactNode } from 'react';

type Step = 'convention-sent' | 'convention-signed' | 'convocations-sent' | 'running' | 'done';

interface Line {
    id: string;
    kind: 'training' | 'fee';
    kind_label: string;
    training: { id: string; title: string; code: string | null } | null;
    label: string;
    quantity: string;
    unit: string;
    unit_price: string;
    discount_percent: string;
    total_ht: string;
    pricing_mode_label: string | null;
}

interface ShowProps {
    dossier: DossierRow & {
        stored_status: string;
        company: string | null;
        contact: { id: string; label: string; can_open: boolean } | null;
        convention_sent_on: string | null;
        convention_signed_on: string | null;
        convocations_sent_on: string | null;
        cancel_reason: string | null;
        cancelled_on: string | null;
        abandon_rule: { label: string; overridden: boolean };
        notes: string | null;
        created_by: string | null;
        created_at: string | null;
        lines: Line[];
    };
    can: { update: boolean; steps: Record<Step, boolean>; cancel: boolean; fund: boolean; delete: boolean; restore: boolean };
    today: string;
    cancel_reasons: Option[];
    enrolments: EnrolmentRow[];
    /** Null without sessions.sessions.update or on a closed dossier. */
    enrol: { sessions: Record<string, EnrolSessionOption[]> } | null;
    enrolment_references: EnrolmentReferences | null;
    fundings: FundingRow[];
    balance: FundingBalance;
    /** Null without sessions.dossiers.update on this dossier or on a closed dossier. */
    funding_options: FundingOptions | null;
    /** Creation, states, enrolments and fundings, most recent first (journal). */
    history: HistoryEntry[];
    /** Blocks of other modules (« Facturation »…): the shape of the blocks of a fiche, and optionally a component. */
    blocks: DossierBlock[];
    status?: string;
}

/** The dated steps: button label and dialog title. */
const DATED: { step: Step; label: string; title: string }[] = [
    { step: 'convention-sent', label: 'Convention envoyée', title: 'Convention envoyée le' },
    { step: 'convention-signed', label: 'Convention signée', title: 'Convention signée le' },
    { step: 'convocations-sent', label: 'Convocations envoyées', title: 'Convocations envoyées le' },
];

function StepDialog({
    dossierId,
    step,
    today,
    onClose,
}: {
    dossierId: string;
    step: (typeof DATED)[number] | null;
    today: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ on: today });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!step) return;
        post(route('dossiers.step', [dossierId, step.step]), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={step !== null} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{step?.label}</DialogTitle>
                        <DialogDescription>Indiquez le jour où l'étape a eu lieu.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="on">{step?.title} *</Label>
                        <Input id="on" type="date" value={data.on} onChange={(e) => setData('on', e.target.value)} />
                        <InputError message={errors.on} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CancelDialog({ dossierId, reasons, open, onClose }: { dossierId: string; reasons: Option[]; open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancelled_reason_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('dossiers.cancel', dossierId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Annuler le dossier</DialogTitle>
                        <DialogDescription>Un dossier annulé ne se modifie plus. Le motif est obligatoire.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="cancelled_reason_id">Motif d'annulation *</Label>
                        <Select value={data.cancelled_reason_id || undefined} onValueChange={(v) => setData('cancelled_reason_id', v)}>
                            <SelectTrigger id="cancelled_reason_id">
                                <SelectValue placeholder="Choisissez" />
                            </SelectTrigger>
                            <SelectContent>
                                {reasons.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cancelled_reason_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Annuler le dossier
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function DossierShow({
    dossier,
    can,
    today,
    cancel_reasons,
    enrolments,
    enrol,
    enrolment_references,
    fundings,
    balance,
    funding_options,
    history,
    blocks,
    status,
}: ShowProps) {
    const [cancelling, setCancelling] = useState(false);
    const [enrolling, setEnrolling] = useState(false);
    const trainingLines = dossier.lines.filter((l) => l.kind === 'training');
    const [dated, setDated] = useState<(typeof DATED)[number] | null>(null);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dossiers', href: '/dossiers' },
        { title: dossier.number, href: '#' },
    ];

    const destroy = () => {
        if (window.confirm(`Mettre le dossier ${dossier.number} à la corbeille ?`)) {
            router.delete(route('dossiers.destroy', dossier.id));
        }
    };

    const mark = (step: 'running' | 'done', label: string) => {
        if (window.confirm(`Passer le dossier ${dossier.number} à « ${label} » ? Cet état ne sera plus calculé d'après les dates des sessions.`)) {
            router.post(route('dossiers.step', [dossier.id, step]), {}, { preserveScroll: true });
        }
    };

    const field = (label: string, value: ReactNode) => (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{value}</dd>
        </div>
    );

    const fiche = (type: 'organisation' | 'person', id: string, label: string, canOpen: boolean, trashed = false) => {
        const text = `${label}${trashed ? ' (supprimée)' : ''}`;

        return canOpen ? (
            <Link href={ficheRoute(type, id)} className="hover:underline">
                {text}
            </Link>
        ) : (
            text
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Dossier ${dossier.number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-2">
                        <Heading title={`Dossier ${dossier.number}`} description={dossier.account.label} />
                        <div className="flex flex-wrap gap-2">
                            <Badge variant={dossierStatusVariant(dossier.status)}>{dossier.status_label}</Badge>
                            {dossier.trashed && <Badge variant="destructive">Dans la corbeille</Badge>}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('dossiers.edit', dossier.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Modifier
                                </Link>
                            </Button>
                        )}
                        {can.cancel && (
                            <Button type="button" variant="outline" onClick={() => setCancelling(true)}>
                                Annuler le dossier
                            </Button>
                        )}
                        {can.delete && (
                            <Button type="button" variant="ghost" className="text-destructive" onClick={destroy}>
                                Mettre à la corbeille
                            </Button>
                        )}
                        {can.restore && (
                            <Button type="button" onClick={() => router.post(route('dossiers.restore', dossier.id))}>
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {dossier.status === 'cancelled' && (
                    <div className="border-destructive/40 bg-destructive/5 rounded-xl border p-3 text-sm">
                        Annulé le {formatDay(dossier.cancelled_on)} · Motif : {dossier.cancel_reason ?? '—'}
                    </div>
                )}

                <section aria-label="Étapes" className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Étapes</h2>
                    <ol className="grid gap-2 text-sm sm:grid-cols-3">
                        <li>Convention envoyée : {formatDay(dossier.convention_sent_on)}</li>
                        <li>Convention signée : {formatDay(dossier.convention_signed_on)}</li>
                        <li>Convocations envoyées : {formatDay(dossier.convocations_sent_on)}</li>
                    </ol>
                    <div className="flex flex-wrap gap-2">
                        {DATED.filter((d) => can.steps[d.step]).map((d) => (
                            <Button key={d.step} type="button" size="sm" onClick={() => setDated(d)}>
                                {d.label}…
                            </Button>
                        ))}
                        {can.steps.running && (
                            <Button type="button" size="sm" variant="outline" onClick={() => mark('running', 'En cours')}>
                                Passer « En cours »
                            </Button>
                        )}
                        {can.steps.done && (
                            <Button type="button" size="sm" variant="outline" onClick={() => mark('done', 'Réalisé')}>
                                Passer « Réalisé »
                            </Button>
                        )}
                    </div>
                </section>

                <section aria-label="Informations" className="rounded-xl border p-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {field(
                            dossier.account.type === 'organisation' ? 'Contractant (entreprise)' : 'Contractant (particulier)',
                            fiche(dossier.account.type, dossier.account.id, dossier.account.label, dossier.account.can_open, dossier.account.trashed),
                        )}
                        {field(
                            'Contact',
                            dossier.contact ? fiche('person', dossier.contact.id, dossier.contact.label, dossier.contact.can_open) : '—',
                        )}
                        {field('Société émettrice', dossier.company ?? '—')}
                        {field('DAS', dossier.das ?? '—')}
                        {field('Responsable', dossier.responsible ?? '—')}
                        {field(
                            "Règle en cas d'abandon",
                            `${dossier.abandon_rule.label}${dossier.abandon_rule.overridden ? ' (propre au dossier)' : " (règle de l'organisme)"}`,
                        )}
                        {field('Créé', `${formatDateTime(dossier.created_at)}${dossier.created_by ? ` par ${dossier.created_by}` : ''}`)}
                    </dl>
                    {dossier.notes && (
                        <div className="mt-4 grid gap-0.5">
                            <div className="text-muted-foreground text-xs">Notes</div>
                            <p className="text-sm whitespace-pre-line">{dossier.notes}</p>
                        </div>
                    )}
                </section>

                <section aria-label="Lignes" className="grid gap-3">
                    <h2 className="text-sm font-semibold">Lignes</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Libellé</th>
                                    <th className="px-3 py-2 font-medium">Type</th>
                                    <th className="px-3 py-2 text-right font-medium">Quantité</th>
                                    <th className="px-3 py-2 text-right font-medium">Prix unitaire HT</th>
                                    <th className="px-3 py-2 text-right font-medium">Remise</th>
                                    <th className="px-3 py-2 text-right font-medium">Total HT</th>
                                </tr>
                            </thead>
                            <tbody>
                                {dossier.lines.map((l) => (
                                    <tr key={l.id} className="border-t">
                                        <td className="px-3 py-2">
                                            {l.label}
                                            {l.training && l.training.title !== l.label && (
                                                <div className="text-muted-foreground text-xs">{l.training.title}</div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {l.kind_label}
                                            {l.pricing_mode_label && <div className="text-muted-foreground text-xs">{l.pricing_mode_label}</div>}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {l.quantity} {l.unit}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">{l.unit_price}</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {l.discount_percent === '0,00' ? '—' : `${l.discount_percent} %`}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">{l.total_ht}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t font-semibold">
                                    <td className="px-3 py-2" colSpan={5}>
                                        Prix du dossier HT
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">{dossier.price}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>

                <FundingSection dossierId={dossier.id} fundings={fundings} balance={balance} options={can.fund ? funding_options : null} />

                <section aria-label="Inscriptions" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">Inscriptions</h2>
                        {enrol && trainingLines.length > 0 && (
                            <Button type="button" size="sm" onClick={() => setEnrolling(true)}>
                                <UserPlus className="h-4 w-4" />
                                Inscrire des stagiaires
                            </Button>
                        )}
                    </div>
                    {trainingLines.length === 0 ? (
                        <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">
                            Ce dossier n'a pas de ligne de formation.
                        </div>
                    ) : (
                        trainingLines.map((l) => (
                            <div key={l.id} className="grid gap-2">
                                <h3 className="text-muted-foreground text-xs font-medium">{l.label}</h3>
                                <EnrolmentList
                                    rows={enrolments.filter((e) => e.line?.id === l.id)}
                                    context="dossier"
                                    references={enrolment_references}
                                />
                            </div>
                        ))
                    )}
                </section>

                {blocks.map((b) => (
                    <section key={b.key} aria-label={b.title} className="grid gap-3">
                        <h2 className="text-sm font-semibold">{b.title}</h2>
                        <DossierBlockPanel block={b} />
                    </section>
                ))}

                <HistoryList entries={history} />
            </div>

            <StepDialog dossierId={dossier.id} step={dated} today={today} onClose={() => setDated(null)} />
            {enrolling && enrol && (
                <EnrolDialog
                    source={{
                        from: 'dossier',
                        dossierId: dossier.id,
                        organisationId: dossier.account.type === 'organisation' ? dossier.account.id : null,
                        organisationLabel: dossier.account.type === 'organisation' ? dossier.account.label : null,
                        lines: trainingLines.map((l) => ({ id: l.id, label: l.label })),
                        sessions: enrol.sessions,
                    }}
                    onClose={() => setEnrolling(false)}
                />
            )}
            {can.cancel && <CancelDialog dossierId={dossier.id} reasons={cancel_reasons} open={cancelling} onClose={() => setCancelling(false)} />}
        </AppLayout>
    );
}
