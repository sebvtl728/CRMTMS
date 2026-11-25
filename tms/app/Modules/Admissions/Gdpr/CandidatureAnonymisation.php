<?php

namespace App\Modules\Admissions\Gdpr;

use App\Modules\Admissions\Booklet\BookletAnonymisation;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFiles;
use App\Modules\Admissions\OnlineTests\OnlineAttempt;
use App\Modules\Admissions\Positioning\PositioningSending;
use Illuminate\Support\Facades\DB;

/**
 * Anonymisation of candidatures: personal, sensitive and custom values and
 * every file and the positioning e-mails (links closed) go; the number, stage, training, dates and DAS stay (statistics).
 * Called for an anonymised person (Crm\Public\FicheLinks) and by the
 * two-year purge of rejected and withdrawn candidatures.
 */
final class CandidatureAnonymisation
{
    public function anonymise(Candidature $candidature): void
    {
        if ($candidature->anonymised_at !== null) {
            return;
        }
        DB::transaction(function () use ($candidature): void {
            app(CandidatureFiles::class)->purge($candidature);
            // The booklet follow-up: texts, drawings, names and e-mails go; the marks stay.
            BookletAnonymisation::forCandidature($candidature->id);
            // The answers and traces of the online tests go.
            OnlineAttempt::query()->where('candidature_id', $candidature->id)->toBase()
                ->update(['answers' => '{}', 'events' => '[]', 'updated_at' => now()]);
            // The positioning e-mails go; a link still open closes.
            PositioningSending::query()->where('candidature_id', $candidature->id)->toBase()
                ->update(['email' => null, 'subject' => null, 'message' => null, 'token_encrypted' => null, 'revoked_at' => DB::raw('coalesce(revoked_at, now())'), 'updated_at' => now()]);
            $candidature->forceFill([
                'legal_representative' => null,
                'rqth' => false,
                'adaptation_needs' => null,
                'interview_report' => null,
                'positioning_result' => null,
                'france_travail_id' => null,
                'last_diploma' => null,
                'custom_values' => [],
                'anonymised_at' => now(),
            ])->save();
        });
    }

    /**
     * Every candidature (trashed ones included) of these people.
     *
     * @param  list<string>  $personIds
     */
    public function forPeople(array $personIds): void
    {
        Candidature::withTrashed()->whereIn('person_id', $personIds)->whereNull('anonymised_at')->get()
            ->each(fn (Candidature $c) => $this->anonymise($c));
        // A tutor of a booklet: his name, drawing and e-mails go.
        BookletAnonymisation::forTutors($personIds);
    }
}
