<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Documents\PdfSource;
use App\Modules\Core\Signatures\SignatureRequest;
use Illuminate\Support\Facades\DB;

/**
 * What the anonymisation of a person (Gdpr\Anonymiser, in its transaction)
 * removes from the quotes (trashed ones too). Numbers, dates, statuses,
 * lines and totals stay (statistics, accounting).
 * - Her quotes as particulier: the recipient copy, the recipient, the
 *   mentions, the refusal reason and the last e-mail are cleared.
 * - Her quotes as interlocuteur of an entreprise: she is no longer the
 *   recipient; the copy keeps the entreprise (name, address) without her
 *   name nor an e-mail; the last e-mail (written to her) is cleared.
 * - Both: the signed copy sent back (a document signed by her or naming
 *   her) is deleted and its columns cleared; the archived PDF names her, it is deleted (the caller deletes the
 *   files after the commit) and never rendered again (QuoteController::pdf
 *   answers 410).
 * - Any other quote whose last e-mail went to her or copied her: her
 *   address leaves the copies; a message sent to her is cleared.
 */
final class QuoteAnonymisation
{
    /**
     * @param  list<string>  $subjects  the person and the fiches merged into her
     * @param  list<string>  $emails  her e-mail addresses, lower-cased
     * @return array{quotes: int, files: list<string>} quotes changed, archived PDFs to delete after the commit
     */
    public static function scrub(array $subjects, array $emails): array
    {
        $now = now();
        $noMessage = ['email_message' => null, 'email_status' => null, 'email_error' => null];

        $noSigned = ['signed_path' => null, 'signed_name' => null, 'signed_at' => null];
        $own = DB::table('quotes')->whereIn('person_id', $subjects)->get(['id', 'pdf_path', 'signed_path']);
        DB::table('quotes')->whereIn('id', $own->pluck('id')->all())->update([
            'recipient_snapshot' => null,
            'contact_person_id' => null,
            'notes' => null,
            'refusal_reason' => null,
            'pdf_path' => null,
            ...$noSigned,
            ...$noMessage,
            'updated_at' => $now,
        ]);

        $asContact = DB::table('quotes')->whereIn('contact_person_id', $subjects)->get(['id', 'pdf_path', 'signed_path', 'recipient_snapshot']);
        foreach ($asContact as $quote) {
            $snapshot = is_string($quote->recipient_snapshot) ? json_decode($quote->recipient_snapshot, true) : null;
            if (is_array($snapshot)) {
                $snapshot = [...$snapshot, 'attention' => null, 'email' => null];
            }
            DB::table('quotes')->where('id', $quote->id)->update([
                'contact_person_id' => null,
                'recipient_snapshot' => is_array($snapshot) ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                'pdf_path' => null,
                ...$noSigned,
                ...$noMessage,
                'updated_at' => $now,
            ]);
        }

        // Their online signatures (« Bon pour accord »): pending ones cancelled, signer data and signed PDFs erased.
        $signed = SignatureRequest::query()->where('subject_type', QuoteSignatures::SUBJECT)
            ->where(fn ($q) => $q->whereIn('subject_id', [...$own->pluck('id')->all(), ...$asContact->pluck('id')->all()])->orWhereIn('signer_person_id', $subjects))->get();
        $signedFiles = [...$signed->pluck('signed_pdf_path')->filter()->values()->all(), ...$signed->pluck('drawing_path')->filter()->values()->all()];
        SignatureRequest::query()->whereKey($signed->pluck('id')->all())->update(['signer_name' => null, 'signer_email' => null, 'signer_person_id' => null,
            'signed_ip' => null, 'signed_user_agent' => null, 'signed_pdf_path' => null, 'drawing_path' => null, 'status' => DB::raw("case when status = 'pending' then 'cancelled' else status end")]);

        $messaged = self::messagesTo($emails);

        return [
            'quotes' => count(array_unique([...$own->pluck('id')->all(), ...$asContact->pluck('id')->all(), ...$messaged])),
            'files' => array_values(array_filter([
                ...$own->pluck('pdf_path')->all(), ...$asContact->pluck('pdf_path')->all(),
                // The frozen HTML of the archived PDF (PdfSource) names her too.
                ...array_map(fn ($path) => is_string($path) ? PdfSource::pathFor($path) : null, [...$own->pluck('pdf_path')->all(), ...$asContact->pluck('pdf_path')->all()]),
                ...$own->pluck('signed_path')->all(), ...$asContact->pluck('signed_path')->all(), ...$signedFiles,
            ], 'is_string')),
        ];
    }

    /**
     * Her addresses leave the last e-mail of the other quotes.
     *
     * @param  list<string>  $emails
     * @return list<string> ids of the quotes changed
     */
    private static function messagesTo(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $cc = "case when jsonb_typeof(email_message->'cc') = 'array' then email_message->'cc' else '[]'::jsonb end";
        $quotes = DB::table('quotes')->whereNotNull('email_message')
            ->where(fn ($q) => $q
                ->whereRaw("lower(btrim(email_message->>'to')) in (select jsonb_array_elements_text(?::jsonb))", [json_encode($emails)])
                ->orWhereRaw("exists (select 1 from jsonb_array_elements_text({$cc}) c where lower(btrim(c)) in (select jsonb_array_elements_text(?::jsonb)))", [json_encode($emails)]))
            ->get(['id', 'email_message']);

        $theirs = fn (mixed $address): bool => is_string($address) && in_array(mb_strtolower(trim($address)), $emails, true);
        foreach ($quotes as $quote) {
            $message = json_decode((string) $quote->email_message, true);
            $changes = ! is_array($message) || $theirs($message['to'] ?? null)
                ? ['email_message' => null, 'email_status' => null, 'email_error' => null]
                : ['email_message' => json_encode([
                    ...$message,
                    'cc' => array_values(array_filter(is_array($message['cc'] ?? null) ? $message['cc'] : [], fn ($a) => ! $theirs($a))),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            DB::table('quotes')->where('id', $quote->id)->update([...$changes, 'updated_at' => now()]);
        }

        return $quotes->pluck('id')->all();
    }
}
