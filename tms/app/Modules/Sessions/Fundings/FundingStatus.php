<?php

namespace App\Modules\Sessions\Fundings;

/**
 * State of a funding, fixed in the code and parallel to the state of the
 * dossier (a request to the OPCO may come before or after the convention).
 * A refused funding no longer counts in the amount funded (FundingBalance);
 * nothing is moved to another funder automatically.
 */
enum FundingStatus: string
{
    case ToRequest = 'to_request';
    case Requested = 'requested';
    case Agreed = 'agreed';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::ToRequest => 'À demander',
            self::Requested => 'Demandé',
            self::Agreed => 'Accordé',
            self::Refused => 'Refusé',
        };
    }

    public function counts(): bool
    {
        return $this !== self::Refused;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
