<?php

namespace App\Modules\Crm\Quotes;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\Events\SignatureCompleted;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * « Bon pour accord » of a sent quote (Core\Signatures): the archived PDF
 * is signed online; once signed, the quote is accepted (date of Paris) and
 * the signed PDF becomes its signed copy. The opportunity stays as it is
 * (the « Accepté » button only proposes « Gagné ») and no dossier is created.
 * A quote that no longer awaits an answer invalidates its link.
 */
final class QuoteSignatures
{
    public const SUBJECT = 'quote';

    public const NOT_AWAITING = 'Ce devis n\'est plus en attente de réponse : il ne peut plus être signé.';

    public function __construct(private readonly Signatures $signatures) {}

    public function request(User $by, Quote $quote, string $email): SignatureRequest
    {
        Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']],
            ['email.required' => 'Indiquez l\'adresse du signataire.', 'email.email' => 'Adresse e-mail invalide.'])->validate();
        if (! $quote->isAwaitingAnswer()) {
            throw new UserFacingHttpException(409, self::NOT_AWAITING);
        }
        $disk = Storage::disk(TenantStorage::DISK);
        if ($quote->pdf_path === null || ! $disk->exists($quote->pdf_path)) {
            throw new UserFacingHttpException(409, 'Le PDF archivé de ce devis est introuvable : il ne peut pas être signé.');
        }
        $recipient = QuoteRecipient::of($quote);

        return $this->signatures->request(self::SUBJECT, $quote->id, $quote->pdf_path, hash('sha256', (string) $disk->get($quote->pdf_path)),
            (string) ($recipient['attention'] ?? $recipient['name']), $email, $quote->contact_person_id ?? $quote->person_id,
            'Devis '.$quote->number, $by)['request'];
    }

    /** Refusal of the signature service: only a quote awaiting an answer is signed. */
    public static function refusal(string $quoteId): ?string
    {
        $quote = Quote::query()->find($quoteId);

        return $quote !== null && $quote->isAwaitingAnswer() ? null : self::NOT_AWAITING;
    }

    /** SignatureCompleted of a quote: accepted, signed copy stored. */
    public static function completed(SignatureCompleted $event, QuoteTransitions $transitions, QuoteSignedCopy $copies): void
    {
        if ($event->subjectType !== self::SUBJECT) {
            return;
        }
        $request = SignatureRequest::query()->find($event->requestId);
        $quote = Quote::query()->find($event->subjectId);
        // Answered meanwhile (the guard refuses the signature, but a race may remain): nothing to accept.
        if ($request === null || $quote === null || ! $quote->isAwaitingAnswer()) {
            return;
        }
        DB::transaction(function () use ($quote, $request, $event, $transitions, $copies): void {
            $transitions->accept($quote, $event->signedAt->setTimezone('Europe/Paris'));
            $copies->storePdf($quote->fresh() ?? $quote, Signatures::signedPdf($request));
        });
    }

    /**
     * @return array{status: string, signed_at: ?string, email: ?string}|null
     */
    public static function state(Quote $quote): ?array
    {
        $request = Signatures::latestFor(self::SUBJECT, $quote->id);

        return $request === null ? null : ['status' => $request->status, 'signed_at' => $request->signed_at?->toIso8601String(), 'email' => $request->signer_email];
    }

    /** Today, in the time zone of the quotes. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(QuoteNumbering::TIMEZONE);
    }
}
