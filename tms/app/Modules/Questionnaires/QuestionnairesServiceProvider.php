<?php

namespace App\Modules\Questionnaires;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use Illuminate\Support\ServiceProvider;

/**
 * Module Questionnaires (0.11): satisfaction questionnaires « à chaud » and
 * « à froid » of the sessions (trainees, optionally the entreprise), answered
 * without an account through a personal link or the QR code of the session;
 * results per session and overall, Qualiopi measures (indicators 30 and 2).
 * Reads Sessions and the CRM through their public services only.
 */
class QuestionnairesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        CorePermissions::registerModule('questionnaires', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP);

        MorphAliases::register([
            SurveyTemplate::MORPH_ALIAS => SurveyTemplate::class,
            SurveyCampaign::MORPH_ALIAS => SurveyCampaign::class,
        ]);
    }

    public function boot(): void {}
}
