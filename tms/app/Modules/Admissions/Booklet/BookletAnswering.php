<?php

namespace App\Modules\Admissions\Booklet;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Signatures\SignatureDrawing;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Public\Fiches;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the apprentice and the tutor do by their monthly link: read their
 * page, save what they type (merged, as they type), receive a code by
 * e-mail (HMAC, 10 minutes, 5 tries, one a minute) and sign with a drawing.
 * The apprentice's signature covers every page of the month and sends the
 * tutor his link; the tutor's covers the company page. A signature closes
 * the link. The tutor never sees the centre page nor the candidature.
 */
final class BookletAnswering
{
    public const CODE_MINUTES = 10;

    public const CODE_TRIES = 5;

    public const WAIT = 'Patientez une minute avant de demander un nouveau code.';

    public const NO_CODE = 'Demandez d\'abord votre code.';

    public const EXPIRED = 'Ce code a expiré : demandez-en un nouveau.';

    public const TOO_MANY = 'Trop d\'essais : demandez un nouveau code.';

    public const WRONG = 'Code incorrect.';

    public const MAIL_FAILED = 'Le code n\'a pas pu être envoyé : réessayez dans un instant.';

    public const SIGNED = 'Cette page est déjà signée.';

    public const TUTOR_SIGNED = 'Le tuteur a déjà signé cette page : elle ne se modifie plus.';

    public function __construct(
        private readonly Fiches $fiches,
        private readonly Trainings $trainings,
        private readonly BookletGrid $grid,
        private readonly BookletLinks $links,
        private readonly MailTemplates $templates,
    ) {}

    /**
     * The props of the public page.
     *
     * @return array<string, mixed>
     */
    public function view(BookletLink $link): array
    {
        $candidature = Candidature::query()->findOrFail($link->candidature_id);
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->firstOrFail();
        $apprentice = $this->fiches->contactCard('person', $candidature->person_id);
        $employer = $this->fiches->contactCard('organisation', $contract->employer_organisation_id);
        $month = $link->month->format('Y-m');
        $isTutor = $link->signer === BookletLink::TUTOR;
        $rows = BookletMonth::query()->where('candidature_id', $candidature->id)->where('month', $link->month->toDateString())->get()->keyBy('place');
        $places = $isTutor ? [BookletMonth::COMPANY] : BookletMonths::placesOf($candidature, $month);
        $initial = mb_substr((string) ($apprentice['last_name'] ?? ''), 0, 1);

        return [
            'state' => 'open',
            'signer' => $link->signer,
            'month_label' => BookletMonths::label($link->month),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
            'apprentice' => $isTutor
                ? trim(($apprentice['first_name'] ?? '').' '.($initial === '' ? '' : mb_strtoupper($initial).'.'))
                : (string) ($apprentice['label'] ?? ''),
            'employer' => $employer['label'] ?? null,
            'training' => $candidature->training_id === null ? null : $this->trainings->find($candidature->training_id)?->title,
            'expires_on' => $link->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            'pages' => array_map(fn (string $place) => [
                'place' => $place,
                'entries' => self::weeks($rows->get($place)?->apprentice_entries),
                'observations' => $isTutor ? self::weeks($rows->get($place)?->observations) : null,
            ], $places),
            'grid' => $isTutor ? array_map(fn (array $at) => [
                'title' => $at['title'],
                'competences' => array_map(fn (array $cp) => [
                    'title' => $cp['title'],
                    'skills' => array_map(fn (array $s) => ['key' => $s['key'], 'title' => $s['title'], 'level' => $s['company']], $cp['skills']),
                ], $at['competences']),
            ], $this->grid->tree($candidature)['types']) : null,
        ];
    }

