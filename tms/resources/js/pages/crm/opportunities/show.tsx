import { ActivityList, type ActivityRow } from '@/components/crm/activity-list';
import { BrochureSendButton, BrochureSendingList, type BrochureSendingRow, type BrochureSendProps } from '@/components/crm/brochure-send';
import { StageBadge, type OpportunityRow } from '@/components/crm/opportunity-list';
import { OpportunityTrainingsSummary, type TrainingEstimate, type TrainingLine } from '@/components/crm/opportunity-trainings';
import { QuickActionFicheButton } from '@/components/crm/quick-action';
import { QuoteCards, type QuoteRow } from '@/components/crm/quote-list';
import { StageDialog, type StageOption } from '@/components/crm/stage-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatAmount, formatDateTime, formatDay, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRightLeft, FilePlus, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface OpportunityData extends OpportunityRow {
    funding_mode: { id: string; label: string } | null;
    loss_reason: string | null;
    loss_comment: string | null;
    won_on: string | null;
    lost_on: string | null;
    cycle_days: number | null;
    probability_overridden: boolean;
    contacts: { id: string; name: string }[];
    open: boolean;
}

interface ShowProps {
    opportunity: OpportunityData;
    history: { id: string; from: string | null; to: string | null; by: string | null; at: string }[];
    activities: ActivityRow[];
    quotes: QuoteRow[];
    brochure_send: BrochureSendProps | null;
    brochure_sendings: BrochureSendingRow[];
    training_summary: { lines: TrainingLine[]; estimate: TrainingEstimate };
    stages: StageOption[];
    lossReasons: Option[];
    can: { update: boolean; delete: boolean; createQuote: boolean };
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

export default function OpportunityShow({
    opportunity: o,
    history,
    activities,
    quotes,
    brochure_send,
    brochure_sendings,
    training_summary: trainings,
    stages,
    lossReasons,
    can,
    status,
}: ShowProps) {
    const [moving, setMoving] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Opportunités', href: '/crm/opportunities' },
        { title: o.title, href: route('crm.opportunities.show', o.id) },
    ];
    const accountHref = o.account.type === 'organisation' ? route('crm.organisations.show', o.account.id) : route('crm.people.show', o.account.id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={o.title} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">{o.title}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <StageBadge stage={o.stage} />
                            <Badge variant="outline">{o.target === 'individual' ? 'Particulier' : 'Entreprise'}</Badge>
                            {o.das && <Badge variant="outline">{o.das}</Badge>}
                            {o.priority && <Badge variant="outline">Priorité : {o.priority}</Badge>}
                        </div>
                        <p className="text-sm">
                            <Link href={accountHref} className="underline">
                                {o.account.name ?? '-'}
                            </Link>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button onClick={() => setMoving(true)}>
                                <ArrowRightLeft className="h-4 w-4" />
                                Changer d'étape
                            </Button>
                        )}
                        <QuickActionFicheButton context={{ opportunity_id: o.id }} available={can.update} />
                        <BrochureSendButton send={brochure_send} routeName="crm.opportunities.brochures.send" subjectId={o.id} />
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('crm.opportunities.edit', o.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Modifier
                                </Link>
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

                {o.target === 'individual' && (
                    <div className="rounded-xl border border-blue-300 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-50">
                        Mode de financement : <strong>{o.funding_mode?.label ?? 'non renseigné'}</strong>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Affaire</h2>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Item label="Montant estimé">{formatAmount(o.amount)}</Item>
                            <Item label="Probabilité">
                                {o.probability} %{o.probability_overridden ? ' (modifiée à la main)' : ''}
                            </Item>
                            <Item label="Montant pondéré">{formatAmount(o.weighted_amount)}</Item>
                            <Item label="Clôture prévue">{formatDay(o.expected_close_on)}</Item>
                            {o.target === 'organisation' && <Item label="Mode de financement">{o.funding_mode?.label}</Item>}
                            <Item label="Responsable">{o.responsible}</Item>
                            <Item label="Créée le">{formatDateTime(o.created_at)}</Item>
                            {o.won_on && <Item label="Gagnée le">{formatDay(o.won_on)}</Item>}
                            {o.cycle_days !== null && <Item label="Durée du cycle">{`${o.cycle_days} jour${o.cycle_days > 1 ? 's' : ''}`}</Item>}
                            {o.lost_on && <Item label="Perdue le">{formatDay(o.lost_on)}</Item>}
                            {o.loss_reason && <Item label="Motif de perte">{o.loss_reason}</Item>}
                        </dl>
                        {o.loss_comment && <p className="mt-3 text-sm whitespace-pre-line">{o.loss_comment}</p>}
                    </section>
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Interlocuteurs</h2>
                        {o.contacts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aucun interlocuteur lié.</p>
                        ) : (
                            <ul className="grid gap-1 text-sm">
                                {o.contacts.map((c) => (
                                    <li key={c.id}>
                                        <Link href={route('crm.people.show', c.id)} className="underline">
                                            {c.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <section className="rounded-xl border p-4 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold">Formations</h2>
                        <OpportunityTrainingsSummary lines={trainings.lines} estimate={trainings.estimate} />
                    </section>
                    <section className="rounded-xl border p-4 lg:col-span-2" aria-labelledby="opportunity-quotes">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 id="opportunity-quotes" className="text-sm font-semibold">
                                Devis
                            </h2>
                            {can.createQuote && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={route('crm.quotes.create', o.id)}>
                                        <FilePlus className="h-4 w-4" />
                                        Nouveau devis
                                    </Link>
                                </Button>
                            )}
                        </div>
                        {quotes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aucun devis pour cette opportunité.</p>
                        ) : (
                            <QuoteCards rows={quotes} showAccount={false} />
                        )}
                    </section>
                    {(brochure_sendings.length > 0 || brochure_send !== null) && (
                        <section className="rounded-xl border p-4 lg:col-span-2" aria-labelledby="opportunity-brochures">
                            <h2 id="opportunity-brochures" className="mb-3 text-sm font-semibold">
                                Plaquettes envoyées
                            </h2>
                            <BrochureSendingList rows={brochure_sendings} />
                        </section>
                    )}
                    <section className="rounded-xl border p-4 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold">Actions</h2>
                        <ActivityList activities={activities} />
                    </section>
                    <section className="rounded-xl border p-4 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold">Historique des étapes</h2>
                        <ol className="grid gap-2 text-sm">
                            {history.map((h) => (
                                <li key={h.id} className="flex flex-wrap gap-x-2">
                                    <span className="text-muted-foreground">{formatDateTime(h.at)}</span>
                                    <span>{h.from ? `${h.from} → ${h.to}` : `Créée à l'étape ${h.to}`}</span>
                                    {h.by && <span className="text-muted-foreground">par {h.by}</span>}
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>
            </div>

            {can.update && (
                <StageDialog
                    opportunityId={o.id}
                    currentStageId={o.stage.id}
                    stages={stages}
                    lossReasons={lossReasons}
                    open={moving}
                    onClose={() => setMoving(false)}
                />
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Supprimer cette opportunité ?</DialogTitle>
                        <DialogDescription>« {o.title} » disparaît de la liste et du pipeline.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Annuler
                        </Button>
                        <Button variant="destructive" onClick={() => router.delete(route('crm.opportunities.destroy', o.id))}>
                            Supprimer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
