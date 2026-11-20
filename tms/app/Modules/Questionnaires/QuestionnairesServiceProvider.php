<?php

namespace App\Modules\Questionnaires;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Questionnaires\Campaigns\SurveyMailTemplates;
use App\Modules\Questionnaires\Gdpr\SurveyPersonData;
use App\Modules\Questionnaires\Results\SessionSurveysBlock;
use App\Modules\Questionnaires\Results\SurveyMeasures;
use App\Modules\Questionnaires\Results\SurveySatisfaction;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use App\Modules\Sessions\Public\EnrolmentSatisfaction;
use App\Modules\Sessions\Public\SessionBlocks;
use App\Modules\Sessions\Public\SessionCards;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
    /**
     * Public pages per minute and address: a whole room answering through
     * the QR code shares the Wi-Fi of the venue (about 5 requests each).
     */
    public const PUBLIC_PER_MINUTE = 150;

    public function register(): void
    {
        CorePermissions::registerModule('questionnaires', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP);

        MorphAliases::register([
            SurveyTemplate::MORPH_ALIAS => SurveyTemplate::class,
            SurveyCampaign::MORPH_ALIAS => SurveyCampaign::class,
        ]);
    }

    public function boot(): void
    {
        SurveyMailTemplates::register();
        SessionBlocks::register('questionnaires.session', fn (string $sessionId, User $viewer) => SessionSurveysBlock::for($sessionId, $viewer));
        EnrolmentSatisfaction::register(fn (array $enrolmentIds) => SurveySatisfaction::forEnrolments($enrolmentIds));
        SurveyMeasures::register();
        FicheLinks::registerLinked('questionnaires.invitations', fn (string $type, string $id): bool => SurveyPersonData::hasLinked($type, $id));
        FicheLinks::registerMerge('questionnaires.invitations', fn (string $type, string $keepId, string $dropId) => SurveyPersonData::merge($type, $keepId, $dropId));
        FicheLinks::registerAnonymisation('questionnaires.invitations', fn (array $personIds) => SurveyPersonData::anonymise($personIds));
        FicheLinks::registerExport('questionnaires', fn (string $personId): array => SurveyPersonData::export($personId), [Permissions::VIEW]);
        SessionCards::register('questionnaires', fn (array $sessionIds, User $viewer) => SessionSurveysBlock::cards($sessionIds, $viewer));
        RateLimiter::for('survey-launch', fn (Request $request) => Limit::perMinute(20)->by('survey-launch:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('survey-public', fn (Request $request) => Limit::perMinute(self::PUBLIC_PER_MINUTE)->by('survey-public:'.$request->ip()));
        Route::pattern('surveyTemplate', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
    }
}
