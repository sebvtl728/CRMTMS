<?php

namespace App\Modules\Sessions\Dossiers;

use Carbon\CarbonImmutable;

/**
 * Life of a dossier, fixed in the code (it drives rules), not a référentiel
 * (spec lot 2a § 5.3 and § 9): Créé → Convention envoyée → Convention
 * signée → Convocations envoyées → En cours → Réalisé; Annulé (with a
 * reason) from any state before Réalisé. Invoiced and settled are reserved
 * for lot 3 (never offered nor reached in 2a).
 *
 * « En cours » and « Réalisé » are read from the dates of the sessions of
 * its enrolments (effective()) while the stored state is one of the steps
 * before them (BEFORE_RUNNING); once set by hand they are no longer computed.
 */
enum DossierStatus: string
{
    case Created = 'created';
    case ConventionSent = 'convention_sent';
    case ConventionSigned = 'convention_signed';
    case ConvocationsSent = 'convocations_sent';
    case Running = 'running';
    case Done = 'done';
    case Cancelled = 'cancelled';
    case Invoiced = 'invoiced';
    case Settled = 'settled';

    /** Stored states whose effective state follows the session dates. */
    public const BEFORE_RUNNING = [self::Created, self::ConventionSent, self::ConventionSigned, self::ConvocationsSent];

    /** States of lot 2a (filters, choices): lot 3 adds invoiced and settled. */
    public const OFFERED = [self::Created, self::ConventionSent, self::ConventionSigned, self::ConvocationsSent, self::Running, self::Done, self::Cancelled];

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Créé',
            self::ConventionSent => 'Convention envoyée',
            self::ConventionSigned => 'Convention signée',
            self::ConvocationsSent => 'Convocations envoyées',
            self::Running => 'En cours',
            self::Done => 'Réalisé',
            self::Cancelled => 'Annulé',
            self::Invoiced => 'Facturé',
            self::Settled => 'Soldé',
        };
    }

    /**
     * The state shown on $today (a Paris day), $firstStart / $lastEnd being
     * the earliest start and the latest end of the sessions of its
     * enrolments (null: no session yet): running from the first start, done
     * after the last end, both included in « En cours ».
     */
    public function effective(?CarbonImmutable $firstStart, ?CarbonImmutable $lastEnd, CarbonImmutable $today): self
    {
        if (! in_array($this, self::BEFORE_RUNNING, true) || $firstStart === null || $lastEnd === null) {
            return $this;
        }
        $day = $today->toDateString();

        return match (true) {
            $day > $lastEnd->toDateString() => self::Done,
            $day >= $firstStart->toDateString() => self::Running,
            default => $this,
        };
    }

    /** Done or after (invoiced, settled): no cancellation any more. */
    public function isDoneOrAfter(): bool
    {
        return in_array($this, [self::Done, self::Invoiced, self::Settled], true);
    }

    /**
     * @return array{value: string, label: string}
     */
    public function toOption(): array
    {
        return ['value' => $this->value, 'label' => $this->label()];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => $s->toOption(), self::OFFERED);
    }
}
