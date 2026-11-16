<?php

namespace App\Modules\Core\References;

/**
 * The reference lists an organisme can configure. The lists themselves are a
 * fixed catalogue (modules depend on them); their VALUES are tenant data.
 */
enum ReferenceList: string
{
    case Das = 'das';
    case PipelineStage = 'pipeline_stage';
    case ActionType = 'action_type';
    case ActionResult = 'action_result';
    case ContactOrigin = 'contact_origin';
    case LossReason = 'loss_reason';
    case EstablishmentType = 'establishment_type';
    case Sector = 'sector';
    case CompanySize = 'company_size';
    case Priority = 'priority';
    case FundingMode = 'funding_mode';
    case ProfessionalSituation = 'professional_situation';
    case LegalFormOrg = 'legal_form_org';
    case DecisionRole = 'decision_role';
    case FundingType = 'funding_type';
    case AbandonReason = 'abandon_reason';
    case CancelReason = 'cancel_reason';
    case BpfCategory = 'bpf_category';
    case EducationLevel = 'education_level';
    case TrainingModality = 'training_modality';

    public function label(): string
    {
        return match ($this) {
            self::Das => 'Domaines d\'activité stratégiques (DAS)',
            self::PipelineStage => 'Étapes du pipeline',
            self::ActionType => 'Types d\'action',
            self::ActionResult => 'Résultats d\'action',
            self::ContactOrigin => 'Origines de contact',
            self::LossReason => 'Motifs de perte',
            self::EstablishmentType => 'Types d\'établissement',
            self::Sector => 'Secteurs d\'activité',
            self::CompanySize => 'Tailles d\'entreprise',
            self::Priority => 'Priorités',
            self::FundingMode => 'Modes de financement',
            self::ProfessionalSituation => 'Situations professionnelles',
            self::LegalFormOrg => 'Formes juridiques (organisations clientes)',
            self::DecisionRole => 'Rôles dans la décision',
            self::FundingType => 'Types de financeur (dossiers)',
            self::AbandonReason => 'Motifs d\'abandon',
            self::CancelReason => 'Motifs d\'annulation (dossiers et sessions)',
            self::BpfCategory => 'Catégories de stagiaires (BPF)',
            self::EducationLevel => 'Niveaux de diplôme',
            self::TrainingModality => 'Modalités de formation',
        };
    }

    /**
     * Only pipeline stages carry a closing probability (0-100).
     */
    public function hasProbability(): bool
    {
        return $this === self::PipelineStage;
    }

    /**
     * Action types carry their place in the CRM planning (meta.planning:
     * « meeting », « call » or none), set by the organisme.
     */
    public function hasPlanning(): bool
    {
        return $this === self::ActionType;
    }

    /**
     * One open pipeline stage per organisme may be flagged meta.quote_stage:
     * sending a quote moves an opportunity that is not past it there.
     */
    public function hasQuoteStage(): bool
    {
        return $this === self::PipelineStage;
    }

    /**
     * Abandon reasons carry a « force majeure » flag (meta.force_majeure):
     * a force majeure abandon never costs a dédit (spec lot 2a § 5.6).
     */
    public function hasForceMajeure(): bool
    {
        return $this === self::AbandonReason;
    }

    /**
     * Funding types carry the kind of contractant they fund by default
     * (meta.default_for: organisation|person): the initial funding of a
     * dossier created from a quote. One item per kind at most; none: no
     * initial funding.
     */
    public function hasDefaultFor(): bool
    {
        return $this === self::FundingType;
    }
}
