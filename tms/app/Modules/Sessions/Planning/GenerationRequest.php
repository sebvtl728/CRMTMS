<?php

namespace App\Modules\Sessions\Planning;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the « Générer les séances » assistant asks: days of the week (ISO,
 * 1 = Monday … 7 = Sunday), one or more slots of the day (« H:i », a break
 * in minutes, not overlapping on a common day; « days »: the weekdays of
 * that slot only, among the ticked ones — empty: every ticked day; on a
 * day named by a slot, only the slots naming it apply: « another timetable
 * on Fridays » replaces the every-day slots that day), a period within the session's dates
 * (default: the module's, else the session's), trainer, room, free place.
 * « until » = hours: no end date, the assistant goes on from the start
 * until the planned hours of the module are reached (at the latest the
 * end of the session) and gives the estimated end.
 */
final readonly class GenerationRequest
{
    /** At most this many séances (slots × days) per run. */
    public const MAX = 500;

    /**
     * @param  list<int>  $weekdays
     * @param  list<array{start: string, end: string, break_minutes: int, days: list<int>}>  $slots  days: empty = every weekday
     */
    public function __construct(
        public array $weekdays,
        public array $slots,
        public string $from,
        public string $to,
        public ?string $trainerId = null,
        public ?string $roomId = null,
        public ?string $placeText = null,
        public bool $untilPlanned = false,
    ) {}

    /**
     * The weekdays named by a slot (those days, only the slots naming them apply).
     *
     * @param  array<int|string, array<string, mixed>>  $slots
     * @return list<int>
     */
    public static function namedDays(array $slots): array
    {
        $days = [];
        foreach ($slots as $slot) {
            foreach (is_array($slot['days'] ?? null) ? $slot['days'] : [] as $day) {
                $days[] = (int) $day;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * From untrusted input, French validation errors (keys weekdays, slots,
     * slots.<i>.start|end|break_minutes, from, to, trainer_id, room_id, place_text).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input, SessionModule $module): self
    {
        $session = TrainingSession::withTrashed()->findOrFail($module->training_session_id);
        $sessionStart = $session->starts_on->toDateString();
        $sessionEnd = $session->ends_on->toDateString();
        $blank = fn (mixed $v): mixed => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v);
        $untilPlanned = ($input['until'] ?? null) === 'hours';
        if ($untilPlanned && $module->planned_minutes === null) {
            throw ValidationException::withMessages(['until' => 'Ce module n\'a pas d\'heures prévues : indiquez la date de fin.']);
        }
        if ($untilPlanned) {
            $input['to'] = $sessionEnd;
        }
        $data = [
            'weekdays' => $input['weekdays'] ?? null,
            'slots' => $input['slots'] ?? null,
            'from' => $blank($input['from'] ?? null) ?? $module->starts_on?->toDateString() ?? $sessionStart,
            'to' => $blank($input['to'] ?? null) ?? $module->ends_on?->toDateString() ?? $sessionEnd,
            'trainer_id' => $blank($input['trainer_id'] ?? null),
            'room_id' => $blank($input['room_id'] ?? null),
            'place_text' => $blank($input['place_text'] ?? null),
        ];
        if (is_array($data['slots'])) {
            $data['slots'] = array_values(array_map(fn ($slot) => is_array($slot) ? [
                'start' => self::time($slot['start'] ?? null),
                'end' => self::time($slot['end'] ?? null),
                'break_minutes' => $blank($slot['break_minutes'] ?? null) ?? '0',
                'days' => is_array($slot['days'] ?? null) ? array_values($slot['days']) : [],
            ] : $slot, $data['slots']));
        }
        $tenant = app(TenantContext::class)->id();

        $validator = Validator::make($data, [
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'slots' => ['required', 'array', 'min:1', 'max:6'],
            'slots.*' => ['array'],
            'slots.*.start' => ['bail', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'slots.*.end' => ['bail', 'required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'slots.*.break_minutes' => ['bail', 'integer', 'between:0,1439'],
            'slots.*.days' => ['array'],
            'slots.*.days.*' => ['integer', 'between:1,7'],
            'from' => ['bail', 'required', 'date_format:Y-m-d'],
            'to' => ['bail', 'required', 'date_format:Y-m-d'],
            'trainer_id' => ['bail', 'nullable', 'uuid', Rule::exists('trainers', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')->where('active', true)],
            'room_id' => ['bail', 'nullable', 'uuid', Rule::exists('rooms', 'id')->where('tenant_id', $tenant)->whereNull('deleted_at')->where('active', true)],
            'place_text' => ['nullable', 'string', 'max:300'],
        ], [
            'weekdays.required' => 'Choisissez au moins un jour de la semaine.',
            'weekdays.*' => 'Jour de la semaine inconnu.',
            'slots.required' => 'Ajoutez au moins un créneau.',
            'slots.min' => 'Ajoutez au moins un créneau.',
            'slots.max' => 'Six créneaux par jour au plus.',
            'slots.array' => 'Créneaux invalides.',
            'slots.*.array' => 'Créneau invalide.',
            'slots.*.start.required' => 'Indiquez le début du créneau.',
            'slots.*.end.required' => 'Indiquez la fin du créneau.',
            'slots.*.start.regex' => 'Heure invalide (ex. 09:00).',
            'slots.*.end.regex' => 'Heure invalide (ex. 09:00).',
            'slots.*.break_minutes.*' => 'La pause doit être un nombre entier de minutes.',
            'slots.*.days.*' => 'Jour de la semaine inconnu.',
            'from.required' => 'Indiquez le début de la période.',
            'from.*' => 'Date de début invalide.',
            'to.required' => 'Indiquez la fin de la période.',
            'to.*' => 'Date de fin invalide.',
            'trainer_id.*' => 'Formateur introuvable.',
            'room_id.*' => 'Salle introuvable.',
            'place_text.*' => 'Le lieu ne doit pas dépasser 300 caractères.',
        ]);
        $validator->after(function ($v) use ($data, $sessionStart, $sessionEnd, $untilPlanned): void {
            $errors = $v->errors();
            if (is_array($data['slots'])) {
                $previous = [];
                $ticked = is_array($data['weekdays']) ? array_map('intval', $data['weekdays']) : [];
                $named = self::namedDays(array_filter($data['slots'], 'is_array'));
                foreach ($data['slots'] as $i => $slot) {
                    if (! is_array($slot) || $errors->hasAny(["slots.{$i}.start", "slots.{$i}.end"])) {
                        continue;
                    }
                    $days = array_map('intval', is_array($slot['days'] ?? null) ? $slot['days'] : []);
                    if (array_diff($days, $ticked) !== [] && ! $errors->has("slots.{$i}.days")) {
                        $errors->add("slots.{$i}.days", 'Cochez aussi ce jour dans les jours de la semaine.');

                        continue;
                    }
                    // An every-day slot applies on the days no slot names.
                    $days = $days === [] ? array_values(array_diff($ticked, $named)) : $days;
                    $length = Seances::minutesBetween((string) $slot['start'], (string) $slot['end']);
                    if ($length <= 0) {
                        $errors->add("slots.{$i}.end", 'La fin du créneau doit suivre son début.');

                        continue;
                    }
                    if (! $errors->has("slots.{$i}.break_minutes") && (int) $slot['break_minutes'] >= $length) {
                        $errors->add("slots.{$i}.break_minutes", 'La pause doit être plus courte que le créneau.');
                    }
                    foreach ($previous as [$start, $end, $otherDays]) {
                        if ($slot['start'] < $end && $slot['end'] > $start && array_intersect($days, $otherDays) !== []) {
                            $errors->add("slots.{$i}.start", 'Les créneaux ne doivent pas se chevaucher.');

                            break;
                        }
                    }
                    $previous[] = [$slot['start'], $slot['end'], $days];
                }
            }
            if ($errors->hasAny(['from', 'to'])) {
                return;
            }
            $outside = 'La période doit rester dans les dates de la session (du '.Conflicts::french($sessionStart).' au '.Conflicts::french($sessionEnd).').';
            if ($data['to'] < $data['from']) {
                $errors->add('to', 'La fin de la période doit suivre son début.');
            } elseif ($data['from'] < $sessionStart || $data['from'] > $sessionEnd) {
                $errors->add('from', $outside);
            } elseif ($data['to'] > $sessionEnd) {
                $errors->add('to', $outside);
            } elseif (! $untilPlanned && ! $errors->hasAny(['weekdays', 'slots']) && is_array($data['weekdays']) && is_array($data['slots'])) {
                $days = WorkingDays::day($data['to'])->diffInDays(WorkingDays::day($data['from']), true) + 1;
                if (intdiv((int) $days + 6, 7) * count(array_unique($data['weekdays'])) * count($data['slots']) > self::MAX) {
                    $errors->add('to', 'L\'assistant crée au plus '.self::MAX.' séances à la fois : raccourcissez la période.');
                }
            }
        });
        $validator->validate();

        /** @var list<array{start: string, end: string, break_minutes: string|int, days: list<int|string>}> $slots */
        $slots = $data['slots'];
        usort($slots, fn (array $a, array $b) => strcmp($a['start'], $b['start']));
        $weekdays = array_values(array_unique(array_map('intval', (array) $data['weekdays'])));
        sort($weekdays);

        return new self(
            $weekdays,
            array_map(fn (array $s) => ['start' => $s['start'], 'end' => $s['end'], 'break_minutes' => (int) $s['break_minutes'],
                'days' => array_values(array_unique(array_map('intval', $s['days'])))], $slots),
            (string) $data['from'],
            (string) $data['to'],
            is_string($data['trainer_id']) ? $data['trainer_id'] : null,
            is_string($data['room_id']) ? $data['room_id'] : null,
            is_string($data['place_text']) ? $data['place_text'] : null,
            $untilPlanned,
        );
    }

    /** « 9:00 » → « 09:00 »; anything else unchanged (rejected by the rules). */
    private static function time(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(\d{1,2}):([0-5]\d)(:00)?$/', trim($value), $m) === 1) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2];
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
