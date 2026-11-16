<?php

namespace App\Modules\Sessions\Enrolments;

/**
 * Life of an enrolment, fixed in the code (it drives rules): enrolled or
 * waitlisted (an inter session full), confirmed, abandoned (a leaving day
 * and a reason, the share kept), cancelled (the place freed, nobody
 * promoted automatically).
 */
enum EnrolmentStatus: string
{
    case Enrolled = 'enrolled';
    case Waitlisted = 'waitlisted';
    case Confirmed = 'confirmed';
    case Abandoned = 'abandoned';
    case Cancelled = 'cancelled';

    /** States that hold a place of the session. */
    public const HOLDING_A_PLACE = [self::Enrolled, self::Confirmed];

    /** States sharing the price of a line priced per trainee (abandons keep their share). */
    public const SHARING_THE_PRICE = [self::Enrolled, self::Confirmed, self::Abandoned];

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Inscrit',
            self::Waitlisted => 'En attente',
            self::Confirmed => 'Confirmé',
            self::Abandoned => 'Abandon',
            self::Cancelled => 'Annulé',
        };
    }

    /**
     * @param  list<self>  $states
     * @return list<string>
     */
    public static function values(array $states): array
    {
        return array_map(fn (self $s) => $s->value, $states);
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }
}
