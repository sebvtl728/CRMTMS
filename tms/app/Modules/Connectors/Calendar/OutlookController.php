<?php

namespace App\Modules\Connectors\Calendar;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\CalendarEvents;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * « Connecter Outlook » in « Mon agenda »: OAuth 2.0 authorization code +
 * PKCE with Microsoft (the redirect URI is this organisme's address), the
 * tokens stored encrypted on the user's link; and « Déconnecter », which
 * deletes them (nothing more is pushed).
 */
class OutlookController extends Controller
{
    public const RECONNECT_MESSAGE = 'Outlook est à reconnecter : Microsoft a refusé l\'accès à votre agenda (mot de passe changé, accès retiré ou connexion trop ancienne). Vos rendez-vous n\'y sont plus envoyés : cliquez sur « Reconnecter Outlook ».';

    public function __construct(private readonly GraphClient $graph, private readonly AuditRecorder $audit) {}

    /**
     * Props of the Outlook section of « Mon agenda ».
     *
     * @return array<string, mixed>
     */
    public static function props(User $user): array
    {
        $link = CalendarLink::for($user->id);

        return [
            'available' => Microsoft365Connector::activeConfig() !== null,
            'status' => $link === null ? 'none' : ($link->isActive() ? 'active' : 'reconnect'),
            'connected_at' => $link?->connected_at?->toIso8601String(),
            'message' => $link !== null && ! $link->isActive() ? self::RECONNECT_MESSAGE : null,
        ];
    }

    public function connect(Request $request): Response
    {
        $user = $this->user($request);
        $config = Microsoft365Connector::activeConfig();
        if ($config === null) {
            return $this->back('Outlook n\'est pas configuré pour votre organisme : contactez votre administrateur.');
        }

        ['state' => $state, 'challenge' => $challenge] = OutlookOAuthState::issue($request, $user);

        return Inertia::location($this->graph->authorizeUrl($config, $this->redirectUri(), $state, $challenge));
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $verifier = OutlookOAuthState::consume($request, $user, $request->query('state'));
        $code = $request->query('code');

        if ($request->query('error') !== null) {
            return $this->back('Connexion à Outlook annulée.');
        }
        $config = Microsoft365Connector::activeConfig();
        if ($verifier === null || ! is_string($code) || $code === '' || $config === null) {
            return $this->back('Lien de connexion invalide ou expiré : cliquez de nouveau sur « Connecter Outlook ».');
        }

        try {
            $tokens = $this->graph->exchangeCode($config, $code, $verifier, $this->redirectUri());
        } catch (Throwable) {
            return $this->back('Microsoft n\'a pas accepté la connexion. Réessayez, ou contactez votre administrateur.');
        }

        $link = CalendarLink::for($user->id) ?? new CalendarLink(['user_id' => $user->id]);
        $link->forceFill(['connected_at' => now()]);
        $link->storeTokens($tokens);
        $this->audit->record('calendar.outlook_connected', $user);

        // What is already planned goes to Outlook too.
        $now = CarbonImmutable::now();
        foreach (CalendarEvents::for($user, $now->subDay(), $now->addDays(IcalFeed::DAYS_AFTER)) as $event) {
            PushActivityToOutlook::dispatch($event->id, url('/'));
        }

        return to_route('calendar.settings')->with('status', 'Outlook connecté : vos rendez-vous et appels planifiés y seront envoyés.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $link = CalendarLink::for($user->id);
        if ($link !== null) {
            $link->delete();
            $this->audit->record('calendar.outlook_disconnected', $user);
        }

        return to_route('calendar.settings')->with('status', 'Outlook déconnecté : plus rien n\'y sera envoyé. Les évènements déjà créés restent dans votre agenda.');
    }

    /** On this organisme's address (declared in the Azure application). */
    private function redirectUri(): string
    {
        return route('calendar.outlook.callback');
    }

    private function back(string $error): RedirectResponse
    {
        return to_route('calendar.settings')->with('outlook_error', $error);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
