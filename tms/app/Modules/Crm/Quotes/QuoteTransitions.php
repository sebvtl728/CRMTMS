<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Brochures;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Brochures\BrochureAttachments;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\LastContact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Life of a quote after its draft: sending (frozen, archived, e-mailed,
 * opportunity moved to the quote stage), reminders, acceptance, refusal,
 * replacement by a new version and expiry. Every transition is checked here
 * on the locked row (409 in French otherwise); the timeline reads them from
 * the journal of the quote.
 *
 * Sending and reminding count as a contact of the account and of the
 * recipient (LastContact, source registered by the CrmServiceProvider).
 *
 * @phpstan-type Message array{to: string, cc: list<string>, subject: string, body: string}
 */
final class QuoteTransitions
{
    public const NOT_DRAFT = 'Seul un brouillon s\'envoie : ce devis est déjà envoyé.';

    public const NOT_AWAITING_REMIND = 'Seul un devis envoyé ou relancé se relance.';

    public const NOT_AWAITING_ANSWER = 'Seul un devis envoyé ou relancé peut être accepté ou refusé.';

    public const STILL_SENDING = 'L\'e-mail précédent de ce devis est encore en cours d\'envoi : réessayez dans un instant.';

    public const LAST_MAIL_FAILED = 'Le dernier e-mail de ce devis n\'est pas parti : utilisez « Renvoyer » avant de relancer.';

    public const NOTHING_TO_RESEND = 'Aucun e-mail en échec à renvoyer pour ce devis.';

    public const VAT_REGIME_CHANGED = 'Le régime de TVA de la société a changé : ouvrez le brouillon, vérifiez les taux et enregistrez-le.';

    public const VALIDITY_PASSED = 'La date de validité est dépassée : modifiez les dates du devis avant de l\'envoyer.';

    public const NO_ARCHIVE = 'Le PDF archivé de ce devis est introuvable : il ne peut pas être joint au message. Contactez l\'administrateur.';

    /** Bytes: the quote PDF and the brochures of one e-mail. */
    public const MAX_ATTACHMENTS_BYTES = BrochureAttachments::MAX_BYTES;

    public const ATTACHMENTS_TOO_BIG = BrochureAttachments::TOO_BIG;

    public const BROCHURE_GONE = BrochureAttachments::GONE;

    public function __construct(
        private readonly QuoteFreezer $freezer,
        private readonly OpportunityStage $stages,
        private readonly QuotePdf $pdf,
        private readonly Brochures $brochures,
    ) {}

    /**
     * Why this draft cannot be sent as it is, null when it can: its
     * company changed its VAT regime since it was saved (the rates and the
     * exemption notice would be wrong), or its validity date is past
     * (Paris). Shown on the fiche, checked again on the locked row.
     */
    public static function sendBlocker(Quote $draft): ?string
    {
        if (self::vatRegimeChanged($draft)) {
            return self::VAT_REGIME_CHANGED;
        }
        if ($draft->valid_until->toDateString() < CarbonImmutable::now(QuoteNumbering::TIMEZONE)->toDateString()) {
            return self::VALIDITY_PASSED;
        }

        return null;
    }

    /** A draft saved under the other VAT regime of its company. */
    public static function vatRegimeChanged(Quote $draft): bool
    {
        $company = $draft->company;

        return $draft->isDraft() && $company !== null && $draft->vat_exempt !== ($company->vat_regime === VatRegime::Exempt);
    }

    /**
     * @param  Message  $message
     * @param  list<string>  $brochureVersionIds  brochure versions joined to the e-mail (and to its reminders)
     */
    public function send(Quote $quote, array $message, array $brochureVersionIds = []): void
    {
        $this->transition($quote, function (Quote $locked) use ($message, $brochureVersionIds): void {
            if (! $locked->isDraft()) {
                throw new UserFacingHttpException(409, self::NOT_DRAFT);
            }
            $blocker = self::sendBlocker($locked);
            if ($blocker !== null) {
                throw new UserFacingHttpException(409, $blocker);
            }
            // Refused before anything is rendered or archived.
            $versions = $this->brochureVersions($brochureVersionIds);
            $this->freezer->freeze($locked);
            $this->requireRoom($locked, $versions);
            foreach ($versions as $position => $version) {
                QuoteBrochure::query()->create(['quote_id' => $locked->id, 'brochure_version_id' => $version['version_id'], 'position' => $position]);
            }

            $now = CarbonImmutable::now();
            $locked->status = QuoteStatus::Sent;
            $locked->forceFill(['sent_at' => $now]);
            $this->queueMail($locked, QuoteMail::KIND_SEND, $message);

            $this->advanceOpportunity($locked);
            self::recordContact($locked, $now);
        });
    }

