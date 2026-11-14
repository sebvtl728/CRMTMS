<?php

namespace App\Modules\Crm\Quotes;

/**
 * Life of a quote. Only a draft is created and changed; sending, reminders,
 * the outcome and the expiry move it on (QuoteTransitions). The list is
 * global (CHECK of quotes.status): it is the quote's own workflow, not a
 * référentiel of the organisme.
 */
enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Reminded = 'reminded';
    case Accepted = 'accepted';
    case Refused = 'refused';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Sent => 'Envoyé',
            self::Reminded => 'Relancé',
            self::Accepted => 'Accepté',
            self::Refused => 'Refusé',
            self::Expired => 'Expiré',
        };
    }

    /** Past participle of the timeline (« Devis DEV-2026-0012 envoyé »). */
    public function event(): string
    {
        return match ($this) {
            self::Draft => 'créé',
            self::Sent => 'envoyé',
            self::Reminded => 'relancé',
            self::Accepted => 'accepté',
            self::Refused => 'refusé',
            self::Expired => 'expiré',
        };
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
        return array_map(fn (self $s) => $s->toOption(), self::cases());
    }
}
