<?php

namespace App\Modules\Sessions\Modules;

/**
 * How a session module is followed: over a period, its realised hours
 * entered month by month (no séance), or by dated séances (day, times,
 * trainer, room, attendance and signing per séance). Labels in plain
 * words: the screens explain what each implies.
 */
enum DatingMode: string
{
    case Interval = 'interval';
    case Seances = 'seances';

    public function label(): string
    {
        return match ($this) {
            self::Interval => 'Période, heures saisies chaque mois',
            self::Seances => 'Séances planifiées',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Interval => 'Le module court sur une période (début, fin), sans planning jour par jour : les heures réalisées de chaque stagiaire se saisissent chaque mois (Suivi › Présences › Heures du mois). Pour la formation à distance, en entreprise ou l\'alternance.',
            self::Seances => 'Chaque séance a son jour, ses horaires, son formateur et sa salle ; présences et émargement se font séance par séance. Pour le présentiel classique.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label(), 'description' => $m->description()], self::cases());
    }
}