    /**
     * @param  Message  $message
     */
    public function remind(Quote $quote, array $message): void
    {
        $this->transition($quote, function (Quote $locked) use ($message): void {
            if (! $locked->isAwaitingAnswer()) {
                throw new UserFacingHttpException(409, self::NOT_AWAITING_REMIND);
            }
            if ($locked->email_status === 'pending') {
                throw new UserFacingHttpException(409, self::STILL_SENDING);
            }
            if ($locked->email_status === 'failed') {
                throw new UserFacingHttpException(409, self::LAST_MAIL_FAILED);
            }
            $this->requireArchive($locked);

            $now = CarbonImmutable::now();
            $locked->status = QuoteStatus::Reminded;
            $locked->forceFill(['reminded_at' => $now]);
            $this->queueMail($locked, QuoteMail::KIND_REMIND, $message);
            self::recordContact($locked, $now);
        });
    }

    /**
     * « Renvoyer »: the last message again, after every try failed.
     */
    public function resend(Quote $quote): void
    {
        $this->transition($quote, function (Quote $locked): void {
            if ($locked->email_status !== 'failed' || ! is_array($locked->email_message)) {
                throw new UserFacingHttpException(409, self::NOTHING_TO_RESEND);
            }
            $this->requireArchive($locked);
            $locked->email_status = 'pending';
            $locked->email_error = null;
            $locked->save();
            SendQuote::dispatch($locked->id)->afterCommit();
        });
    }

    public function accept(Quote $quote, CarbonImmutable $on): void
    {
        $this->transition($quote, function (Quote $locked) use ($on): void {
            if (! $locked->isAwaitingAnswer()) {
                throw new UserFacingHttpException(409, self::NOT_AWAITING_ANSWER);
            }
            $locked->status = QuoteStatus::Accepted;
            $locked->forceFill(['accepted_on' => $on->toDateString()]);
            $locked->save();
        });
    }

    public function refuse(Quote $quote, CarbonImmutable $on, ?string $reason): void
    {
        $this->transition($quote, function (Quote $locked) use ($on, $reason): void {
            if (! $locked->isAwaitingAnswer()) {
                throw new UserFacingHttpException(409, self::NOT_AWAITING_ANSWER);
            }
            $locked->status = QuoteStatus::Refused;
            $locked->forceFill(['refused_on' => $on->toDateString()]);
            $locked->refusal_reason = $reason;
            $locked->save();
        });
    }

