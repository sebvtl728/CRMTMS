import { BrochureSendButton, type BrochureSendProps } from '@/components/crm/brochure-send';
import { SimilarFiches, type DuplicateCandidate } from '@/components/crm/duplicate-warning';
import { FicheBlockPanel, type FicheBlock } from '@/components/crm/fiche-blocks';
import { FutureNeeds, type FutureNeedsData } from '@/components/crm/future-needs';
import { InterestTrainings, type InterestTraining, type InterestTrainingOption } from '@/components/crm/interest-trainings';
import { FicheOpportunities, type OpportunityRow } from '@/components/crm/opportunity-list';
import { OrganisationContacts, type AffiliationRow } from '@/components/crm/organisation-contacts';
import { QuickActionFicheButton } from '@/components/crm/quick-action';
import { FicheQuotes, type QuoteRow } from '@/components/crm/quote-list';
import { Timeline } from '@/components/crm/timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatPhone, formatSiret, statusVariant, type Option, type StatusValue } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Ref {
    id: string;
    label: string;
}

interface OrganisationData {
    future_needs: FutureNeedsData;
    id: string;
    name: string;
    siret: string | null;
    naf_code: string | null;
    idcc: string | null;
    status: StatusValue;
    legal_form: Ref | null;
    sector: Ref | null;
    establishment_type: Ref | null;
    company_size: Ref | null;
    origin: Ref | null;
    responsible: { id: string; name: string } | null;
    das: Ref[];
    types: Ref[];
    email: string | null;
    phone: string | null;
    website: string | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country: string;
    notes_summary: string | null;
    last_contact_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    deleted: boolean;
}

interface ShowProps {
    similar: DuplicateCandidate[];
    organisation: OrganisationData;
    affiliations: AffiliationRow[];
    formerAffiliations: AffiliationRow[];
    decisionRoles: Option[];
    opportunities: OpportunityRow[];
    quotes: QuoteRow[];
    /** Tabs added by other modules (« Formations suivies », « Dossiers »). */
    blocks: FicheBlock[];
    brochure_send: BrochureSendProps | null;
    interestTrainings: InterestTraining[];
    trainingOptions: InterestTrainingOption[];
    ficheDasIds: string[];
    can: {
        affiliate: boolean;
        createPerson: boolean;
        createOpportunity: boolean;
        update: boolean;
        delete: boolean;
        restore: boolean;
        merge: boolean;
    };
    status?: string;
}

const TABS = ['Informations', 'Interlocuteurs', 'Opportunités', 'Devis', 'Historique'] as const;

function Item({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children || '-'}</dd>
        </div>
    );
}

function safeLink(url: string | null): React.ReactNode {
    if (!url) return null;

    return /^https?:\/\//i.test(url) ? (
        <a href={url} target="_blank" rel="noopener noreferrer" className="underline">
            {url}
        </a>
    ) : (
        url
    );
}

