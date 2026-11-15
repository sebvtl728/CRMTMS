import { SimilarFiches, type DuplicateCandidate } from '@/components/crm/duplicate-warning';
import { InterestTrainings, type InterestTraining, type InterestTrainingOption } from '@/components/crm/interest-trainings';
import { FicheOpportunities, type OpportunityRow } from '@/components/crm/opportunity-list';
import { PersonGdpr, type GdprData } from '@/components/crm/person-gdpr';
import { PersonTypeBadges } from '@/components/crm/person-type-badges';
import { QuickActionFicheButton } from '@/components/crm/quick-action';
import { FicheQuotes, type QuoteRow } from '@/components/crm/quote-list';
import { Timeline } from '@/components/crm/timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatPhone, statusVariant, type StatusValue } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Ref {
    id: string;
    label: string;
}

interface AffiliationRow {
    id: string;
    organisation: { id: string; name: string };
    job_title: string | null;
    decision_role: Ref | null;
    work_email: string | null;
    work_phone: string | null;
    is_referent: boolean;
    started_on: string | null;
    ended_on: string | null;
}

interface PersonData {
    id: string;
    civility: string | null;
    first_name: string | null;
    last_name: string;
    name: string;
    email: string | null;
    email_secondary: string | null;
    phone: string | null;
    mobile: string | null;
    address_line1: string | null;
    address_line2: string | null;
    postal_code: string | null;
    city: string | null;
    country: string;
    birth_date: string | null;
    is_individual: boolean;
    project: string | null;
    status: StatusValue;
    professional_situation: Ref | null;
    funding_mode: Ref | null;
    origin: Ref | null;
    responsible: { id: string; name: string } | null;
    das: Ref[];
    affiliations: AffiliationRow[];
    last_contact_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    deleted: boolean;
    legal_basis: { value: string; label: string };
    prospecting_opt_out_at: string | null;
    anonymised: boolean;
    anonymised_at: string | null;
}

interface ShowProps {
    similar: DuplicateCandidate[];
    person: PersonData;
    gdpr: GdprData;
    opportunities: OpportunityRow[];
    quotes: QuoteRow[];
    interestTrainings: InterestTraining[];
    trainingOptions: InterestTrainingOption[];
    ficheDasIds: string[];
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
        merge: boolean;
        createOpportunity: boolean;
        consent: boolean;
        gdpr: boolean;
        gdprExport: boolean;
        anonymise: boolean;
    };
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

const TABS = ['Informations', 'Opportunités', 'Devis', 'Historique'] as const;

const mail = (email: string | null) =>
    email && (
        <a href={`mailto:${email}`} className="underline">
            {email}
        </a>
    );

const tel = (phone: string | null) =>
    phone && (
        <a href={`tel:${phone.replace(/[^+\d]/g, '')}`} className="underline">
            {formatPhone(phone)}
        </a>
    );

