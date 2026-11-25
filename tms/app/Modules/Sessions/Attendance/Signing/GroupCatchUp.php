<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Catching up several half-days of one trainee at once: one e-mail, one
 * link, one code, one drawing signed for each half-day listed
 * (RemoteCatchUp on a link carrying extra_slot_ids). Only the half-days the
 * trainee can still sign are offered: past days with live séances, the
 * trainee expected, the month open, not signed yet, and no attendance
 * entered absent or excused (an absence never turns into a signature).
 */
final class GroupCatchUp
{
    /** At most this many half-days per link. */
    public const MAX = 40;

    public function __construct(private readonly RemoteCatchUp $remote) {}

    /**
     * The half-days $enrolmentId may still sign, oldest first.
     *
     * @return list<array{date: string, half: string, label: string}>
     */
    public static function candidates(TrainingSession $session, string $enrolmentId): array
    {
        if (! Str::isUuid($enrolmentId) || $session->isCancelled()) {
            return [];
        }
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();
        $seances = Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->where('date', '<', $today)->orderBy('date')->orderBy('starts_at')->get();
        $closed = AttendanceClosure::query()->where('training_session_id', $session->id)->whereNotNull('closed_at')
            ->pluck('month')->map(fn ($m) => substr((string) $m, 0, 7))->all();
        $noted = Attendance::query()->where('enrolment_id', $enrolmentId)->whereIn('status', ['absent', 'excused'])
            ->whereIn('seance_id', $seances->pluck('id'))->pluck('seance_id')->map(fn ($id) => (string) $id)->all();
        $signed = SignSlot::query()->where('training_session_id', $session->id)
            ->whereIn('id', AttendanceSignature::query()->where('enrolment_id', $enrolmentId)->select('slot_id'))
            ->get(['date', 'half'])->map(fn (SignSlot $s) => $s->date->toDateString().'|'.$s->half)->all();

        $halves = [];
        foreach ($seances as $seance) {
            $date = $seance->date->toDateString();
            if (in_array(substr($date, 0, 7), $closed, true)) {
                continue;
            }
            // His individual planning: this séance (not exempted), at his hours.
            if (! Attendances::expected($seance)->whereKey($enrolmentId)->exists()) {
                continue;
            }
            $his = IndividualPlanning::times($seance, [$enrolmentId])[$enrolmentId];
            foreach (Halves::of($his['start'], $his['end']) as $half) {
                $key = "{$date}|{$half}";
                // An absence (excused or not) entered on a séance of the half-day: never caught up.
                if (in_array($seance->id, $noted, true)) {
                    $halves[$key] = false;
                } elseif (! array_key_exists($key, $halves) && ! in_array($key, $signed, true)) {
                    $halves[$key] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys(array_filter($halves)) as $key) {
            [$date, $half] = explode('|', $key);
            $out[] = ['date' => $date, 'half' => $half, 'label' => self::label($date, $half)];
        }

        return $out;
    }

    /** « mardi 8 septembre 2026 - matin » */
    public static function label(string $date, string $half): string
    {
        return CarbonImmutable::parse($date, 'Europe/Paris')->locale('fr')->isoFormat('dddd D MMMM YYYY').' - '.mb_strtolower(SignSlot::label($half));
    }

    /**
     * One link for the half-days chosen (each one among the candidates),
     * e-mailed to the trainee.
     *
     * @param  list<array{date?: mixed, half?: mixed}>  $halves
     * @return int the half-days of the link
     *
     * @throws ValidationException
     */
    public function send(TrainingSession $session, string $enrolmentId, array $halves, string $email, User $by): int
    {
        $offered = collect(self::candidates($session, $enrolmentId))->keyBy(fn (array $h) => $h['date'].'|'.$h['half']);
        if ($halves === []) {
            throw ValidationException::withMessages(['halves' => 'Cochez au moins une demi-journée.']);
        }
        if (count($halves) > self::MAX) {
            throw ValidationException::withMessages(['halves' => self::MAX.' demi-journées au plus par lien.']);
        }
        $keys = [];
        foreach ($halves as $h) {
            $date = is_string($h['date'] ?? null) ? $h['date'] : '';
            $half = is_string($h['half'] ?? null) ? $h['half'] : '';
            if (! $offered->has("{$date}|{$half}")) {
                $label = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && in_array($half, SignSlot::HALVES, true) ? self::label($date, $half) : 'cette demi-journée';
                throw ValidationException::withMessages(['halves' => "Le {$label} ne peut pas être rattrapé (signé, absence notée, mois clôturé ou stagiaire non attendu)."]);
            }
            $keys["{$date}|{$half}"] = [$date, $half];
        }
        $slotIds = DB::transaction(function () use ($session, $keys): array {
            TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            $ids = [];
            foreach ($keys as [$date, $half]) {
                Closures::assertOpen($session->id, $date);
                $slot = SignSlot::query()->where('training_session_id', $session->id)->where('date', $date)->where('half', $half)->first()
                    ?? SignSlot::query()->create(['training_session_id' => $session->id, 'date' => $date, 'half' => $half,
                        'status' => SignSlot::CLOSED, 'opened_at' => CarbonImmutable::now()]);
                $ids[] = (string) $slot->id;
            }

            return $ids;
        });

        $this->remote->sendGroup($slotIds, $enrolmentId, $email, $by);

        return count($slotIds);
    }

    /**
     * Confirms every catch-up of the trainee waiting in this session.
     *
     * @return int the catch-ups confirmed
     */
    public function confirmAll(TrainingSession $session, string $enrolmentId, User $by): int
    {
        $waiting = AttendanceSignature::query()->where('enrolment_id', $enrolmentId)
            ->whereIn('slot_id', SignSlot::query()->where('training_session_id', $session->id)->select('id'))
            ->get()->filter(fn (AttendanceSignature $s) => $s->isPendingCatchUp());
        foreach ($waiting as $signature) {
            app(CatchUpConfirmation::class)->confirm($signature, $by);
        }

        return $waiting->count();
    }
}
