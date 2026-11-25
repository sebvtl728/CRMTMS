<?php

namespace App\Modules\Sessions\Automation;

use App\Modules\Core\Automation\AutomationSends;
use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\SessionPlace;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The day-before reminder (2026-11-22): every morning, for the confirmed
 * sessions with a live séance tomorrow (Paris), one e-mail to each expected
 * trainee (his séances) and one to each trainer (all his séances of the day,
 * every session), when they have an e-mail and are neither trashed nor
 * anonymised. Hours, module and place only: never a price, a note or the
 * other trainees. Each recipient is claimed once per day.
 */
final class DayBeforeReminders
{
    public function __construct(private readonly MailTemplates $templates, private readonly Fiches $fiches) {}

    /** The reminders queued. */
    public function run(CarbonImmutable $today): int
    {
        if (! AutomationSettings::dayBefore()) {
            return 0;
        }
        $tomorrow = CarbonImmutable::parse($today->setTimezone('Europe/Paris')->toDateString(), 'Europe/Paris')->addDay();
        $date = $tomorrow->toDateString();
        $seances = Seance::query()->where('date', $date)->where('status', '!=', Seance::CANCELLED)
            ->whereIn('training_session_id', TrainingSession::query()->where('status', 'confirmed')->select('id'))
            ->orderBy('starts_at')->get();
        if ($seances->isEmpty()) {
            return 0;
        }
        $sessions = TrainingSession::query()->whereKey($seances->pluck('training_session_id')->unique()->all())->get()->keyBy('id');
        $labels = PlanningRows::sessionLabels($sessions->values());
        $modules = SessionModule::query()->whereKey($seances->pluck('session_module_id')->unique()->all())->pluck('title', 'id');
        $rooms = Room::query()->whereKey($seances->pluck('room_id')->filter()->unique()->all())->get()->keyBy('id');
        $line = fn (Seance $s, bool $withSession, ?array $hours = null): string => implode(' · ', array_filter([
            ($hours['start'] ?? $s->start()).'–'.($hours['end'] ?? $s->end()),
            $withSession ? ($labels[$s->training_session_id] ?? null) : null,
            $modules[$s->session_module_id] ?? null,
            self::place($s, $sessions[$s->training_session_id], $rooms),
        ]));
        $day = $tomorrow->locale('fr')->isoFormat('dddd D MMMM YYYY');
        $organisme = (string) (app(TenantContext::class)->current()->name ?? '');
        $sent = 0;

        // One e-mail per trainee: his séances of tomorrow (a catch-up of another session included), at his hours.
        $byEnrolment = [];
        foreach ($seances as $seance) {
            $expected = Attendances::expected($seance)->get(['enrolments.id', 'enrolments.person_id', 'enrolments.training_session_id']);
            $times = IndividualPlanning::times($seance, $expected->pluck('id')->map(fn ($id) => (string) $id)->all());
            foreach ($expected as $enrolment) {
                $byEnrolment[$enrolment->id]['person'] = (string) $enrolment->person_id;
                $byEnrolment[$enrolment->id]['session'] = (string) $enrolment->training_session_id;
                $byEnrolment[$enrolment->id]['seances'][] = [$seance, $times[$enrolment->id]];
            }
        }
        // His own session's label (a trainee in catch-up keeps his).
        $missing = array_diff(array_unique(array_column($byEnrolment, 'session')), array_keys($labels));
        if ($missing !== []) {
            $labels += PlanningRows::sessionLabels(TrainingSession::withTrashed()->whereKey(array_values($missing))->get());
        }
        $people = $this->recipients(array_values(array_unique(array_column($byEnrolment, 'person'))));
        foreach ($byEnrolment as $enrolmentId => $row) {
            $person = $people[$row['person']] ?? null;
            if ($person === null) {
                continue;
            }
            $text = $this->templates->render(DayBeforeMailTemplates::TRAINEE, [
                'stagiaire' => $person['name'], 'session' => (string) ($labels[$row['session']] ?? ''), 'date' => $day,
                'seances' => implode("\n", array_map(fn (array $s) => '- '.$line($s[0], $s[0]->training_session_id !== $row['session'], $s[1]), $row['seances'])), 'organisme' => $organisme,
            ]);
            $sent += $this->queue((string) $enrolmentId, $date, $person['email'], $text);
        }

        $trainers = Trainer::query()->whereKey($seances->pluck('trainer_id')->filter()->unique()->all())->get(['id', 'person_id'])->keyBy('id');
        $people = $this->recipients($trainers->pluck('person_id')->map(fn ($id) => (string) $id)->all());
        foreach ($seances->whereNotNull('trainer_id')->groupBy('trainer_id') as $trainerId => $own) {
            $person = $people[(string) ($trainers[$trainerId]->person_id ?? '')] ?? null;
            if ($person === null) {
                continue;
            }
            $text = $this->templates->render(DayBeforeMailTemplates::TRAINER, [
                'formateur' => $person['name'], 'date' => $day,
                'seances' => $own->map(fn (Seance $s) => '- '.$line($s, true))->implode("\n"), 'organisme' => $organisme,
            ]);
            $sent += $this->queue((string) $trainerId, $date, $person['email'], $text);
        }

        return $sent;
    }

    /**
     * Name and e-mail of the people who may receive it (with an e-mail, out of the trash, not anonymised).
     *
     * @param  list<string>  $personIds
     * @return array<string, array{name: string, email: string}>
     */
    private function recipients(array $personIds): array
    {
        $labels = $this->fiches->personLabels($personIds);
        $emails = $this->fiches->personEmails($personIds);
        $out = [];
        foreach ($labels as $id => $label) {
            $email = $emails[$id] ?? null;
            if (! $label['trashed'] && is_string($email) && $email !== '') {
                $out[$id] = ['name' => $label['label'], 'email' => $email];
            }
        }

        return $out;
    }

    /** @param  array{subject: string, body: string}  $text */
    private function queue(string $subjectId, string $date, string $email, array $text): int
    {
        return DB::transaction(function () use ($subjectId, $date, $email, $text): int {
            if (! AutomationSends::claim(AutomationSends::DAY_BEFORE, $subjectId, $date)) {
                return 0;
            }
            SendDayBeforeReminder::dispatch($email, $text['subject'], $text['body'])->afterCommit();

            return 1;
        });
    }

    /** @param  Collection<string, Room>  $rooms */
    private static function place(Seance $seance, TrainingSession $session, Collection $rooms): ?string
    {
        $room = $seance->room_id === null ? null : $rooms->get($seance->room_id);
        if ($room !== null) {
            return implode(', ', array_filter([$room->name, $room->address]));
        }
        if ($seance->place_text !== null && $seance->place_text !== '') {
            return $seance->place_text;
        }
        if ($session->location_kind === SessionLocation::Remote->value) {
            return 'À distance';
        }

        return SessionPlace::of($session);
    }
}
