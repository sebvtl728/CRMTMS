import { CandidatureSteps } from '@/components/admissions/candidature-steps';
import { DossierDialog } from '@/components/admissions/dossier-dialog';
import { CandidatureFiles, OptionSelect, TextField, textareaClass } from '@/components/admissions/fields';
import { PositioningPanel, type PositioningProps } from '@/components/admissions/positioning-panel';
import { TrainingFollowUp, type TrainingFollowUpProps } from '@/components/admissions/training-follow-up';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import { HistoryList } from '@/components/sessions/history-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatPhone } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { type AdmissionOptions, type Candidature, type CustomField, STAGE_RANK, stageVariant } from '@/types/admissions';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ExternalLink } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface ShowProps {
    candidature: Candidature;
    custom_fields: CustomField[];
    history: { at: string; title: string; author: string | null }[];
    options: AdmissionOptions;
    can: { update: boolean; delete: boolean; sensitive: boolean; create_dossier: boolean };
    positioning: PositioningProps;
    /** Planning, attendance and signatures of the trainee (null: no dossier, or no right on the session). */
    follow_up: TrainingFollowUpProps | null;
    status?: string;
}

type Values = Record<string, unknown>;

function Card({ title, children, id, className }: { title: string; children: ReactNode; id?: string; className?: string }) {
    return (
        <section id={id} aria-label={title} className={`grid content-start gap-3 rounded-xl border p-4 ${className ?? ''}`}>
            <h2 className="text-sm font-semibold">{title}</h2>
            {children}
        </section>
    );
}

/** Saves the keys of one card (PUT: only the keys sent change). */
function useCard(candidatureId: string, initial: Values) {
    const [values, setValues] = useState<Values>(initial);
    const [processing, setProcessing] = useState(false);
    const set = (key: string, value: unknown) => setValues((v) => ({ ...v, [key]: value }));
    const save = (extra: Values = {}) => {
        setProcessing(true);
        router.put(`/admissions/candidatures/${candidatureId}`, { ...values, ...extra } as Record<string, never>, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    };

    return { values, set, save, processing, str: (key: string) => (values[key] as string | null) ?? '' };
}

function SaveButton({ onClick, processing }: { onClick: () => void; processing: boolean }) {
    return (
        <div>
            <Button type="button" size="sm" onClick={onClick} disabled={processing}>
                Enregistrer
            </Button>
        </div>
    );
}

/** Proposes the stage that a date just entered calls for (never automatic). */
function Suggest({ c, to, label, canUpdate }: { c: Candidature; to: string; label: string; canUpdate: boolean }) {
    if (!canUpdate || STAGE_RANK[c.status] < 0 || STAGE_RANK[c.status] >= STAGE_RANK[to]) return null;

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className="justify-self-start"
            onClick={() => router.post(`/admissions/candidatures/${c.id}/status`, { status: to }, { preserveScroll: true })}
        >
            Passer à l'étape « {label} »
        </Button>
    );
}

/** « 1 85 05 78 006 084 91 »: the groups of a social security number, as typed. */
function formatNir(value: string): string {
    const raw = value.replace(/\s/g, '');
    return [raw.slice(0, 1), raw.slice(1, 3), raw.slice(3, 5), raw.slice(5, 7), raw.slice(7, 10), raw.slice(10, 13), raw.slice(13, 15)]
        .filter((g) => g !== '')
        .join(' ');
}

