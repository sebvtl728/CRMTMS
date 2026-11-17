<?php

namespace App\Modules\Finance\Billing;

/**
 * What a dossier owes, cumulated up to a cut-off (BillingCalculator), in
 * integer cents:
 *
 *  - billableCents: the training part B(D) (training and fee lines), shared
 *    between the recipients: Σ byRecipient[*].training_cents = billableCents;
 *  - deditCents, absencesCents: the dédits of abandons and the unjustified
 *    absences, always to the contractor alone (spec 0.9 § 4 bis 1 and 7);
 *  - byRecipient: « contractor », then « funding:<id> » per funding taking a
 *    share (agreed, paying directly or billed outside the TMS: external,
 *    never invoiced by the TMS);
 *  - detail: how each amount was reached (minutes planned and realised,
 *    ratio), for display;
 *  - ready: false when nothing is billable yet at the cut-off, with the
 *    French reason (« Mois non clôturé », « Formation non terminée »,
 *    « Échéance non atteinte ») and no amount.
 *
 * @phpstan-type Recipient array{type: 'contractor'|'funder', id: string, fiche_type: string, funding_id?: string}
 * @phpstan-type RecipientShare array{recipient: Recipient, training_cents: int, dedit_cents: int, absences_cents: int, external: bool}
 * @phpstan-type Detail array{line_id: string, enrolment_id: ?string, planned: ?int, realised: ?int, ratio: string, amount_cents: int, dedit_cents: int, absences_cents: int}
 */
final readonly class Shares
{
    /**
     * @param  array<string, RecipientShare>  $byRecipient
     * @param  list<Detail>  $detail
     */
    public function __construct(
        public bool $ready,
        public ?string $reason,
        public int $billableCents,
        public int $deditCents,
        public int $absencesCents,
        public array $byRecipient,
        public array $detail,
    ) {}

    public static function notReady(string $reason): self
    {
        return new self(false, $reason, 0, 0, 0, [], []);
    }

    /**
     * @return array{ready: bool, reason: ?string, billable_cents: int, dedit_cents: int, absences_cents: int, by_recipient: array<string, RecipientShare>, detail: list<Detail>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'reason' => $this->reason,
            'billable_cents' => $this->billableCents,
            'dedit_cents' => $this->deditCents,
            'absences_cents' => $this->absencesCents,
            'by_recipient' => $this->byRecipient,
            'detail' => $this->detail,
        ];
    }
}
