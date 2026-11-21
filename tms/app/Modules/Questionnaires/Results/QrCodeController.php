<?php

namespace App\Modules\Questionnaires\Results;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Core\Users\User;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Sessions\Public\Surveys;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The QR code of the hot questionnaire of a session, full page and
 * printable, to show in the room on the last day (who sees the session).
 */
class QrCodeController extends Controller
{
    public function show(Request $request, string $sessionId): Response
    {
        /** @var User $user */
        $user = $request->user();
        $session = Surveys::session($sessionId);
        abort_unless($session !== null && Surveys::allows($user, $sessionId, false), 404);
        $campaign = SurveyCampaign::query()->where('training_session_id', $sessionId)
            ->where('kind', SurveyCampaign::HOT)->where('audience', SurveyCampaign::TRAINEE)->first();
        abort_if($campaign === null || $campaign->qr_token_encrypted === null, 404);
        $url = TenantUrl::route('survey.qr', Crypt::decryptString($campaign->qr_token_encrypted));

        return Inertia::render('sessions/questionnaire-qr', [
            'session' => $session['label'],
            'session_id' => $sessionId,
            'title' => $campaign->title,
            'url' => $url,
            'qr' => self::svg($url),
            'closes_on' => $campaign->closes_on->format('d/m/Y'),
        ]);
    }

    /** The QR code of $url as an SVG data URI. */
    public static function svg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(480, 2, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
