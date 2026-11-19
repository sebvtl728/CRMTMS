import { type Option } from '@/lib/crm';

/** Options of the candidature forms (CandidatureController::options). */
export interface AdmissionOptions {
    statuses: Option[];
    contract_types: Option[];
    pathways: Option[];
    refusal_reasons: Option[];
    das: Option[];
    origins: Option[];
    education_levels: Option[];
    situations: Option[];
    funding_types: Option[];
    users: Option[];
    training_options: (Option & { rncp_code: string | null })[];
    sessions: { id: string; label: string; starts_on: string }[];
}

export interface CandidatureFileRow {
    id: string;
    kind: string;
    name: string;
    size: number;
    created_at: string | null;
    href: string;
}

export interface Alternance {
    contract_type: string;
    contract_type_label: string;
    employer: { id: string; label: string } | null;
    tutor: { id: string; label: string } | null;
    starts_on: string;
    ends_on: string;
    opco: { id: string; label: string } | null;
}

/** A candidature as its page shows it (CandidaturePresenter::show). */
export interface Candidature {
    id: string;
    number: string;
    status: string;
    status_label: string;
    pathway: 'alternance' | 'trainee' | null;
    pathway_label: string | null;
    person: {
        id: string;
        label: string;
        anonymised: boolean;
        trashed: boolean;
        email: string | null;
        phone: string | null;
        mobile: string | null;
        birth_date: string | null;
        education_level_id: string | null;
        professional_situation_id: string | null;
        crm_href: string | null;
    };
    training: { id: string; title: string; rncp_code: string | null } | null;
    rncp_code: string | null;
    target_session: { id: string; label: string; starts_on: string } | null;
    das: { id: string; label: string | null };
    responsible_user_id: string | null;
    origin_id: string | null;
    france_travail_id: string | null;
    last_diploma: string | null;
    legal_representative: Partial<Record<'last_name' | 'first_name' | 'relationship' | 'phone' | 'email', string>> | null;
    positioning_sent_on: string | null;
    positioning_received_on: string | null;
    positioning_result: string | null;
    interview_at: string | null;
    interviewer_user_id: string | null;
    decision_reason_id: string | null;
    decision_reason_label: string | null;
    dossier_id: string | null;
    dossier: { id: string; number: string; href: string; trashed: boolean } | null;
    alternance: Alternance | null;
    files: CandidatureFileRow[];
    is_minor: boolean;
    deleted_at: string | null;
    /** Only with the right « Voir les données sensibles des candidats ». */
    rqth?: boolean;
    adaptation_needs?: string | null;
    interview_report?: string | null;
}

export interface CustomField {
    id: string;
    label: string;
    type: 'text' | 'textarea' | 'number' | 'date' | 'boolean' | 'choice' | 'file';
    options: string[] | null;
    required_for_admission: boolean;
    value: string | boolean | null;
}

/** A row of the candidature list (CandidatureRows::row). */
export interface CandidatureRow {
    id: string;
    number: string;
    person: string;
    status: string;
    status_label: string;
    training: string | null;
    rncp_code: string | null;
    das: string | null;
    responsible: string | null;
    pathway: 'alternance' | 'trainee' | null;
    pathway_label: string | null;
    has_contract: boolean;
    contract_type: string | null;
    employer: string | null;
    contract_starts_on: string | null;
    contract_ends_on: string | null;
    dossier: string | null;
    kind: 'Stagiaire' | 'Alternant' | null;
}

/** Place of a stage on the path (CandidatureStatus::rank), -1 for the exits. */
export const STAGE_RANK: Record<string, number> = {
    identified: 0,
    positioning_sent: 1,
    positioning_received: 2,
    interview: 3,
    admitted: 4,
    rejected: -1,
    withdrawn: -1,
};

export function stageVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'admitted') return 'default';
    if (status === 'rejected' || status === 'withdrawn') return 'outline';

    return 'secondary';
}
