<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotRows;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Public\RealisedHours;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The individual follow-up of one trainee of a dossier (read by the page of
 * an admitted candidate, through Public\Admission::followUp): his séances
 * (between his entry and leaving days, Paris days), then - for who manages
 * the attendance of the session on a scope other than « Ses séances », or
 * closes its months - his attendance per séance, his realised hours and his
 * signatures per half-day. Never another trainee; a fixed number of queries.
 *
 * Rights: sessions.sessions.view reaching the session, else null; the
 * attendance keys (seances.*.attendance, hours, signatures) are absent
 * without the attendance right. The actions (can_record, can_confirm,
 * can_send_link, the e-mail to prefill) are offered to a manager of the
 * attendance (« Saisir les présences » outside « Ses séances », reaching
 * the session), never on a closed month; they go through the endpoints of
 * the session's screens, which check again.
 */
final class TraineeFollowUp
{
    /**
     * @return array<string, mixed>|null
     */
    public static function of(string $dossierId, string $personId, User $user): ?array
    {
        if (! Str::isUuid($dossierId) || ! Str::isUuid($personId)) {
            return null;
        }
        $enrolment = Enrolment::query()->where('dossier_id', $dossierId)->where('person_id', $personId)
            ->whereNotIn('status', [EnrolmentStatus::Cancelled->value, EnrolmentStatus::Waitlisted->value])
            ->orderByDesc('created_at')->first();
        $session = $enrolment === null ? null : TrainingSession::query()->find($enrolment->training_session_id);
        if ($enrolment === null || $session === null
            || ! SessionsVisibility::sessions(TrainingSession::query()->whereKey($session->id), $user)->exists()) {
            return null;
        }
        $attendance = self::mayReadAttendance($session, $user);
        $manage = $attendance && SlotRows::mayCatchUpOn($session->id, $user);
        $closed = $attendance ? RealisedHours::forEnrolment($enrolment->id)['closed_months'] : [];

        $from = $enrolment->entered_on?->toDateString();
        $to = $enrolment->left_on?->toDateString();
        $seances = Seance::query()->where('training_session_id', $session->id)
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'date', 'starts_at', 'ends_at', 'duration_minutes', 'session_module_id', 'trainer_id', 'room_id', 'place_text', 'status']);

        $modules = SessionModule::withTrashed()->whereIn('id', $seances->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $rooms = Room::withTrashed()->whereIn('id', $seances->pluck('room_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $trainers = Trainer::withTrashed()->whereIn('id', $seances->pluck('trainer_id')->filter()->unique()->values()->all())->pluck('person_id', 'id');
        $names = app(Fiches::class)->personLabels(array_values(array_map('strval', $trainers->all())));
        $marks = $attendance
            ? Attendance::query()->where('enrolment_id', $enrolment->id)->whereIn('seance_id', $seances->pluck('id')->all())->get()->keyBy('seance_id')
            : collect();
        $now = CarbonImmutable::now('Europe/Paris');
        $location = SessionLocation::tryFrom($session->location_kind);

        $rows = $seances->map(function (Seance $s) use ($modules, $rooms, $trainers, $names, $marks, $attendance, $manage, $closed, $now, $session, $location): array {
            $trainerPerson = $s->trainer_id === null ? null : ($trainers[$s->trainer_id] ?? null);
            $row = [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'starts_at' => $s->start(),
                'ends_at' => $s->end(),
                'duration_minutes' => (int) $s->duration_minutes,
                'module' => $modules[$s->session_module_id] ?? null,
                'trainer' => $trainerPerson === null ? null : ($names[$trainerPerson]['label'] ?? null),
                'place' => $s->room_id !== null && isset($rooms[$s->room_id]) ? $rooms[$s->room_id]
                    : ($s->place_text ?? ($session->location_text ?? $location?->label())),
                'remote' => $s->room_id === null && $s->place_text === null && $location === SessionLocation::Remote,
                'cancelled' => $s->isCancelled(),
                'past' => $s->endsAtInstant()->lessThanOrEqualTo($now),
            ];
            if ($attendance) {
                $mark = $marks->get($s->id);
                $row['attendance'] = $mark === null ? null : [
                    'status' => $mark->status,
                    'label' => Attendance::label($mark->status),
                    'minutes' => $mark->minutes,
                ];
                $row['can_record'] = $manage && $row['past'] && ! $row['cancelled'] && ! in_array($s->date->format('Y-m'), $closed, true);
            }

            return $row;
        })->values()->all();

        $result = [
            'session' => [
                'id' => $session->id,
                'label' => $session->label(app(Trainings::class)->find($session->training_id)?->title),
                'href' => "/sessions/{$session->id}",
                'starts_on' => $session->starts_on->toDateString(),
                'ends_on' => $session->ends_on->toDateString(),
            ],
            'seances' => $rows,
        ];
        if (! $attendance) {
            return $result;
        }

        $hours = RealisedHours::forEnrolment($enrolment->id);
        $result['enrolment_id'] = $enrolment->id;
        if ($manage) {
            // Prefills the remote catch-up link (changeable before sending).
            $result['email'] = app(Fiches::class)->personEmails([(string) $enrolment->person_id])[$enrolment->person_id] ?? null;
        }
        $result['hours'] = [
            'realised_minutes' => $hours['realised_minutes'],
            'planned_minutes' => $hours['enrolment_planned_minutes'],
        ];
        $result['signatures'] = self::signatures($session->id, $enrolment, $manage, $closed, $seances->where('status', Seance::PLANNED)->pluck('date')->map(fn ($d) => $d->toDateString())->unique()->values()->all());

        return $result;
    }

    /**
     * « Saisir les présences » on a scope other than « Ses séances », or
     * « Clôturer les présences », reaching the session.
     */
    public static function mayReadAttendance(TrainingSession $session, User $user): bool
    {
        $reaches = fn (string $permission) => SessionsVisibility::sessions(TrainingSession::withTrashed()->whereKey($session->id), $user, $permission)->exists();

        return (Scopes::of($user, Permissions::ATTENDANCE_MANAGE) !== AccessScope::Trainer && $reaches(Permissions::ATTENDANCE_MANAGE))
            || $reaches(Permissions::ATTENDANCE_CLOSE);
    }

    /**
     * The half-days opened on the days of his séances, and his signature on
     * each (none: signed false), with the actions $manage may take on it.
     *
     * @param  list<string>  $closed  closed months (« 2026-09 »)
     * @param  list<string>  $days
     * @return list<array<string, mixed>>
     */
    private static function signatures(string $sessionId, Enrolment $enrolment, bool $manage, array $closed, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $slots = SignSlot::query()->where('training_session_id', $sessionId)->whereIn('date', $days)
            ->orderBy('date')->orderByRaw("half = 'afternoon'")->get();
        $signatures = AttendanceSignature::query()->where('enrolment_id', $enrolment->id)
            ->whereIn('slot_id', $slots->pluck('id')->all())->get()->keyBy('slot_id');
        $links = CatchUpLink::query()->where('enrolment_id', $enrolment->id)->whereIn('slot_id', $slots->pluck('id')->all())
            ->whereNull('revoked_at')->orderBy('created_at')->get()->keyBy('slot_id');
        $today = TrainingSession::today()->toDateString();

        return $slots->map(function (SignSlot $slot) use ($signatures, $links, $manage, $closed, $today): array {
            $signature = $signatures->get($slot->id);
            $link = $links->get($slot->id);
            $open = $manage && ! in_array($slot->date->format('Y-m'), $closed, true);

            return [
                'id' => $signature?->id,
                'slot_id' => $slot->id,
                'date' => $slot->date->toDateString(),
                'half' => $slot->half,
                'half_label' => SignSlot::label($slot->half),
                'slot_status' => $slot->status,
                'signed' => $signature !== null,
                'signed_at' => $signature?->signed_at?->toIso8601String(),
                'kind' => $signature?->kind,
                'state' => self::state($signature),
                'drawing_url' => $signature?->drawing_path === null ? null : "/sessions/signatures/{$signature->id}/dessin",
                'catch_up_link' => $link === null ? null : [
                    'sent_at' => $link->created_at?->toIso8601String(),
                    'email_status' => $link->email_status,
                    'expires_at' => $link->expires_at->toIso8601String(),
                    'used' => $link->used_at !== null,
                ],
                'can_confirm' => $open && $signature?->isPendingCatchUp() === true,
                'can_send_link' => $open && $signature === null && $slot->date->toDateString() < $today,
            ];
        })->values()->all();
    }

    /** null (on time or unsigned), « pending », « confirmed » or « refused ». */
    private static function state(?AttendanceSignature $signature): ?string
    {
        return match (true) {
            $signature === null, $signature->kind === AttendanceSignature::KIND_ON_TIME => null,
            $signature->confirmed_at !== null => 'confirmed',
            $signature->refused_at !== null => 'refused',
            default => 'pending',
        };
    }
}
