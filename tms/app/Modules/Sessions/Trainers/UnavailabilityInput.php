<?php

namespace App\Modules\Sessions\Trainers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The fields of an unavailability (manager or trainer himself): start and
 * end as « Y-m-d\TH:i » in Paris time, end after start, optional reason.
 */
final class UnavailabilityInput
{
    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, reason: string|null}
     */
    public static function from(Request $request): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'starts_at.required' => 'Indiquez le début.',
            'starts_at.date_format' => 'Indiquez un début valide (date et heure).',
            'ends_at.required' => 'Indiquez la fin.',
            'ends_at.date_format' => 'Indiquez une fin valide (date et heure).',
            'ends_at.after' => 'La fin doit être après le début.',
            'reason.string' => 'Le motif doit être un texte.',
            'reason.max' => 'Le motif ne doit pas dépasser 200 caractères.',
        ]);

        return [
            'from' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $data['starts_at'], 'Europe/Paris') ?: throw new \LogicException('Date invalide.'),
            'to' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $data['ends_at'], 'Europe/Paris') ?: throw new \LogicException('Date invalide.'),
            'reason' => isset($data['reason']) ? (string) $data['reason'] : null,
        ];
    }

    /**
     * Rows of a list of unavailabilities, Paris time.
     *
     * @param  iterable<TrainerUnavailability>  $unavailabilities
     * @return list<array{id: string, label: string, starts_at: string, ends_at: string, reason: string|null}>
     */
    public static function rows(iterable $unavailabilities): array
    {
        $rows = [];
        foreach ($unavailabilities as $u) {
            $rows[] = [
                'id' => $u->id,
                'label' => $u->label(),
                'starts_at' => $u->starts_at->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'),
                'ends_at' => $u->ends_at->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'),
                'reason' => $u->reason,
            ];
        }

        return $rows;
    }
}