    /**
     * A new version of a quote: a draft copy (QuoteWriter::duplicate). A
     * quote still awaiting an answer, or expired, is then refused as
     * « Remplacé par <numéro> » (replaced_by_id); a draft, an accepted or a
     * refused quote is left as it is. One transaction.
     */
    public function newVersion(Quote $quote, QuoteWriter $writer, Opportunity $opportunity): Quote
    {
        return DB::transaction(function () use ($quote, $writer, $opportunity): Quote {
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());
            $copy = $writer->duplicate($locked, $opportunity);

            // An accepted or refused quote keeps its answer: the copy is only a new draft.
            if ($locked->isAwaitingAnswer() || $locked->status === QuoteStatus::Expired) {
                $locked->status = QuoteStatus::Refused;
                $locked->forceFill(['refused_on' => CarbonImmutable::now(QuoteNumbering::TIMEZONE)->toDateString()]);
                $locked->refusal_reason = "Remplacé par {$copy->number}";
                $locked->replaced_by_id = $copy->id;
                $locked->save();
            }
            $quote->setRawAttributes($locked->getAttributes(), true);

            return $copy;
        });
    }

    /**
     * Sent or reminded quotes whose validity ended before today (Paris):
     * expired. In the current organisme; returns how many.
     */
    public static function expireDue(): int
    {
        $today = CarbonImmutable::now(QuoteNumbering::TIMEZONE)->toDateString();
        $count = 0;

        Quote::query()->whereIn('status', [QuoteStatus::Sent, QuoteStatus::Reminded])
            ->where('valid_until', '<', $today)
            ->lazyById()
            ->each(function (Quote $candidate) use ($today, &$count): void {
                // Re-read under lock: an answer given meanwhile is never overwritten.
                DB::transaction(function () use ($candidate, $today, &$count): void {
                    $quote = Quote::query()->lockForUpdate()->find($candidate->getKey());
                    if ($quote === null || ! $quote->isAwaitingAnswer() || $quote->valid_until->toDateString() >= $today) {
                        return;
                    }
                    $quote->status = QuoteStatus::Expired;
                    $quote->save();
                    $count++;
                });
            });

        return $count;
    }

    /**
     * The versions chosen, each once, in order; one that no longer exists
     * (or the total of the brochures alone over the limit) is refused.
     *
     * @param  list<string>  $ids
     * @return list<array{version_id: string, path: string, original_name: string, size: int}>
     */
    private function brochureVersions(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $versions = $this->brochures->versions($ids);
        if (count($versions) !== count($ids)) {
            throw new UserFacingHttpException(422, self::BROCHURE_GONE);
        }
        if (BrochureAttachments::tooBig(array_sum(array_column($versions, 'size')))) {
            throw new UserFacingHttpException(422, self::ATTACHMENTS_TOO_BIG);
        }

        return $versions;
    }

    /**
     * The archived PDF and the brochures together stay within the limit of
     * one e-mail; otherwise the archive just written is removed (the
     * transaction rolls the quote back to a draft).
     *
     * @param  list<array{version_id: string, path: string, original_name: string, size: int}>  $versions
     */
    private function requireRoom(Quote $quote, array $versions): void
    {
        if ($versions === []) {
            return;
        }
        $disk = Storage::disk(TenantStorage::DISK);
        $path = (string) $quote->pdf_path;
        if (BrochureAttachments::tooBig((int) $disk->size($path) + array_sum(array_column($versions, 'size')))) {
            $disk->delete($path);

            throw new UserFacingHttpException(422, self::ATTACHMENTS_TOO_BIG);
        }
    }

    /**
     * A reminder or « Renvoyer » attaches the archived PDF: without it
     * (purged by the anonymisation, or lost) nothing is queued.
     */
    private function requireArchive(Quote $quote): void
    {
        if ($this->pdf->archived($quote) === null) {
            throw new UserFacingHttpException(409, self::NO_ARCHIVE);
        }
    }

    /**
     * @param  callable(Quote): void  $change  on the locked row, saved afterwards
     */
    private function transition(Quote $quote, callable $change): void
    {
        DB::transaction(function () use ($quote, $change): void {
            $locked = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());
            $change($locked);
            $locked->save();
            $quote->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  Message  $message
     */
    private function queueMail(Quote $quote, string $kind, array $message): void
    {
        $quote->email_message = ['kind' => $kind, ...$message];
        $quote->email_status = 'pending';
        $quote->email_error = null;
        $quote->save();
        SendQuote::dispatch($quote->id)->afterCommit();
    }

    /**
     * « Proposition » (the stage flagged by the organisme), when the
     * opportunity is open and not past it.
     */
    private function advanceOpportunity(Quote $quote): void
    {
        $stage = OpportunityStage::quoteStage();
        $opportunity = Opportunity::query()->find($quote->opportunity_id);
        if ($stage !== null && $opportunity !== null) {
            $this->stages->advanceTo($opportunity, $stage);
        }
    }

    private static function recordContact(Quote $quote, CarbonImmutable $at): void
    {
        if ($quote->organisation_id !== null) {
            LastContact::record((new Organisation)->getMorphClass(), $quote->organisation_id, $at);
        }
        foreach (array_unique(array_filter([$quote->person_id, $quote->contact_person_id])) as $personId) {
            LastContact::record((new Person)->getMorphClass(), $personId, $at);
        }
    }
}
