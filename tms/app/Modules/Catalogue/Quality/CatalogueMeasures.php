<?php

namespace App\Modules\Catalogue\Quality;

use App\Modules\Catalogue\Training;
use App\Modules\Qualite\Public\Measures;

/**
 * Qualiopi indicators 1 and 5: the active training sheets with objectives,
 * audience, prerequisites, methods, evaluation and accessibility filled in.
 */
final class CatalogueMeasures
{
    public const FIELDS = ['audience', 'prerequisites', 'methods', 'evaluation', 'accessibility'];

    public static function register(): void
    {
        foreach ([1, 5] as $indicator) {
            Measures::register($indicator, 'catalogue.complete_sheets', fn () => self::completeSheets());
        }
    }

    /** @return array<string, mixed> */
    public static function completeSheets(): array
    {
        $total = 0;
        $complete = 0;
        Training::query()->where('is_active', true)->select(['id', 'objectives', ...self::FIELDS])
            ->chunkById(500, function ($trainings) use (&$total, &$complete): void {
                foreach ($trainings as $training) {
                    $total++;
                    $filled = array_filter((array) $training->objectives, fn ($o) => trim((string) $o) !== '') !== []
                        && array_all(self::FIELDS, fn (string $f) => trim((string) $training->{$f}) !== '');
                    $complete += $filled ? 1 : 0;
                }
            });

        return [
            'label' => 'Fiches formation actives complètes',
            ...Measures::ratio($complete, $total),
            'detail' => 'Objectifs, public, prérequis, méthodes, évaluation et accessibilité renseignés.',
        ];
    }
}