    /**
     * Saves what was typed (only the keys sent): the apprentice's weeks on
     * each page of the month, the tutor's observations and company marks.
     *
     * @param  array<string, mixed>  $data  entries.<place>.<week> | observations.<week>, marks: list<{key, level}>
     *
     * @throws ValidationException
     */
    public function save(BookletLink $link, array $data): void
    {
        $candidature = Candidature::query()->findOrFail($link->candidature_id);
        DB::transaction(function () use ($link, $data, $candidature): void {
            $locked = $this->lockOpen($link);
            if ($locked->signer === BookletLink::APPRENTICE) {
                $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
                foreach (BookletMonths::placesOf($candidature, $locked->month->format('Y-m')) as $place) {
                    if (is_array($entries[$place] ?? null)) {
                        $this->merge($locked, $place, 'apprentice_entries', $entries[$place]);
                    }
                }

                return;
            }
            if (is_array($data['observations'] ?? null)) {
                $this->merge($locked, BookletMonth::COMPANY, 'observations', $data['observations']);
            }
            foreach (is_array($data['marks'] ?? null) ? $data['marks'] : [] as $mark) {
                if (! is_array($mark) || ! is_string($mark['key'] ?? null)) {
                    throw ValidationException::withMessages(['marks' => 'Évaluation invalide.']);
                }
                $level = is_string($mark['level'] ?? null) && $mark['level'] !== '' ? $mark['level'] : null;
                $this->grid->set($candidature, $mark['key'], BookletGrid::COMPANY, $level, null);
            }
        });
    }

    public function sendCode(BookletLink $link): void
    {
        $code = DB::transaction(function () use ($link): string {
            $locked = $this->lockOpen($link);
            if ($locked->code_sent_at !== null && $locked->code_sent_at->addMinute()->isFuture()) {
                throw new UserFacingHttpException(429, self::WAIT);
            }
            $code = sprintf('%06d', random_int(0, 999999));
            $locked->forceFill([
                'code_hash' => self::codeHash($locked, $code),
                'code_expires_at' => CarbonImmutable::now()->addMinutes(self::CODE_MINUTES),
                'code_attempts' => 0,
                'code_sent_at' => CarbonImmutable::now(),
            ])->save();

            return $code;
        });
        $text = $this->templates->render(BookletMail::CODE, [
            'code' => $code,
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
        ]);
        try {
            TenantMailer::for(null)->to((string) $link->email)->send(new BookletMail($text['subject'], $text['body']));
        } catch (Throwable $e) {
            Log::warning('Booklet code e-mail failed.', ['error' => $e::class]);

            throw new UserFacingHttpException(503, self::MAIL_FAILED);
        }
    }

    /**
     * Checks the drawing (a refused drawing costs no try), then the code,
     * then signs the part of the signer and closes the link; the apprentice's
     * signature sends the tutor his link (when he has none open and an
     * e-mail).
     *
     * @throws ValidationException
     */
    public function sign(BookletLink $link, string $code, string $drawing, string $ip, string $agent): void
    {
        $png = SignatureDrawing::decode($drawing);
        $candidature = Candidature::query()->findOrFail($link->candidature_id);
        $signed = DB::transaction(function () use ($link, $code, $png, $ip, $agent, $candidature): bool {
            $locked = $this->lockOpen($link);
            if ($locked->code_hash === null) {
                throw ValidationException::withMessages(['code' => self::NO_CODE]);
            }
            if ($locked->code_attempts >= self::CODE_TRIES) {
                throw ValidationException::withMessages(['code' => self::TOO_MANY]);
            }
            if ($locked->code_expires_at === null || ! $locked->code_expires_at->isFuture()) {
                throw ValidationException::withMessages(['code' => self::EXPIRED]);
            }
            if (! hash_equals((string) $locked->code_hash, self::codeHash($locked, trim($code)))) {
                // The try is counted (committed), then refused below.
                $locked->forceFill(['code_attempts' => $locked->code_attempts + 1])->save();

                return false;
            }

            $signer = $locked->signer;
            $path = TenantStorage::path('booklet_signatures', $locked->id.'.png');
            Storage::disk(TenantStorage::DISK)->put($path, $png);
            $places = $signer === BookletLink::TUTOR ? [BookletMonth::COMPANY] : BookletMonths::placesOf($candidature, $locked->month->format('Y-m'));
            foreach ($places as $place) {
                $page = BookletMonth::query()->lockForUpdate()->firstOrCreate(['candidature_id' => $candidature->id, 'month' => $locked->month->toDateString(), 'place' => $place]);
                if ($page->signedBy($signer)) {
                    throw new UserFacingHttpException(409, self::SIGNED);
                }
                $page->forceFill([
                    $signer.'_signed_at' => CarbonImmutable::now(),
                    $signer.'_signature_path' => $path,
                    $signer.'_signer_name' => $locked->signer_name,
                    $signer.'_ip' => mb_substr($ip, 0, 45),
                    $signer.'_agent' => mb_substr($agent, 0, 500),
                    $signer.'_link_id' => $locked->id,
                ])->save();
            }
            $locked->forceFill(['closed_at' => CarbonImmutable::now(), 'code_hash' => null])->save();

            return true;
        });
        if (! $signed) {
            throw ValidationException::withMessages(['code' => self::WRONG]);
        }
        if ($link->signer === BookletLink::APPRENTICE) {
            $this->chainTutor($candidature, $link->month->format('Y-m'));
        }
    }

