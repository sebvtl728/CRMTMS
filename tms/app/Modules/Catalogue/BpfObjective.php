<?php

namespace App\Modules\Catalogue;

/**
 * « Objectif général des prestations dispensées » of a training: its line
 * in cadre F-3 of the BPF (Cerfa n° 10443). Labels to check against the
 * notice of the declared exercise.
 */
enum BpfObjective: string
{
    case Rncp68 = 'rncp_6_8';
    case Rncp5 = 'rncp_5';
    case Rncp4 = 'rncp_4';
    case Rncp3 = 'rncp_3';
    case Rncp2 = 'rncp_2';
    case RncpCqp = 'rncp_cqp';
    case Rs = 'rs';
    case Cqp = 'cqp';
    case Other = 'other';
    case SkillsAssessment = 'skills_assessment';
    case Vae = 'vae';

    public function label(): string
    {
        return match ($this) {
            self::Rncp68 => 'Diplôme ou titre RNCP, niveaux 6 à 8',
            self::Rncp5 => 'Diplôme ou titre RNCP, niveau 5',
            self::Rncp4 => 'Diplôme ou titre RNCP, niveau 4',
            self::Rncp3 => 'Diplôme ou titre RNCP, niveau 3',
            self::Rncp2 => 'Diplôme ou titre RNCP, niveau 2',
            self::RncpCqp => 'CQP enregistré au RNCP sans niveau de qualification',
            self::Rs => 'Certification ou habilitation enregistrée au répertoire spécifique (RS)',
            self::Cqp => 'CQP non enregistré au RNCP ni au RS',
            self::Other => 'Autres formations professionnelles',
            self::SkillsAssessment => 'Bilan de compétences',
            self::Vae => 'Accompagnement à la VAE',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
