<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the screens read about the half-days: the trainer's live state of a
 * slot (full names: an authenticated reader), the half-days of a séance's
 * day (attendance grid) and of a month (« Présences » tab).
 */
final class SlotRows
{
    /**
     * @return array<string, mixed>
     */
    public static function state(SignSlot $slot, ?User $user = null): array
    {
        $expected = SlotSeances::expected($slot);
        $late = self::lateEntrants($slot);
        $signatures = AttendanceSignature::query()->where('slot_id', $slot->id)->get()->keyBy('enrolment_id');
        $labels = app(Fiches::class)->personLabels($expected->concat($late)->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $emails = app(Fiches::class)->personEmails($expected->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $links = CatchUpLink::query()->where('slot_id', $slot->id)->whereNull('revoked_at')->orderBy('created_at')->get()->keyBy('enrolment_id');
        $session = TrainingSession::withTrashed()->find($slot->training_session_id);
        $token = $slot->token();
        $url = $token === null ? null : SlotQr::publicUrl($token);

        return [
            'id' => $slot->id,
            'session_id' => $slot->training_session_id,
            'session' => $session?->number,
            'date' => $slot->date->toDateString(),
            'date_label' => $slot->date->locale('fr')->isoFormat('dddd D MMMM YYYY'),
            'half' => $slot->half,
            'half_label' => SignSlot::label($slot->half),
            'status' => $slot->status,
            'public_url' => $url,
            'qr_svg' => $url === null ? null : SlotQr::svg($url),
            'is_today' => $slot->date->toDateString() === TrainingSession::today()->toDateString(),
            'validated_at' => $slot->validated_at?->toIso8601String(),
            'validated_by' => $slot->trainer_name,
            // On-site catch-up of a past half-day (the link is shown once, when opened).
            'can_catch_up' => $user !== null && $slot->date->toDateString() < TrainingSession::today()->toDateString() && self::mayCatchUp($slot, $user),
            'catch_up' => [
                'open' => $slot->catch_up_token_hash !== null && $slot->catch_up_expires_at?->isFuture() === true,
                'public_url' => null,
                'expires_at' => $slot->catch_up_expires_at?->toIso8601String(),
            ],
            'rows' => $expected->map(fn ($e) => [
                'enrolment_id' => $e->id,
                'name' => $labels[$e->person_id]['label'] ?? 'Stagiaire',
                'signed_at' => $signatures->get($e->id)?->signed_at?->toIso8601String(),
                'signature_id' => $signatures->get($e->id)?->id,
                // A catch-up (its kind and state: pending, confirmed, refused), and the remote link last sent.
                'catch_up_kind' => $signatures->get($e->id)?->kind === AttendanceSignature::KIND_ON_TIME ? null : $signatures->get($e->id)?->kind,
                'catch_up_state' => self::catchUpState($signatures->get($e->id)),
                'email' => $emails[$e->person_id] ?? null,
                'catch_up_link' => ($l = $links->get($e->id)) === null ? null : [
                    'sent_at' => $l->created_at?->toIso8601String(),
                    'email_status' => $l->email_status,
                    'expires_at' => $l->expires_at->toIso8601String(),
                ],
            ])->sortBy(fn (array $r) => mb_strtolower($r['name']).$r['enrolment_id'])->values()->all(),
            // Enrolled but entering the training after this day: not expected, may be brought in.
            'late_entrants' => $slot->status === SignSlot::VALIDATED ? [] : $late->map(fn (Enrolment $e) => [
                'enrolment_id' => $e->id,
                'name' => $labels[$e->person_id]['label'] ?? 'Stagiaire',
                'entered_on' => $e->entered_on?->toDateString(),
            ])->sortBy(fn (array $r) => mb_strtolower($r['name']).$r['enrolment_id'])->values()->all(),
            'can_advance_entry' => $user !== null && self::mayAdvanceEntry($slot, $user),
        ];
    }

    /**
     * Enrolments of the session that attend the training but enter it after
     * the slot's day (and have not left before it).
     *
     * @return Collection<int, Enrolment>
     */
    public static function lateEntrants(SignSlot $slot): Collection
    {
        $date = $slot->date->toDateString();

        return Enrolment::query()->where('training_session_id', $slot->training_session_id)
            ->whereIn('status', Attendances::ATTENDING)
            ->where('entered_on', '>', $date)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date))
            ->orderBy('id')->get()->values();
    }

    /**
     * Opening a catch-up (on-site QR, remote link) is a manager's act:
     * « Saisir les présences » on a scope other than « Ses séances », reaching
     * the slot's session. A trainer still sees the half-day.
     */
    public static function mayCatchUp(SignSlot $slot, User $user): bool
    {
        return self::mayCatchUpOn($slot->training_session_id, $user);
    }

    public static function mayCatchUpOn(string $sessionId, User $user): bool
    {
        return Scopes::of($user, Permissions::ATTENDANCE_MANAGE) !== AccessScope::Trainer
            && SessionsVisibility::sessions(TrainingSession::query()->whereKey($sessionId), $user, Permissions::ATTENDANCE_MANAGE)->exists();
    }

    /** The user holds « Faire entrer un stagiaire en formation » on the slot's session. */
    public static function mayAdvanceEntry(SignSlot $slot, User $user): bool
    {
        return SessionsVisibility::sessions(TrainingSession::query()->whereKey($slot->training_session_id), $user, Permissions::ATTENDANCE_ADVANCE_ENTRY)->exists();
    }