export default function CandidatureShow({
    candidature: c,
    custom_fields,
    history,
    options,
    can,
    positioning: positioningTests,
    follow_up: followUp,
}: ShowProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [stage, setStage] = useState<string | null>(null);
    const [reason, setReason] = useState<string | null>(null);
    const [dossierOpen, setDossierOpen] = useState(false);
    const disabled = !can.update;
    // Each card opens once the one before is saved (rejected or withdrawn: every card).
    const rank = STAGE_RANK[c.status];
    const exited = rank < 0;
    const showTest = exited || rank >= 1 || c.training !== null || c.positioning_sent_on !== null;
    const showInterview = exited || rank >= 2 || c.positioning_received_on !== null || c.interview_at !== null;
    const showAfterInterview = exited || rank >= 3 || c.interview_at !== null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Candidats', href: '/admissions/candidatures' },
        { title: c.number, href: '#' },
    ];

    const orientation = useCard(c.id, {
        training_id: c.training?.id ?? null,
        rncp_code: c.rncp_code,
        target_session_id: c.target_session?.id ?? null,
        das_id: c.das.id,
        responsible_user_id: c.responsible_user_id,
        origin_id: c.origin_id,
        pathway: c.pathway,
        france_travail_id: c.france_travail_id,
        last_diploma: c.last_diploma,
        education_level_id: c.person.education_level_id,
        professional_situation_id: c.person.professional_situation_id,
        legal_representative: c.legal_representative ?? {},
    });
    const positioning = useCard(c.id, {
        positioning_sent_on: c.positioning_sent_on,
        positioning_received_on: c.positioning_received_on,
        positioning_result: c.positioning_result,
    });
    const interview = useCard(c.id, {
        interview_at: c.interview_at,
        interviewer_user_id: c.interviewer_user_id,
        ...(can.sensitive ? { interview_report: c.interview_report ?? '', rqth: c.rqth ?? false, adaptation_needs: c.adaptation_needs ?? '' } : {}),
    });
    const custom = useCard(c.id, {
        custom_values: Object.fromEntries(custom_fields.filter((f) => f.type !== 'file' && !f.hidden).map((f) => [f.id, f.value ?? ''])),
    });

    const [contract, setContract] = useState({
        contract_type: c.alternance?.contract_type ?? 'apprenticeship',
        starts_on: c.alternance?.starts_on ?? '',
        ends_on: c.alternance?.ends_on ?? '',
    });
    const [employer, setEmployer] = useState<Picked | null>(c.alternance?.employer ?? null);
    const [tutor, setTutor] = useState<Picked | null>(c.alternance?.tutor ?? null);
    const [opco, setOpco] = useState<Picked | null>(c.alternance?.opco ?? null);

    const rep = (orientation.values.legal_representative ?? {}) as Record<string, string>;
    const setRep = (key: string, value: string) => orientation.set('legal_representative', { ...rep, [key]: value });
    const sessions = options.sessions.map((s) => ({ value: s.id, label: `${s.label} · ${formatDate(s.starts_on)}` }));
    const customValues = (custom.values.custom_values ?? {}) as Record<string, unknown>;
    const setCustom = (id: string, value: unknown) => custom.set('custom_values', { ...customValues, [id]: value });

    const moveStage = () => {
        if (!stage) return;
        router.post(
            `/admissions/candidatures/${c.id}/status`,
            { status: stage, decision_reason_id: reason },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setStage(null);
                    setReason(null);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${c.person.label} · ${c.number}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-2">
                        <Heading title={c.person.label} description={`Candidature ${c.number}${c.training ? ` · ${c.training.title}` : ''}`} />
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant={stageVariant(c.status)}>{c.status_label}</Badge>
                            {c.dossier && !c.dossier.trashed && <Badge variant="secondary">{c.alternance ? 'Alternant' : 'Stagiaire'}</Badge>}
                            {c.decision_reason_label && <span className="text-muted-foreground text-sm">Motif : {c.decision_reason_label}</span>}
                            {c.deleted_at && <Badge variant="outline">Corbeille</Badge>}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button type="button" variant="outline" onClick={() => setStage(c.status)}>
                                Changer l'étape
                            </Button>
                        )}
                        {can.create_dossier && (
                            <Button type="button" onClick={() => setDossierOpen(true)}>
                                Créer le dossier
                            </Button>
                        )}
                        {can.delete && !c.deleted_at && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    confirm('Mettre cette candidature à la corbeille ?') && router.delete(`/admissions/candidatures/${c.id}`)
                                }
                            >
                                Corbeille
                            </Button>
                        )}
                        {can.delete && c.deleted_at && (
                            <Button type="button" variant="outline" onClick={() => router.post(`/admissions/candidatures/${c.id}/restore`)}>
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {(errors.training_id || errors.custom_values || errors.decision_reason_id || errors.positioning_received_on) && stage === null && (
                    <div role="alert" className="text-destructive text-sm">
                        {errors.training_id ?? errors.custom_values ?? errors.decision_reason_id ?? errors.positioning_received_on}
                    </div>
                )}

                <CandidatureSteps c={c} onCreateDossier={can.create_dossier ? () => setDossierOpen(true) : undefined} />

                <div className="grid gap-4 lg:grid-cols-3 lg:items-start">
                    {/* The path, in its order. */}
                    <div className="grid gap-4 lg:col-span-2">
                        <Card title="Orientation" id="carte-orientation">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <OptionSelect
                                    id="das_id"
                                    label="DAS"
                                    value={orientation.str('das_id') || null}
                                    options={options.das}
                                    onChange={(v) => {
                                        orientation.set('das_id', v);
                                        // A training (and its session) of another DAS no longer fits.
                                        const training = options.training_options.find((t) => t.value === orientation.values.training_id);
                                        if (training && v !== null && training.das_id !== v) {
                                            orientation.set('training_id', null);
                                            orientation.set('rncp_code', null);
                                            orientation.set('target_session_id', null);
                                        }
                                    }}
                                    error={errors.das_id}
                                    disabled={disabled}
                                />
                                <OptionSelect
                                    id="training_id"
                                    label="Formation visée"
                                    value={orientation.str('training_id') || null}
                                    options={options.training_options.filter(
                                        (t) => !orientation.values.das_id || t.das_id === orientation.values.das_id,
                                    )}
                                    disabled={disabled}
                                    onChange={(v) => {
                                        orientation.set('training_id', v);
                                        orientation.set('rncp_code', options.training_options.find((t) => t.value === v)?.rncp_code ?? null);
                                        orientation.set('target_session_id', null);
                                    }}
                                    error={errors.training_id}
                                />
                                <TextField
                                    id="rncp_code"
                                    label="Code RNCP"
                                    value={orientation.str('rncp_code')}
                                    onChange={(v) => orientation.set('rncp_code', v)}
                                    error={errors.rncp_code}
                                    disabled={disabled}
                                    placeholder="RNCP36004"
                                />
                                <OptionSelect
                                    id="target_session_id"
                                    label="Session visée"
                                    value={orientation.str('target_session_id') || null}
                                    options={sessions}
                                    onChange={(v) => orientation.set('target_session_id', v)}
                                    error={errors.target_session_id}
                                    disabled={
                                        disabled ||
                                        orientation.values.training_id !== (c.training?.id ?? null) ||
                                        orientation.values.das_id !== c.das.id
                                    }
                                />
                                <div className="grid gap-1">
                                    <OptionSelect
                                        id="pathway"
                                        label="Parcours"
                                        value={orientation.str('pathway') || null}
                                        options={options.pathways}
                                        noneLabel="À définir"
                                        onChange={(v) => orientation.set('pathway', v)}
                                        error={errors.pathway}
                                        disabled={disabled || c.alternance !== null}
                                    />
                                    {c.alternance !== null && (
                                        <p className="text-muted-foreground text-xs">
                                            Un contrat d'alternance est saisi : le parcours reste « Alternance ».
                                        </p>
                                    )}
                                </div>
                                <OptionSelect
                                    id="responsible_user_id"
                                    label="Responsable"
                                    value={orientation.str('responsible_user_id') || null}
                                    options={options.users}
                                    onChange={(v) => orientation.set('responsible_user_id', v)}
                                    disabled={disabled}
                                />
                                <OptionSelect
                                    id="origin_id"
                                    label="Origine (prescripteur, canal)"
                                    value={orientation.str('origin_id') || null}
                                    options={options.origins}
                                    onChange={(v) => orientation.set('origin_id', v)}
                                    disabled={disabled}
                                />
                                <TextField
                                    id="france_travail_id"
                                    label="Identifiant France Travail"
                                    value={orientation.str('france_travail_id')}
                                    onChange={(v) => orientation.set('france_travail_id', v)}
                                    disabled={disabled}
                                />
                                <OptionSelect
                                    id="professional_situation_id"
                                    label="Situation avant la formation"
                                    value={orientation.str('professional_situation_id') || null}
                                    options={options.situations}
                                    onChange={(v) => orientation.set('professional_situation_id', v)}
                                    disabled={disabled}
                                />
                                <OptionSelect
                                    id="education_level_id"
                                    label="Niveau de diplôme"
                                    value={orientation.str('education_level_id') || null}
                                    options={options.education_levels}
                                    onChange={(v) => orientation.set('education_level_id', v)}
                                    disabled={disabled}
                                />
                                <TextField
                                    id="last_diploma"
                                    label="Dernier diplôme ou classe"
                                    value={orientation.str('last_diploma')}
                                    onChange={(v) => orientation.set('last_diploma', v)}
                                    disabled={disabled}
                                />
                            </div>
                            {can.update && <SaveButton onClick={() => orientation.save()} processing={orientation.processing} />}
                            {!showTest && (
                                <p className="text-muted-foreground text-xs">Enregistrez la formation visée pour ouvrir le test de positionnement.</p>
                            )}
                        </Card>

                        {showTest && (
                            <Card title="Test de positionnement" id="carte-test">
                                <PositioningPanel candidatureId={c.id} positioning={positioningTests} />
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <TextField
                                        id="positioning_sent_on"
                                        type="date"
                                        label="Envoyé le"
                                        value={positioning.str('positioning_sent_on')}
                                        onChange={(v) => positioning.set('positioning_sent_on', v)}
                                        error={errors.positioning_sent_on}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="positioning_received_on"
                                        type="date"
                                        label="Reçu le"
                                        value={positioning.str('positioning_received_on')}
                                        onChange={(v) => positioning.set('positioning_received_on', v)}
                                        error={errors.positioning_received_on}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="positioning_result"
                                        label="Score ou niveau"
                                        value={positioning.str('positioning_result')}
                                        onChange={(v) => positioning.set('positioning_result', v)}
                                        disabled={disabled}
                                    />
                                </div>
                                {can.update && <SaveButton onClick={() => positioning.save()} processing={positioning.processing} />}
                                {c.positioning_received_on ? (
                                    <Suggest c={c} to="positioning_received" label="Test reçu" canUpdate={can.update} />
                                ) : (
                                    c.positioning_sent_on && <Suggest c={c} to="positioning_sent" label="Test envoyé" canUpdate={can.update} />
                                )}
                                {/* The copies the candidate deposited through his link (no manual upload here). */}
                                <CandidatureFiles
                                    candidatureId={c.id}
                                    kind="positioning"
                                    files={c.files}
                                    canUpdate={can.update}
                                    allowUpload={false}
                                />
                                {!showInterview && (
                                    <p className="text-muted-foreground text-xs">
                                        Le test est obligatoire : l’entretien s’ouvre une fois la date de réception enregistrée (ou la copie du
                                        candidat reçue).
                                    </p>
                                )}
                            </Card>
                        )}

                        {showInterview && (
                            <Card title="Entretien" id="carte-entretien">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <TextField
                                        id="interview_at"
                                        type="datetime-local"
                                        label="Date et heure"
                                        value={interview.str('interview_at')}
                                        onChange={(v) => interview.set('interview_at', v)}
                                        error={errors.interview_at}
                                        disabled={disabled}
                                    />
                                    <OptionSelect
                                        id="interviewer_user_id"
                                        label="Mené par"
                                        value={interview.str('interviewer_user_id') || null}
                                        options={options.users}
                                        onChange={(v) => interview.set('interviewer_user_id', v)}
                                        disabled={disabled}
                                    />
                                </div>
                                {can.sensitive ? (
                                    <div className="grid gap-3">
                                        <div className="grid gap-1">
                                            <Label htmlFor="interview_report">Compte rendu</Label>
                                            <textarea
                                                id="interview_report"
                                                rows={4}
                                                className={textareaClass}
                                                value={interview.str('interview_report')}
                                                onChange={(e) => interview.set('interview_report', e.target.value)}
                                                disabled={disabled}
                                            />
                                        </div>
                                        <Label className="flex items-center gap-2 font-normal">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(interview.values.rqth)}
                                                onChange={(e) => interview.set('rqth', e.target.checked)}
                                                disabled={disabled}
                                            />
                                            Reconnaissance de travailleur handicapé (RQTH)
                                        </Label>
                                        <div className="grid gap-1">
                                            <Label htmlFor="adaptation_needs">Besoins d'adaptation</Label>
                                            <textarea
                                                id="adaptation_needs"
                                                rows={2}
                                                className={textareaClass}
                                                value={interview.str('adaptation_needs')}
                                                onChange={(e) => interview.set('adaptation_needs', e.target.value)}
                                                disabled={disabled}
                                            />
                                        </div>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        Le compte rendu, la RQTH et les besoins d'adaptation sont réservés au droit « Voir les données sensibles des
                                        candidats ».
                                    </p>
                                )}
                                {can.update && <SaveButton onClick={() => interview.save()} processing={interview.processing} />}
                                {c.interview_at && <Suggest c={c} to="interview" label="Entretien" canUpdate={can.update} />}
                            </Card>
                        )}

                        {showAfterInterview && c.pathway !== 'trainee' && (
                            <Card title="Alternance" id="carte-alternance">
                                {c.pathway === null && !c.alternance ? (
                                    <p className="text-muted-foreground text-sm">
                                        Choisissez le parcours « Alternance » (carte Orientation) pour saisir le contrat.
                                    </p>
                                ) : STAGE_RANK[c.status] < 3 && !c.alternance ? (
                                    <p className="text-muted-foreground text-sm">Le contrat se saisit à partir de l'entretien.</p>
                                ) : (
                                    <div className="grid gap-3">
                                        <OptionSelect
                                            id="contract_type"
                                            label="Type de contrat"
                                            value={contract.contract_type}
                                            options={options.contract_types}
                                            onChange={(v) => setContract({ ...contract, contract_type: v ?? 'apprenticeship' })}
                                            error={errors.contract_type}
                                            disabled={disabled}
                                        />
                                        <div className="grid gap-1">
                                            <Label htmlFor="employer">Entreprise d'accueil</Label>
                                            <FichePicker
                                                id="employer"
                                                kind="organisation"
                                                value={employer}
                                                onPick={setEmployer}
                                                placeholder="Rechercher l'entreprise"
                                                searchUrl="/admissions/candidatures/lookup/organisations"
                                            />
                                            <InputError message={errors.employer_organisation_id} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="tutor">Maître d'apprentissage ou tuteur</Label>
                                            <FichePicker
                                                id="tutor"
                                                kind="person"
                                                organisationId={employer?.id}
                                                value={tutor}
                                                onPick={setTutor}
                                                placeholder="Rechercher une personne"
                                                searchUrl="/admissions/candidatures/lookup/people"
                                            />
                                            <InputError message={errors.tutor_person_id} />
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <TextField
                                                id="starts_on"
                                                type="date"
                                                label="Début du contrat"
                                                value={contract.starts_on}
                                                onChange={(v) => setContract({ ...contract, starts_on: v })}
                                                error={errors.starts_on}
                                                disabled={disabled}
                                            />
                                            <TextField
                                                id="ends_on"
                                                type="date"
                                                label="Fin du contrat"
                                                value={contract.ends_on}
                                                onChange={(v) => setContract({ ...contract, ends_on: v })}
                                                error={errors.ends_on}
                                                disabled={disabled}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="opco">OPCO</Label>
                                            <FichePicker
                                                id="opco"
                                                kind="organisation"
                                                value={opco}
                                                onPick={setOpco}
                                                placeholder="Rechercher l'OPCO"
                                                searchUrl="/admissions/candidatures/lookup/organisations"
                                            />
                                            <InputError message={errors.opco_organisation_id} />
                                        </div>
                                        {can.update && (
                                            <div className="flex gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.put(
                                                            `/admissions/candidatures/${c.id}/alternance`,
                                                            {
                                                                ...contract,
                                                                employer_organisation_id: employer?.id ?? null,
                                                                tutor_person_id: tutor?.id ?? null,
                                                                opco_organisation_id: opco?.id ?? null,
                                                            },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Enregistrer le contrat
                                                </Button>
                                                {c.alternance && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            confirm('Retirer le contrat ?') &&
                                                            router.delete(`/admissions/candidatures/${c.id}/alternance`, { preserveScroll: true })
                                                        }
                                                    >
                                                        Retirer
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                        {c.alternance && <BookletPanel candidatureId={c.id} modules={c.booklet.modules} canUpdate={can.update} />}
                                        {c.alternance && (
                                            <div className="grid gap-1">
                                                <Label>Contrat signé</Label>
                                                <CandidatureFiles
                                                    candidatureId={c.id}
                                                    kind="contract"
                                                    files={c.files}
                                                    canUpdate={can.update}
                                                    label="Joindre le contrat signé"
                                                />
                                            </div>
                                        )}
                                    </div>
                                )}
                            </Card>
                        )}

                        {/* After the contract and the apprenticeship booklet: the next step of the path. */}
                        <Card title="Dossier de formation" id="carte-dossier">
                            {c.dossier ? (
                                <p className="text-sm">
                                    <Link href={c.dossier.href} className="font-medium hover:underline">
                                        {c.dossier.number}
                                    </Link>
                                    {c.dossier.trashed && <span className="text-muted-foreground"> (à la corbeille)</span>}
                                </p>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    {c.status !== 'admitted'
                                        ? 'Le dossier se crée une fois le candidat admis.'
                                        : c.pathway === 'alternance' && c.alternance === null
                                          ? 'Candidat admis en alternance sans contrat : saisissez le contrat avant de créer son dossier.'
                                          : `Candidat admis : créez son dossier pour qu'il devienne ${c.alternance ? 'alternant' : 'stagiaire'}.`}
                                </p>
                            )}
                            {!c.dossier && c.status === 'interview' && <Suggest c={c} to="admitted" label="Admis" canUpdate={can.update} />}
                        </Card>

                        {followUp && (
                            <div id="carte-suivi">
                                <TrainingFollowUp followUp={followUp} />
                            </div>
                        )}
                    </div>

                    {/* The candidate, beside the path (first on a phone). */}
                    <aside className="order-first grid gap-4 lg:order-none">
                        <Card title="Identité">
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">Naissance</dt>
                                <dd>{formatDate(c.person.birth_date) || '-'}</dd>
                                <dt className="text-muted-foreground">E-mail</dt>
                                <dd>{c.person.email ?? '-'}</dd>
                                <dt className="text-muted-foreground">Téléphone</dt>
                                <dd>{formatPhone(c.person.mobile ?? c.person.phone) || '-'}</dd>
                            </dl>
                            {c.is_minor && (
                                <p role="alert" className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                                    <AlertTriangle className="h-4 w-4" aria-hidden />
                                    Candidat mineur : renseignez le représentant légal.
                                </p>
                            )}
                            {c.person.crm_href && (
                                <Link href={c.person.crm_href} className="inline-flex items-center gap-1 text-sm hover:underline">
                                    <ExternalLink className="h-4 w-4" aria-hidden />
                                    Modifier dans le CRM
                                </Link>
                            )}
                        </Card>
                        {(c.is_minor || c.legal_representative) && (
                            <Card title="Représentant légal">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <TextField
                                        id="rep-last"
                                        label="Nom"
                                        value={rep.last_name ?? ''}
                                        onChange={(v) => setRep('last_name', v)}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="rep-first"
                                        label="Prénom"
                                        value={rep.first_name ?? ''}
                                        onChange={(v) => setRep('first_name', v)}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="rep-rel"
                                        label="Lien (père, mère, tuteur…)"
                                        value={rep.relationship ?? ''}
                                        onChange={(v) => setRep('relationship', v)}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="rep-phone"
                                        label="Téléphone"
                                        value={rep.phone ?? ''}
                                        onChange={(v) => setRep('phone', v)}
                                        disabled={disabled}
                                    />
                                    <TextField
                                        id="rep-email"
                                        label="E-mail"
                                        value={rep.email ?? ''}
                                        onChange={(v) => setRep('email', v)}
                                        error={errors['legal_representative.email']}
                                        disabled={disabled}
                                    />
                                </div>
                                {can.update && <SaveButton onClick={() => orientation.save()} processing={orientation.processing} />}
                            </Card>
                        )}
                        {/* Not a step: the organisme's own fields are open from the start. */}
                        {custom_fields.length > 0 && (
                            <Card title="Informations complémentaires">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {custom_fields.map((f) => {
                                        const label = `${f.label}${f.required_for_admission ? " (requis pour l'admission)" : ''}`;
                                        const value = (customValues[f.id] as string | boolean | null | undefined) ?? '';
                                        const error = errors[`custom_values.${f.id}`];
                                        if (f.hidden) {
                                            return (
                                                <div key={f.id} className="grid gap-1">
                                                    <Label>{label}</Label>
                                                    <p className="text-muted-foreground text-sm">
                                                        Donnée sensible, réservée aux personnes autorisées.
                                                    </p>
                                                </div>
                                            );
                                        }
                                        if (f.type === 'nir') {
                                            return (
                                                <TextField
                                                    key={f.id}
                                                    id={`cf-${f.id}`}
                                                    label={label}
                                                    value={formatNir(String(value))}
                                                    onChange={(v) => setCustom(f.id, v.replace(/\s/g, '').toUpperCase())}
                                                    error={error}
                                                    disabled={disabled}
                                                />
                                            );
                                        }
                                        if (f.type === 'file') {
                                            return (
                                                <div key={f.id} className="grid gap-1 sm:col-span-2">
                                                    <Label>{label}</Label>
                                                    <CandidatureFiles
                                                        candidatureId={c.id}
                                                        kind={`custom:${f.id}`}
                                                        files={c.files}
                                                        canUpdate={can.update}
                                                    />
                                                </div>
                                            );
                                        }
                                        if (f.type === 'choice') {
                                            return (
                                                <OptionSelect
                                                    key={f.id}
                                                    id={`cf-${f.id}`}
                                                    label={label}
                                                    value={(value as string) || null}
                                                    options={(f.options ?? []).map((o) => ({ value: o, label: o }))}
                                                    onChange={(v) => setCustom(f.id, v ?? '')}
                                                    error={error}
                                                    disabled={disabled}
                                                />
                                            );
                                        }
                                        if (f.type === 'boolean') {
                                            return (
                                                <Label key={f.id} className="flex items-center gap-2 font-normal">
                                                    <input
                                                        type="checkbox"
                                                        checked={value === true}
                                                        onChange={(e) => setCustom(f.id, e.target.checked)}
                                                        disabled={disabled}
                                                    />
                                                    {label}
                                                </Label>
                                            );
                                        }
                                        if (f.type === 'textarea') {
                                            return (
                                                <div key={f.id} className="grid gap-1 sm:col-span-2">
                                                    <Label htmlFor={`cf-${f.id}`}>{label}</Label>
                                                    <textarea
                                                        id={`cf-${f.id}`}
                                                        rows={3}
                                                        className={textareaClass}
                                                        value={String(value)}
                                                        onChange={(e) => setCustom(f.id, e.target.value)}
                                                        disabled={disabled}
                                                    />
                                                    <InputError message={error} />
                                                </div>
                                            );
                                        }

                                        return (
                                            <TextField
                                                key={f.id}
                                                id={`cf-${f.id}`}
                                                type={f.type === 'date' ? 'date' : 'text'}
                                                label={label}
                                                value={String(value)}
                                                onChange={(v) => setCustom(f.id, v)}
                                                error={error}
                                                disabled={disabled}
                                            />
                                        );
                                    })}
                                </div>
                                {can.update && <SaveButton onClick={() => custom.save()} processing={custom.processing} />}
                            </Card>
                        )}
                        <HistoryList
                            entries={history.map((h) => ({ type: 'candidature', at: h.at, title: h.title, body: null, author: h.author }))}
                        />
                    </aside>
                </div>
            </div>

            <Dialog open={stage !== null} onOpenChange={(open) => !open && setStage(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Changer l'étape</DialogTitle>
                        <DialogDescription>Le parcours : identifié, test envoyé, test reçu, entretien, admis.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        <OptionSelect
                            id="stage"
                            label="Étape"
                            value={stage}
                            options={options.statuses.filter((s) => s.value !== c.status)}
                            onChange={setStage}
                            error={errors.status}
                        />
                        {stage === 'rejected' && (
                            <OptionSelect
                                id="reason"
                                label="Motif"
                                value={reason}
                                options={options.refusal_reasons}
                                onChange={setReason}
                                error={errors.decision_reason_id}
                            />
                        )}
                        {(errors.training_id || errors.custom_values || errors.positioning_received_on) && (
                            <InputError message={errors.training_id ?? errors.custom_values ?? errors.positioning_received_on} />
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => setStage(null)}>
                            Annuler
                        </Button>
                        <Button type="button" onClick={moveStage} disabled={!stage || stage === c.status}>
                            Valider
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {can.create_dossier && (
                <DossierDialog
                    candidatureId={c.id}
                    personLabel={c.person.label}
                    employerLabel={c.alternance?.employer?.label ?? null}
                    fundingTypes={options.funding_types}
                    open={dossierOpen}
                    onOpenChange={setDossierOpen}
                />
            )}
        </AppLayout>
    );
}

/** Apprenticeship booklet: the PDF already filled, and the modules the apprentice is exempted from. */
function BookletPanel({
    candidatureId,
    modules,
    canUpdate,
}: {
    candidatureId: string;
    modules: Candidature['booklet']['modules'];
    canUpdate: boolean;
}) {
    const [dispensed, setDispensed] = useState<string[]>(modules.filter((m) => m.dispensed).map((m) => m.id));
    const changed = dispensed.length !== modules.filter((m) => m.dispensed).length || modules.some((m) => m.dispensed !== dispensed.includes(m.id));

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Label>Livret d'apprentissage</Label>
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <a href={`/admissions/candidatures/${candidatureId}/livret.pdf`} target="_blank" rel="noopener">
                            Livret d'apprentissage (PDF)
                        </a>
                    </Button>
                    <Button asChild size="sm" variant="outline">
                        <a href={`/admissions/candidatures/${candidatureId}/cerfa.pdf`} target="_blank" rel="noopener">
                            Fiche Cerfa (PDF)
                        </a>
                    </Button>
                </div>
            </div>
            <p className="text-muted-foreground text-xs">
                Déjà rempli : partenaires, équipe, parcours, planning, pages de suivi datées mois par mois et grilles de compétences de la formation.
            </p>
            {modules.length > 0 && (
                <div className="grid gap-1">
                    <span className="text-xs font-medium">Modules dispensés (parcours individualisé)</span>
                    {modules.map((m) => (
                        <label key={m.id} className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                disabled={!canUpdate}
                                checked={dispensed.includes(m.id)}
                                onChange={(e) => setDispensed((d) => (e.target.checked ? [...d, m.id] : d.filter((id) => id !== m.id)))}
                            />
                            {m.title}
                            {m.duration_hours !== null && (
                                <span className="text-muted-foreground text-xs">({m.duration_hours.replace('.00', '').replace('.', ',')} h)</span>
                            )}
                        </label>
                    ))}
                    {canUpdate && changed && (
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() =>
                                    router.put(
                                        `/admissions/candidatures/${candidatureId}/dispenses`,
                                        { module_ids: dispensed },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Enregistrer les dispenses
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
