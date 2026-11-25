<?php

namespace App\Modules\Admissions\Booklet;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use Carbon\CarbonImmutable;

/**
 * The automation of the apprenticeship booklet (spec 2026-11-24, B3; off by
 * default, Administration › Automatismes): each day of a month, the link of
 * the past month to the apprentice of every contract covering it (unless
 * his part is signed, online or on paper, or a link of his is open), once
 * per candidature and month; then reminders at J+7 and J+14 of the first
 * link of a part (apprentice or tutor) still unsigned with a link open: a
 * new link, the former one closed. Never twice (AutomationSends); a sending
 * that cannot leave (no e-mail) is tried again the next days.
 */
final class BookletAutomation
{
    public const REMINDER_DAYS = [1 => 7, 2 => 14];

    public function __construct(private readonly BookletLinks $links) {}

    /** The number of links sent. */
    public function run(CarbonImmutable $today): int
    {
        if (! AutomationSettings::booklet()) {
            return 0;
        }

        return $this->monthly($today) + $this->reminders($today);
    }

    private function monthly(CarbonImmutable $today): int
    {
        $month = $today->startOfMonth()->subMonth();
        $key = $month->format('Y-m');
        $candidatureIds = AlternanceContract::query()
            ->where('starts_on', '<=', $month->endOfMonth()->toDateString())
            ->where('ends_on', '>=', $month->toDateString())
            ->pluck('candidature_id');
        $sent = 0;
        foreach (Candidature::query()->whereKey($candidatureIds)->whereNull('anonymised_at')->get() as $candidature) {
            if ($this->signed($candidature, $month, BookletLink::APPRENTICE)
                || BookletLinks::openFor($candidature, $key, BookletLink::APPRENTICE) !== null) {
                continue;
            }
            $sent += $this->send($candidature, $key, BookletLink::APPRENTICE, AutomationSends::BOOKLET, $key.':'.BookletLink::APPRENTICE);
        }

        return $sent;
    }

    private function reminders(CarbonImmutable $today): int
    {
        $sent = 0;
        $open = BookletLink::query()->whereNull('closed_at')->where('expires_at', '>', now())->get();
        foreach ($open as $link) {
            $candidature = Candidature::query()->whereNull('anonymised_at')->find($link->candidature_id);
            if ($candidature === null || $this->signed($candidature, $link->month, $link->signer)) {
                continue;
            }
            $first = BookletLink::query()->where('candidature_id', $link->candidature_id)->where('month', $link->month->toDateString())
                ->where('signer', $link->signer)->min('created_at');
            $days = (int) CarbonImmutable::parse((string) $first)->setTimezone('Europe/Paris')->startOfDay()->diffInDays($today, false);
            $key = $link->month->format('Y-m');
            // The reminders due and not sent yet: one e-mail, every due reminder claimed with it.
            $due = array_keys(array_filter(self::REMINDER_DAYS, fn (int $after) => $days >= $after));
            $claimed = array_values(array_filter($due, fn (int $n) => AutomationSends::claim(AutomationSends::BOOKLET_REMINDER, $candidature->id, $key.':'.$link->signer.':'.$n)));
            if ($claimed === []) {
                continue;
            }
            try {
                $this->links->send($candidature, $key, $link->signer, null);
                $sent++;
            } catch (UserFacingHttpException) {
                foreach ($claimed as $n) {
                    AutomationSends::release(AutomationSends::BOOKLET_REMINDER, $candidature->id, $key.':'.$link->signer.':'.$n);
                }
            }
        }

        return $sent;
    }

    private function send(Candidature $candidature, string $month, string $signer, string $kind, string $occurrence): int
    {
        if (! AutomationSends::claim($kind, $candidature->id, $occurrence)) {
            return 0;
        }
        try {
            $this->links->send($candidature, $month, $signer, null);

            return 1;
        } catch (UserFacingHttpException) {
            // No e-mail, no tutor, out of the contract: tried again the next days.
            AutomationSends::release($kind, $candidature->id, $occurrence);

            return 0;
        }
    }

    /** The part of $signer for the month is signed (online or on paper) on the company page. */
    private function signed(Candidature $candidature, CarbonImmutable $month, string $signer): bool
    {
        $page = BookletMonth::query()->where('candidature_id', $candidature->id)->where('month', $month->toDateString())
            ->where('place', BookletMonth::COMPANY)->first();

        return $page !== null && $page->signedBy($signer);
    }
}
