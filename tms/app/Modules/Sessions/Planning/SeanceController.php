<?php

namespace App\Modules\Sessions\Planning;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Séances of the « Planning » tab of a session: add, change, cancel,
 * delete one; the alerts of a séance being typed; the « Générer les
 * séances » assistant (preview, then generate). sessions.seances.manage
 * with its scope on the session (own / das / all), else 403; a séance or a
 * module of another session is a 404. Alerts come back as a warning,
 * never as an error.
 */
class SeanceController extends Controller
{
    public function __construct(private readonly Seances $seances, private readonly SeanceGenerator $generator) {}

    public function store(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        self::authorizeManage($request, $trainingSession);

        $seance = $this->seances->create($trainingSession, $request->all());

        return self::back($trainingSession, 'Séance ajoutée.', Conflicts::for($seance));
    }

    public function update(Request $request, TrainingSession $trainingSession, Seance $seance): RedirectResponse
    {
        self::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $seance);

        $seance = $this->seances->reschedule($seance, $request->all());

        return self::back($trainingSession, 'Séance enregistrée.', Conflicts::for($seance));
    }

    public function cancel(Request $request, TrainingSession $trainingSession, Seance $seance): RedirectResponse
    {
        self::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $seance);
        $reason = $request->input('reason');

        $this->seances->cancel($seance, is_string($reason) ? $reason : '');

        return self::back($trainingSession, 'Séance annulée.');
    }

    public function destroy(Request $request, TrainingSession $trainingSession, Seance $seance): RedirectResponse
    {
        self::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $seance);

        $this->seances->delete($seance);

        return self::back($trainingSession, 'Séance supprimée.');
    }

    /**
     * Alerts of a séance being typed (form of a séance), without writing.
     */
    public function check(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        self::authorizeManage($request, $trainingSession);
        $seanceId = $request->input('seance_id');
        // The séance being changed (of this session): its current trainer and room stay accepted.
        $current = is_string($seanceId) && Str::isUuid($seanceId)
            ? Seance::query()->where('training_session_id', $trainingSession->id)->find($seanceId)
            : null;
        $values = Seances::validated($trainingSession, $request->all(), $current);
        $seanceId = $current?->id;

        return response()->json(['alerts' => array_map(fn (Conflict $c) => $c->toArray(), Conflicts::for([
            ...$values,
            'training_session_id' => $trainingSession->id,
            'seance_id' => $seanceId,
        ]))]);
    }

    public function preview(Request $request, TrainingSession $trainingSession, SessionModule $sessionModule): JsonResponse
    {
        self::authorizeManage($request, $trainingSession);
        self::assertModuleOf($trainingSession, $sessionModule);
        self::assertSeancesMode($sessionModule);

        return response()->json($this->generator->preview($sessionModule, GenerationRequest::fromInput($request->all(), $sessionModule))->toArray());
    }

    public function generate(Request $request, TrainingSession $trainingSession, SessionModule $sessionModule): RedirectResponse
    {
        self::authorizeManage($request, $trainingSession);
        self::assertModuleOf($trainingSession, $sessionModule);
        self::assertSeancesMode($sessionModule);

        $created = $this->generator->generate($sessionModule, GenerationRequest::fromInput($request->all(), $sessionModule));
        $count = count($created);

        return to_route('sessions.show', $trainingSession)->with('status', match ($count) {
            0 => 'Aucune séance créée : toutes existent déjà ou tombent sur des jours exclus.',
            1 => '1 séance créée.',
            default => "{$count} séances créées.",
        });
    }

    /**
     * sessions.seances.manage (route) and its scope on the session.
     */
    public static function authorizeManage(Request $request, TrainingSession $session): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && SessionsVisibility::allows($user, Permissions::SEANCES_MANAGE, $session), 403);
    }

    private static function assertOf(TrainingSession $session, Seance $seance): void
    {
        abort_unless($seance->training_session_id === $session->id, 404);
    }

    private static function assertModuleOf(TrainingSession $session, SessionModule $module): void
    {
        abort_unless($module->training_session_id === $session->id, 404);
    }

    private static function assertSeancesMode(SessionModule $module): void
    {
        if ($module->dating_mode !== DatingMode::Seances->value) {
            throw new UserFacingHttpException(409, SeanceGenerator::INTERVAL);
        }
    }

    /**
     * Back to the session, the alerts of the séance as a warning.
     *
     * @param  list<Conflict>  $alerts
     */
    private static function back(TrainingSession $session, string $status, array $alerts = []): RedirectResponse
    {
        $warning = $alerts === [] ? null : implode(' ', array_map(fn (Conflict $c) => $c->message, $alerts));

        return to_route('sessions.show', $session)->with('status', $status)->with('warning', $warning);
    }
}
