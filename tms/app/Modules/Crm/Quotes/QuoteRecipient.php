<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Crm\Affiliations\Affiliation;

/**
 * Who a quote is addressed to, as printed: the entreprise (with the
 * interlocuteur as « à l'attention de ») or the particulier. Copied into
 * recipient_snapshot when the quote is sent (QuoteFreezer); a sent quote is
 * then always printed from that copy.
 */
final class QuoteRecipient
{
    public const KEYS = ['name', 'attention', 'address_line1', 'address_line2', 'postal_code', 'city', 'email'];

    /**
     * @return array{name: string, attention: string|null, address_line1: string|null, address_line2: string|null, postal_code: string|null, city: string|null, email: string|null}
     */
    public static function of(Quote $quote): array
    {
        if (is_array($quote->recipient_snapshot)) {
            $snapshot = $quote->recipient_snapshot;
            $value = fn (string $key): ?string => isset($snapshot[$key]) && is_scalar($snapshot[$key]) ? (string) $snapshot[$key] : null;

            return [
                'name' => $value('name') ?? '',
                'attention' => $value('attention'),
                'address_line1' => $value('address_line1'),
                'address_line2' => $value('address_line2'),
                'postal_code' => $value('postal_code'),
                'city' => $value('city'),
                'email' => $value('email'),
            ];
        }

        return self::current($quote);
    }

    /**
     * The recipient as the fiches describe it today.
     *
     * @return array{name: string, attention: string|null, address_line1: string|null, address_line2: string|null, postal_code: string|null, city: string|null, email: string|null}
     */
    public static function current(Quote $quote): array
    {
        $contact = $quote->contact;
        $organisation = $quote->organisation_id !== null ? $quote->organisation : null;

        if ($organisation !== null) {
            // The interlocuteur's work e-mail at this entreprise first (a B2B quote), then her own, then the entreprise's.
            $workEmail = $contact === null ? null : Affiliation::query()
                ->where('person_id', $contact->id)->where('organisation_id', $organisation->id)
                ->whereNull('ended_on')->whereNotNull('work_email')->value('work_email');

            return [
                'name' => $organisation->name,
                'attention' => $contact?->fullName(),
                'address_line1' => $organisation->address_line1,
                'address_line2' => $organisation->address_line2,
                'postal_code' => $organisation->postal_code,
                'city' => $organisation->city,
                'email' => $workEmail ?? $contact->email ?? $organisation->email,
            ];
        }

        $person = $quote->person;

        return [
            'name' => $person?->fullName() ?? '',
            'attention' => $contact !== null && $contact->id !== $person?->id ? $contact->fullName() : null,
            'address_line1' => $person?->address_line1,
            'address_line2' => $person?->address_line2,
            'postal_code' => $person?->postal_code,
            'city' => $person?->city,
            'email' => $person?->email,
        ];
    }
}
