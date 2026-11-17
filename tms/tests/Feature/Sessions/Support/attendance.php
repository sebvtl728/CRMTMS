<?php

/*
 * Helpers of the attendance tests (présences, heures mensuelles, clôture,
 * heures réalisées), included with require_once after Support/lot2a.php and
 * Support/planning.php.
 */

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A trainee enrolled on the session (a dossier of his own).
 *
 * @param  array<string, mixed>  $extra  entered_on, planned_hours, adaptation_needs, notes…
 */
function atEnrol(TrainingSession $session, string $lastName = 'Durand', array $extra = []): Enrolment
{
    return l2Enrol(l2Dossier(), $session, makePerson($lastName, ['first_name' => 'Léa']), $extra);
}

/**
 * Who manages the séances and the attendance of every session, and closes months.
 */
function atManager(): User
{
    return userWithPermissions([
        Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE,
        Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE,
    ]);
}

/**
 * A module of the session dated as an interval (no séances, hours per month).
 */
function atIntervalModule(TrainingSession $session, int $plannedMinutes = 600, string $title = 'Atelier en autonomie', int $position = 2): SessionModule
{
    return SessionModule::query()->create([
        'training_session_id' => $session->id, 'position' => $position, 'title' => $title, 'planned_minutes' => $plannedMinutes,
        'dating_mode' => 'interval', 'starts_on' => $session->starts_on->toDateString(), 'ends_on' => $session->ends_on->toDateString(),
    ]);
}

/**
 * Gotenberg answers a recognisable PDF; the HTML it received is read back with sheetHtml().
 */
function fakeSheetGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 feuille', 200, ['Content-Type' => 'application/pdf'])]);
}

function sheetHtml(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}
