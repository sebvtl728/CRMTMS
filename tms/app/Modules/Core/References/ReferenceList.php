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
}