export default function PersonShow({
    person: p,
    similar,
    gdpr,
    opportunities,
    quotes,
    interestTrainings,
    trainingOptions,
    ficheDasIds,
    can,
    status,
}: ShowProps) {
    const [confirming, setConfirming] = useState(false);
    const [tab, setTab] = useState<(typeof TABS)[number]>('Informations');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Personnes', href: '/crm/people' },
        { title: p.name, href: route('crm.people.show', p.id) },
    ];

    const address = [
        p.address_line1,
        p.address_line2,
        [p.postal_code, p.city].filter(Boolean).join(' '),
        p.country !== 'FR' ? p.country : null,
    ].filter(Boolean);
    const active = p.affiliations.filter((a) => !a.ended_on);
    const former = p.affiliations.filter((a) => a.ended_on);

    const affiliation = (a: AffiliationRow) => (
        <li key={a.id} className="rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={route('crm.organisations.show', a.organisation.id)} className="font-medium hover:underline">
                    {a.organisation.name}
                </Link>
                {a.is_referent && <Badge>Référent</Badge>}
                {a.decision_role && <Badge variant="outline">{a.decision_role.label}</Badge>}
            </div>
            <p className="text-muted-foreground text-sm">
                {[a.job_title, a.started_on && `depuis le ${formatDate(a.started_on)}`, a.ended_on && `jusqu'au ${formatDate(a.ended_on)}`]
                    .filter(Boolean)
                    .join(' · ') || '—'}
            </p>
            {(a.work_email || a.work_phone) && (
                <p className="mt-1 text-sm break-all">
                    {mail(a.work_email)} {a.work_email && a.work_phone && ' · '} {tel(a.work_phone)}
                </p>
            )}
        </li>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={p.name} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {p.civility ? `${p.civility} ` : ''}
                            {p.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={statusVariant(p.status.value)}>{p.status.label}</Badge>
                            <PersonTypeBadges individual={p.is_individual} contact={active.length > 0} />
                            {p.deleted && <Badge variant="destructive">Supprimée</Badge>}
                            {p.anonymised && <Badge variant="destructive">Anonymisée</Badge>}
                            {p.prospecting_opt_out_at && <Badge variant="outline">Opposé à la prospection</Badge>}
                            {p.das.map((d) => (
                                <Badge key={d.id} variant="outline">
                                    {d.label}
                                </Badge>
                            ))}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <QuickActionFicheButton context={{ person_id: p.id }} available={!p.deleted && !p.anonymised} />
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('crm.people.edit', p.id)}>
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
                            <Button onClick={() => router.post(route('crm.people.restore', p.id))}>
                                <RotateCcw className="h-4 w-4" />
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {can.merge && <SimilarFiches type="person" currentId={p.id} similar={similar} />}

                <div role="tablist" aria-label="Sections de la fiche" className="flex gap-1 overflow-x-auto border-b">
                    {TABS.map((t) => (
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

                {tab === 'Opportunités' && (
                    <FicheOpportunities
                        rows={opportunities}
                        createHref={can.createOpportunity ? `${route('crm.opportunities.create')}?person=${p.id}` : null}
                    />
                )}
                {tab === 'Devis' && <FicheQuotes rows={quotes} />}
                {tab === 'Historique' && <Timeline kind="person" subjectId={p.id} canEdit={can.update} />}

                <div
                    role="tabpanel"
                    id="panel-Informations"
                    aria-labelledby="tab-Informations"
                    hidden={tab !== 'Informations'}
                    className="grid gap-4 lg:grid-cols-2"
                >
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Contact</h2>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Item label="E-mail">{mail(p.email)}</Item>
                            <Item label="E-mail secondaire">{mail(p.email_secondary)}</Item>
                            <Item label="Téléphone">{tel(p.phone)}</Item>
                            <Item label="Mobile">{tel(p.mobile)}</Item>
                            <Item label="Adresse">{address.length ? address.map((l, i) => <div key={i}>{l}</div>) : null}</Item>
                            <Item label="Date de naissance">{p.birth_date ? formatDate(p.birth_date) : null}</Item>
                        </dl>
                    </section>

                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Profil</h2>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Item label="Situation professionnelle">{p.professional_situation?.label}</Item>
                            <Item label="Mode de financement">{p.funding_mode?.label}</Item>
                            <Item label="Origine">{p.origin?.label}</Item>
                            <Item label="Responsable">{p.responsible?.name}</Item>
                        </dl>
                        {p.project && <p className="mt-3 text-sm whitespace-pre-line">{p.project}</p>}
                    </section>

                    <section className="rounded-xl border p-4 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold">Entreprises</h2>
                        {active.length === 0 && former.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aucun rattachement à une entreprise.</p>
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                <ul className="grid gap-2">{active.map(affiliation)}</ul>
                                {former.length > 0 && (
                                    <div>
                                        <h3 className="text-muted-foreground mb-2 text-xs font-medium">Anciens rattachements</h3>
                                        <ul className="grid gap-2">{former.map(affiliation)}</ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </section>

                    <InterestTrainings
                        trainings={interestTrainings}
                        options={trainingOptions}
                        ficheDasIds={ficheDasIds}
                        updateUrl={route('crm.people.trainings.update', p.id)}
                        canUpdate={can.update}
                    />

                    <PersonGdpr
                        personId={p.id}
                        name={p.name}
                        legalBasis={p.legal_basis}
                        optOutAt={p.prospecting_opt_out_at}
                        anonymisedAt={p.anonymised_at}
                        gdpr={gdpr}
                        can={can}
                    />

                    <section className="rounded-xl border p-4 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold">Suivi</h2>
                        <dl className="grid gap-3 sm:grid-cols-3">
                            <Item label="Dernier contact">{p.last_contact_at ? formatDate(p.last_contact_at) : null}</Item>
                            <Item label="Créée le">{formatDateTime(p.created_at)}</Item>
                            <Item label="Modifiée le">{formatDateTime(p.updated_at)}</Item>
                        </dl>
                    </section>
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Supprimer cette personne ?</DialogTitle>
                        <DialogDescription>
                            « {p.name} » disparaît des listes et de la fiche de ses entreprises, mais reste récupérable depuis la corbeille.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Annuler
                        </Button>
                        <Button variant="destructive" onClick={() => router.delete(route('crm.people.destroy', p.id))}>
                            Supprimer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
