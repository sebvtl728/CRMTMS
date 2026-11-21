<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
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
            'rows' => $expected->map(fn ($e) => [
                'enrolment_id' => $e->id,
                'name' => $labels[$e->person_id]['label'] ?? 'Stagiaire',
                'signed_at' => $signatures->get($e->id)?->signed_at?->toIso8601String(),
                'signature_id' => $signatures->get($e->id)?->id,
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

    /** The user holds « Faire entrer un stagiaire en formation » on the slot's session. */
    public static function mayAdvanceEntry(SignSlot $slot, User $user): bool
    {
        return SessionsVisibility::sessions(TrainingSession::query()->whereKey($slot->training_session_id), $user, Permissions::ATTENDANCE_ADVANCE_ENTRY)->exists();
    }

    /**
     * The half-days of the séance's day for its session.
     *
     * @return list<array{half: string, label: string, status: string|null, slot_id: string|null, signed: int, expected: int, is_today: bool, is_future: bool}>
     */
    public static function forSeance(Seance $seance): array
    {
        $date = $seance->date->toDateString();
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
     * @param  list<string>  $slotIds
     * @return array<string, int>
     */
    private static function counts(array $slotIds): array
    {
        return AttendanceSignature::query()->whereIn('slot_id', $slotIds)->selectRaw('slot_id, count(*) as n')->groupBy('slot_id')
            ->toBase()->pluck('n', 'slot_id')->map(fn ($n) => (int) $n)->all();
    }
}