export default function OrganisationShow({
    organisation: o,
    affiliations,
    formerAffiliations,
    decisionRoles,
    opportunities,
    quotes,
    blocks,
    brochure_send,
    interestTrainings,
    trainingOptions,
    ficheDasIds,
    similar,
    can,
    status,
}: ShowProps) {
    const [tab, setTab] = useState<string>('Informations');
    const [confirming, setConfirming] = useState(false);
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Entreprises', href: '/crm/organisations' },
        { title: o.name, href: route('crm.organisations.show', o.id) },
    ];

    const address = [
        o.address_line1,
        o.address_line2,
        [o.postal_code, o.city].filter(Boolean).join(' '),
        o.country !== 'FR' ? o.country : null,
    ].filter(Boolean);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={o.name} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">{o.name}</h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={statusVariant(o.status.value)}>{o.status.label}</Badge>
                            {o.deleted && <Badge variant="destructive">Supprimée</Badge>}
                            {o.types.map((t) => (
                                <Badge key={t.id} variant="secondary">
                                    {t.label}
                                </Badge>
                            ))}
                            {o.das.map((d) => (
                                <Badge key={d.id} variant="outline">
                                    {d.label}
                                </Badge>
                            ))}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <QuickActionFicheButton context={{ organisation_id: o.id }} available={!o.deleted} />
                        <BrochureSendButton send={brochure_send} routeName="crm.organisations.brochures.send" subjectId={o.id} />
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('crm.organisations.edit', o.id)}>
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
                        {can.restore && (
                            <Button onClick={() => router.post(route('crm.organisations.restore', o.id))}>
                                <RotateCcw className="h-4 w-4" />
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {can.merge && <SimilarFiches type="organisation" currentId={o.id} similar={similar} />}
                {errors.restore && (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                    >
                        {errors.restore}
                    </div>
                )}
                {errors.siret && (
                    <div
                        role="alert"
                        className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                    >
                        {errors.siret}{' '}
                        {errors.siret_existing_id && (
                            <Link href={route('crm.organisations.show', errors.siret_existing_id)} className="underline">
                                Ouvrir l'entreprise existante
                            </Link>
                        )}
                    </div>
                )}

                <div role="tablist" aria-label="Sections de la fiche" className="flex gap-1 overflow-x-auto border-b">
                    {[...TABS.slice(0, -1), ...blocks.map((b) => b.title), 'Historique'].map((t) => (
                        <button
                            key={t}
                            role="tab"
                            type="button"
                            id={`tab-${t}`}
                            aria-selected={tab === t}
                            aria-controls={`panel-${t}`}
                            onClick={() => setTab(t)}
                            className={`-mb-px border-b-2 px-4 py-2 text-sm whitespace-nowrap ${tab === t ? 'border-primary font-medium' : 'text-muted-foreground hover:text-foreground border-transparent'}`}
                        >
                            {t}
                        </button>
                    ))}
                </div>

                {tab === 'Informations' && (
                    <div role="tabpanel" id="panel-Informations" aria-labelledby="tab-Informations" className="grid gap-4 lg:grid-cols-2">
                        <section className="rounded-xl border p-4">
                            <h2 className="mb-3 text-sm font-semibold">Identité</h2>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Item label="SIRET">{o.siret ? formatSiret(o.siret) : null}</Item>
                                <Item label="Code NAF">{o.naf_code}</Item>
                                <Item label="IDCC">{o.idcc}</Item>
                                <Item label="Forme juridique">{o.legal_form?.label}</Item>
                                <Item label="Secteur d'activité">{o.sector?.label}</Item>
                                <Item label="Type d'établissement">{o.establishment_type?.label}</Item>
                                <Item label="Taille">{o.company_size?.label}</Item>
                                <Item label="Origine">{o.origin?.label}</Item>
                                <Item label="Responsable">{o.responsible?.name}</Item>
                            </dl>
                        </section>
                        <section className="rounded-xl border p-4">
                            <h2 className="mb-3 text-sm font-semibold">Contact</h2>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Item label="E-mail">
                                    {o.email && (
                                        <a href={`mailto:${o.email}`} className="underline">
                                            {o.email}
                                        </a>
                                    )}
                                </Item>
                                <Item label="Téléphone">{o.phone ? formatPhone(o.phone) : null}</Item>
                                <Item label="Site web">{safeLink(o.website)}</Item>
                                <Item label="Adresse">{address.length ? address.map((l, i) => <div key={i}>{l}</div>) : null}</Item>
                            </dl>
                        </section>
                        <InterestTrainings
                            trainings={interestTrainings}
                            options={trainingOptions}
                            ficheDasIds={ficheDasIds}
                            updateUrl={route('crm.organisations.trainings.update', o.id)}
                            canUpdate={can.update}
                        />
                        <FutureNeeds needs={o.future_needs} updateUrl={route('crm.organisations.future-needs', o.id)} canUpdate={can.update} />
                        <section className="rounded-xl border p-4 lg:col-span-2">
                            <h2 className="mb-3 text-sm font-semibold">Suivi</h2>
                            <dl className="grid gap-3 sm:grid-cols-3">
                                <Item label="Dernier contact">{o.last_contact_at ? formatDate(o.last_contact_at) : null}</Item>
                                <Item label="Créée le">{formatDateTime(o.created_at)}</Item>
                                <Item label="Modifiée le">{formatDateTime(o.updated_at)}</Item>
                            </dl>
                            {o.notes_summary && <p className="mt-3 text-sm whitespace-pre-line">{o.notes_summary}</p>}
                        </section>
                    </div>
                )}
                {tab === 'Interlocuteurs' && (
                    <OrganisationContacts
                        organisationId={o.id}
                        affiliations={affiliations}
                        former={formerAffiliations}
                        decisionRoles={decisionRoles}
                        can={{ affiliate: can.affiliate, createPerson: can.createPerson }}
                    />
                )}
                {tab === 'Opportunités' && (
                    <FicheOpportunities
                        rows={opportunities}
                        createHref={can.createOpportunity ? `${route('crm.opportunities.create')}?organisation=${o.id}` : null}
                    />
                )}
                {tab === 'Devis' && <FicheQuotes rows={quotes} />}
                {blocks.map((b) => tab === b.title && <FicheBlockPanel key={b.key} block={b} />)}
                {tab === 'Historique' && <Timeline kind="organisation" subjectId={o.id} canEdit={can.update} />}
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Supprimer cette entreprise ?</DialogTitle>
                        <DialogDescription>« {o.name} » disparaît des listes mais reste récupérable depuis la corbeille.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Annuler
                        </Button>
                        <Button variant="destructive" onClick={() => router.delete(route('crm.organisations.destroy', o.id))}>
                            Supprimer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