    /**
     * The half-days of the séance's day for its session.
     *
     * @return list<array{half: string, label: string, status: string|null, slot_id: string|null, signed: int, expected: int, is_today: bool, is_future: bool, can_catch_up: bool}>
     */
    public static function forSeance(Seance $seance, ?User $user = null): array
    {
        $date = $seance->date->toDateString();
        $catchUp = $user !== null && $date < TrainingSession::today()->toDateString() && self::mayCatchUpOn($seance->training_session_id, $user);
        $slots = SignSlot::query()->where('training_session_id', $seance->training_session_id)->where('date', $date)->get()->keyBy('half');
        $counts = self::counts($slots->pluck('id')->all());
        $rows = [];
        foreach (SignSlot::HALVES as $half) {
            if (SlotSeances::of($seance->training_session_id, $date, $half)->isEmpty() && ! $slots->has($half)) {
                continue;
            }
            $slot = $slots[$half] ?? null;
            $rows[] = [
                'half' => $half,
                'label' => SignSlot::label($half),
                'status' => $slot?->status,
                'slot_id' => $slot?->id,
                'signed' => $slot === null ? 0 : ($counts[$slot->id] ?? 0),
                'expected' => Attendances::expected($seance)->count(),
                'is_today' => $date === TrainingSession::today()->toDateString(),
                'is_future' => $date > TrainingSession::today()->toDateString(),
                'can_catch_up' => $catchUp,
            ];
        }

        return $rows;
    }

    /**
     * The half-days opened in a Paris month of the session.
     *
     * @return list<array<string, mixed>>
     */
    public static function forMonth(string $sessionId, CarbonImmutable $first): array
    {
        $slots = SignSlot::query()->where('training_session_id', $sessionId)
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])
            ->orderBy('date')->orderByRaw("half = 'afternoon'")->get();
        $counts = self::counts($slots->pluck('id')->all());
        $today = TrainingSession::today()->toDateString();

        return $slots->map(fn (SignSlot $s) => [
            'id' => $s->id,
            'date' => $s->date->toDateString(),
            'half' => $s->half,
            'label' => SignSlot::label($s->half),
            'status' => $s->status,
            'signed' => $counts[$s->id] ?? 0,
            'expected' => SlotSeances::expected($s)->count(),
            'validated_by' => $s->trainer_name,
            'to_validate' => ! $s->isValidated() && $s->date->toDateString() < $today,
        ])->values()->all();
    }

    /**
     * The catch-ups of a Paris month of the session neither confirmed nor
     * refused (« Rattrapages à confirmer »), the oldest half-day first.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingCatchUps(string $sessionId, CarbonImmutable $first): array
    {
        $slots = SignSlot::query()->where('training_session_id', $sessionId)
            ->whereBetween('date', [$first->toDateString(), $first->endOfMonth()->toDateString()])->get()->keyBy('id');
        $pending = AttendanceSignature::query()->whereIn('slot_id', $slots->keys()->all())
            ->where('kind', '<>', AttendanceSignature::KIND_ON_TIME)->whereNull('confirmed_at')->whereNull('refused_at')
            ->orderBy('signed_at')->get();
        if ($pending->isEmpty()) {
            return [];
        }
        $enrolments = Enrolment::query()->whereKey($pending->pluck('enrolment_id')->all())->get()->keyBy('id');
        $labels = app(Fiches::class)->personLabels($enrolments->pluck('person_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
        $manual = Attendance::query()->whereIn('enrolment_id', $enrolments->keys()->all())->whereNull('source')
            ->join('seances', 'seances.id', '=', 'attendances.seance_id')
            ->toBase()->get(['attendances.enrolment_id', 'seances.date'])
            ->map(fn ($r) => $r->enrolment_id.'|'.CarbonImmutable::parse($r->date)->toDateString())->all();

        return $pending->map(function (AttendanceSignature $s) use ($slots, $enrolments, $labels, $manual): array {
            $slot = $slots[$s->slot_id];
            $enrolment = $enrolments[$s->enrolment_id] ?? null;

            return [
                'signature_id' => $s->id,
                'enrolment_id' => $s->enrolment_id,
                'name' => $enrolment === null ? 'Stagiaire' : ($labels[$enrolment->person_id]['label'] ?? 'Stagiaire'),
                'date' => $slot->date->toDateString(),
                'half' => $slot->half,
                'half_label' => SignSlot::label($slot->half),
                'kind' => $s->kind,
                'kind_label' => $s->kind === AttendanceSignature::KIND_ONSITE ? 'Sur place' : 'À distance',
                'signed_at' => $s->signed_at->toIso8601String(),
                'drawing_url' => $s->drawing_path === null ? null : route('sessions.catch-ups.drawing', $s->id),
                'manual_attendance' => in_array($s->enrolment_id.'|'.$slot->date->toDateString(), $manual, true),
            ];
        })->sortBy(fn (array $r) => $r['date'].($r['half'] === SignSlot::AFTERNOON ? '1' : '0').$r['signed_at'])->values()->all();
    }

    /**
     * @param  list<string>  $slotIds
     * @return array<string, int>
     */
    private static function counts(array $slotIds): array
    {
        return AttendanceSignature::query()->counted()->whereIn('slot_id', $slotIds)->selectRaw('slot_id, count(*) as n')->groupBy('slot_id')
            ->toBase()->pluck('n', 'slot_id')->map(fn ($n) => (int) $n)->all();
    }

    private static function catchUpState(?AttendanceSignature $signature): ?string
    {
        return match (true) {
            $signature === null, $signature->kind === AttendanceSignature::KIND_ON_TIME => null,
            $signature->refused_at !== null => 'refused',
            $signature->confirmed_at !== null => 'confirmed',
            default => 'pending',
        };
    }
}
