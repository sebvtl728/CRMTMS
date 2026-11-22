<?php

namespace App\Modules\Qualite\Registers;

use App\Modules\Qualite\Public\Measures;
use Carbon\CarbonImmutable;

/**
 * Automatic measures of the registers on the Qualiopi file, over the last
 * 12 months (days of Paris): complaints received (indicator 31) and
 * improvement actions (indicator 32).
 */
final class RegisterMeasures
{
    public static function register(): void
    {
        Measures::register(31, 'qualite.complaints', fn () => self::complaints());
        Measures::register(32, 'qualite.improvements', fn () => self::improvements());
    }

    /** @return array<string, mixed> */
    private static function complaints(): array
    {
        $rows = Complaint::query()->where('received_on', '>=', self::since())->get(['status', 'received_on', 'responded_on']);
        $closed = $rows->where('status', 'closed');
        $open = $rows->count() - $closed->count();
        $delays = $closed->filter(fn (Complaint $c) => $c->responded_on !== null)
            ->map(fn (Complaint $c) => (int) $c->received_on->diffInDays($c->responded_on));
        $average = $delays->isEmpty() ? null : (int) round($delays->avg());

        return [
            'label' => 'Réclamations reçues (12 mois)',
            'value' => (string) $rows->count(),
            'detail' => $rows->isEmpty() ? 'Aucune réclamation enregistrée.'
                : $closed->count().' close'.($closed->count() > 1 ? 's' : '').($average === null ? '' : ', délai moyen de réponse : '.$average.' jour'.($average > 1 ? 's' : ''))
                    .' ; '.$open.' ouverte'.($open > 1 ? 's' : '').'.',
            'tone' => $open > 0 ? 'warn' : 'ok',
        ];
    }

    /** @return array<string, mixed> */
    private static function improvements(): array
    {
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();
        $rows = ImprovementAction::query()->where('created_at', '>=', self::since())->where('status', '<>', 'abandoned')->get(['status', 'due_on']);
        $done = $rows->where('status', 'done')->count();
        $late = $rows->filter(fn (ImprovementAction $a) => $a->status !== 'done' && $a->due_on !== null && $a->due_on->toDateString() < $today)->count();

        return [
            'label' => 'Actions d\'amélioration (12 mois)',
            'value' => $done.' réalisée'.($done > 1 ? 's' : '').' sur '.$rows->count(),
            'detail' => $late === 0 ? null : $late.' en retard.',
            'tone' => $rows->isEmpty() ? 'neutral' : ($late > 0 ? 'warn' : 'ok'),
        ];
    }

    private static function since(): string
    {
        return CarbonImmutable::now('Europe/Paris')->subMonths(12)->toDateString();
    }
}
