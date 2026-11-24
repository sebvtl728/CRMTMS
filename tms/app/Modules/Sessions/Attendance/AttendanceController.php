<?php

namespace App\Modules\Sessions\Attendance;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Signing\SlotRows;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Attendance screens (JSON):
 *  - a séance's grid: sessions.attendance.manage within its scope on the
 *    séance - « Ses séances » for a trainer (his séances only), the
 *    sessions of the scope for a manager; out of scope: 404 (the séance is
 *    bound within sessions.seances.view, 404 too);
 *  - the « Présences » tab of a session and its monthly hours:
 *    sessions.attendance.manage on the session (a trainer never, 403);
 *  - closing and reopening a month: sessions.attendance.close on the
 *    session (403 otherwise).
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly Attendances $attendances,
        private readonly MonthlyHours $monthlyHours,
        private readonly Closures $closures,
    ) {}

    public function grid(Request $request, Seance $seance): JsonResponse
    {
        self::authorizeSeance($request, $seance);

        return self::json([...AttendanceRows::grid($seance), ...AttendanceSheetController::list($seance, self::user($request)), ...self::proofs($seance, self::user($request)), 'slots' => SlotRows::forSeance($seance, self::user($request))]);
    }

    public function record(Request $request, Seance $seance): JsonResponse
    {
        self::authorizeSeance($request, $seance);
        $minutes = $request->input('minutes');
        if ($minutes !== null && $minutes !== '' && filter_var($minutes, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages(['minutes' => 'Indiquez un nombre entier de minutes.']);
        }
        $this->attendances->record(
            $seance,
            self::string($request->input('enrolment_id')),
            self::string($request->input('status')),
            $minutes === null || $minutes === '' ? null : (int) $minutes,
            is_string($reason = $request->input('reason')) ? $reason : null,
        );

        return self::json(self::rows($request, $seance->refresh()));
    }

    public function allPresent(Request $request, Seance $seance): JsonResponse
    {
        self::authorizeSeance($request, $seance);
        $count = $this->attendances->allPresent($seance);

        return self::json(['count' => $count, ...self::rows($request, $seance->refresh())]);
    }

    public function clear(Request $request, Seance $seance, string $enrolmentId): JsonResponse
    {
        self::authorizeSeance($request, $seance);
        $this->attendances->clear($seance, $enrolmentId);

        return self::json(self::rows($request, $seance->refresh()));
    }

    /**
     * The grid with the half-days of the day and their signatures: the dialog
     * replaces what it shows by each answer, so the signatures stay in it.
     *
     * @return array<string, mixed>
     */
    private static function rows(Request $request, Seance $seance): array
    {
        return [...AttendanceRows::grid($seance), 'slots' => SlotRows::forSeance($seance, self::user($request))];
    }

    /**
     * The « Présences » tab of a session, for ?month=2026-09 (default: the
     * current month within the session).
     */
    public function session(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = self::user($request);
        abort_unless(SessionsVisibility::allows($user, Permissions::ATTENDANCE_MANAGE, $trainingSession)
            || SessionsVisibility::allows($user, Permissions::ATTENDANCE_CLOSE, $trainingSession), 403);
        $month = $request->query('month');

        return self::json(AttendanceRows::session($trainingSession, $user, is_string($month) ? $month : null));
    }

    public function monthly(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = self::user($request);
        abort_if(Scopes::of($user, Permissions::ATTENDANCE_MANAGE) === AccessScope::Trainer
            || ! SessionsVisibility::allows($user, Permissions::ATTENDANCE_MANAGE, $trainingSession), 403);
        $moduleId = self::string($request->input('session_module_id'));
        $module = SessionModule::query()->where('training_session_id', $trainingSession->id)->whereKey($moduleId)->first();
        if ($module === null) {
            throw ValidationException::withMessages(['session_module_id' => 'Module introuvable.']);
        }
        $month = self::string($request->input('month'));
        $enrolmentId = self::string($request->input('enrolment_id'));
        if ($request->boolean('clear')) {
            $this->monthlyHours->clear($enrolmentId, $module, $month);
        } else {
            $minutes = $request->input('minutes');
            if (filter_var($minutes, FILTER_VALIDATE_INT) === false) {
                throw ValidationException::withMessages(['minutes' => 'Indiquez un nombre entier de minutes.']);
            }
            $trainer = $request->input('trainer_id');
            $this->monthlyHours->record($enrolmentId, $module, $month, (int) $minutes, is_string($trainer) ? $trainer : null);
        }

        return self::json(AttendanceRows::session($trainingSession, $user, $month));
    }

    public function close(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = self::authorizeClose($request, $trainingSession);
        $month = self::string($request->input('month'));
        $this->closures->close($trainingSession, $month);

        return self::json(AttendanceRows::session($trainingSession, $user, $month));
    }

    public function reopen(Request $request, TrainingSession $trainingSession): JsonResponse
    {
        $user = self::authorizeClose($request, $trainingSession);
        $month = self::string($request->input('month'));
        $this->closures->reopen($trainingSession, $month, self::string($request->input('reason')));

        return self::json(AttendanceRows::session($trainingSession, $user, $month));
    }

    /**
     * sessions.attendance.manage (route) within its scope on the séance: 404 otherwise.
     */
    /**
     * The proofs of absence of the séance, for managers only.
     *
     * @return array{proofs: array<string, list<array<string, mixed>>>, can_manage_proofs: bool}
     */
    private static function proofs(Seance $seance, User $user): array
    {
        $can = AttendanceProofController::canManage($user);

        return ['proofs' => $can ? AttendanceProofs::forSeance($seance) : [], 'can_manage_proofs' => $can];
    }

    private static function authorizeSeance(Request $request, Seance $seance): void
    {
        $reached = SessionsVisibility::seances(Seance::query()->whereKey($seance->id), self::user($request), Permissions::ATTENDANCE_MANAGE)->exists();
        abort_unless($reached, 404);
    }

    private static function authorizeClose(Request $request, TrainingSession $session): User
    {
        $user = self::user($request);
        abort_unless(SessionsVisibility::allows($user, Permissions::ATTENDANCE_CLOSE, $session), 403);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
