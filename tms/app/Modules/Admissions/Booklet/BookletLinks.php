<?php

namespace App\Modules\Admissions\Booklet;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The monthly links of the booklet: one per candidature, month and signer
 * (apprentice or tutor) open at a time, 40 random characters of which only
 * the SHA-256 is kept, valid 30 days of Paris; the recipient is the e-mail
 * of the person's fiche at the sending, frozen on the link.
 */
final class BookletLinks
{
    public const VALID_DAYS = 30;

    public const NO_EMAIL_APPRENTICE = 'Ajoutez l\'e-mail de l\'apprenti sur sa fiche.';

    public const NO_EMAIL_TUTOR = 'Ajoutez l\'e-mail du tuteur sur sa fiche.';

    public const NO_TUTOR = 'Indiquez le tuteur dans le contrat.';

    public function __construct(private readonly Fiches $fiches) {}

    /**
     * Sends (or sends again: the open link is closed) the link of $signer
     * for $month ('Y-m'); $by null when the chain sends it (the apprentice
     * signed).
     */
    public function send(Candidature $candidature, string $month, string $signer, ?User $by): BookletLink
    {
        if (! in_array($signer, BookletLink::SIGNERS, true)) {
            throw new UserFacingHttpException(404, 'Signataire inconnu.');
        }
        $first = BookletMonths::checkMonth($candidature, $month);
        $token = Str::random(40);

        $link = DB::transaction(function () use ($candidature, $first, $signer, $by, $token): BookletLink {
            Candidature::query()->whereKey($candidature->id)->lockForUpdate()->firstOrFail();
            $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->firstOrFail();
            $personId = $signer === BookletLink::APPRENTICE ? $candidature->person_id : $contract->tutor_person_id;
            if ($personId === null) {
                throw new UserFacingHttpException(409, self::NO_TUTOR);
            }
            $page = BookletMonth::query()->where('candidature_id', $candidature->id)->where('month', $first->toDateString())
                ->where('place', BookletMonth::COMPANY)->first();
            if ($page !== null && $page->signedBy($signer)) {
                throw new UserFacingHttpException(409, BookletMonths::SIGNED);
            }
            $card = $this->fiches->contactCard('person', $personId);
            $email = is_string($card['email'] ?? null) ? trim($card['email']) : '';
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new UserFacingHttpException(409, $signer === BookletLink::APPRENTICE ? self::NO_EMAIL_APPRENTICE : self::NO_EMAIL_TUTOR);
            }

            BookletLink::query()->where('candidature_id', $candidature->id)->where('month', $first->toDateString())
                ->where('signer', $signer)->whereNull('closed_at')->update(['closed_at' => now()]);

            return BookletLink::query()->create([
                'candidature_id' => $candidature->id,
                'month' => $first->toDateString(),
                'signer' => $signer,
                'person_id' => $personId,
                'email' => $email,
                'signer_name' => trim(($card['first_name'] ?? '').' '.mb_strtoupper((string) ($card['last_name'] ?? ''))),
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now('Europe/Paris')->startOfDay()->addDays(self::VALID_DAYS)->endOfDay(),
                'email_status' => 'pending',
                'created_by' => $by?->id,
            ]);
        });
        SendBookletLink::dispatch($link->id, $token)->afterCommit();

        return $link;
    }

    /**
     * The open link of $token, or null for anything invalid (unknown,
     * expired, closed, candidature in the trash or anonymised, no contract):
     * the public page answers the same 404.
     */
    public static function find(string $token): ?BookletLink
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }
        $link = BookletLink::query()->where('token_hash', hash('sha256', $token))->first();
        if ($link === null || ! BookletMonths::isOpen($link)) {
            return null;
        }
        $candidature = Candidature::query()->find($link->candidature_id);
        if ($candidature === null || $candidature->anonymised_at !== null
            || ! AlternanceContract::query()->where('candidature_id', $candidature->id)->exists()) {
            return null;
        }

        return $link;
    }

    public static function openFor(Candidature $candidature, string $month, string $signer): ?BookletLink
    {
        return BookletLink::query()->where('candidature_id', $candidature->id)->where('month', $month.'-01')
            ->where('signer', $signer)->whereNull('closed_at')->where('expires_at', '>', now())->first();
    }
}