    /** After the apprentice: the tutor's link, unless he has one open, signed already, or has no e-mail. */
    private function chainTutor(Candidature $candidature, string $month): void
    {
        if (BookletLinks::openFor($candidature, $month, BookletLink::TUTOR) !== null) {
            return;
        }
        try {
            $this->links->send($candidature, $month, BookletLink::TUTOR, null);
        } catch (UserFacingHttpException) {
            // No tutor, no e-mail or already signed: the trainer sees it on the booklet.
        }
    }

    /** The link locked, still open; 409 otherwise (signed meanwhile, closed, expired). */
    private function lockOpen(BookletLink $link): BookletLink
    {
        $locked = BookletLink::query()->lockForUpdate()->findOrFail($link->id);
        if (! BookletMonths::isOpen($locked)) {
            throw new UserFacingHttpException(409, self::SIGNED);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $weeks
     *
     * @throws ValidationException
     */
    private function merge(BookletLink $link, string $place, string $column, array $weeks): void
    {
        $page = BookletMonth::query()->lockForUpdate()->firstOrCreate(['candidature_id' => $link->candidature_id, 'month' => $link->month->toDateString(), 'place' => $place]);
        if ($page->signedBy($link->signer)) {
            throw new UserFacingHttpException(409, self::SIGNED);
        }
        // What the tutor signed (the apprentice's weeks he read) never changes afterwards.
        if ($link->signer === BookletLink::APPRENTICE && $page->signedBy(BookletLink::TUTOR)) {
            throw new UserFacingHttpException(409, self::TUTOR_SIGNED);
        }
        $clean = BookletMonths::cleanWeeks($weeks, $column === 'observations' ? 'observations' : "entries.{$place}");
        $current = is_array($page->{$column}) ? $page->{$column} : [];
        foreach (array_keys($weeks) as $week) {
            if (array_key_exists($week, $clean)) {
                $current[$week] = $clean[$week];
            }
        }
        $page->forceFill([$column => $current])->save();
    }

    /**
     * @param  array<string, mixed>|null  $weeks
     * @return array<string, ?string>
     */
    private static function weeks(?array $weeks): array
    {
        $out = [];
        foreach (array_keys(BookletMonth::WEEKS) as $week) {
            $out[$week] = is_string($weeks[$week] ?? null) ? $weeks[$week] : null;
        }

        return $out;
    }

    private static function codeHash(BookletLink $link, string $code): string
    {
        return hash_hmac('sha256', $link->id.'|'.$code, (string) config('app.key'));
    }
}
