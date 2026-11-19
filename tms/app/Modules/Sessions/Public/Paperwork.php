<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\TrainingSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * What the training documents (module Documents, 0.10) read of a dossier:
 * Billing::dossier() plus the contact, the places, dates and planned
 * minutes of its sessions and of each trainee (enrolled, confirmed or left:
 * never the waiting list nor a cancelled enrolment). Also moves the dossier
 * along when its convention is sent or signed.
 */
final class Paperwork
{
    public function __construct(private readonly DossierTransitions $transitions) {}

    /**
     * @return array<string, mixed>|null
     */
    public static function dossier(string $dossierId): ?array
    {
        $billing = Billing::dossier($dossierId);
        if ($billing === null) {
            return null;
        }
        $dossier = Dossier::withTrashed()->findOrFail($dossierId);
        $sessions = TrainingSession::withTrashed()->whereKey(array_column($billing['sessions'], 'id'))->get()->keyBy('id');
        $enrolments = Enrolment::query()->whereKey(array_column($billing['enrolments'], 'id'))->get()->keyBy('id');

        return [
            ...$billing,
            'contact_person_id' => $dossier->contact_person_id,
            'sessions' => array_map(function (array $s) use ($sessions): array {
                $session = $sessions[$s['id']];

                return [
                    ...$s,
                    'training_id' => $session->training_id,
                    'planned_minutes' => self::minutes($session->planned_hours),
                    'place' => self::place($session),
                ];
            }, $billing['sessions']),
            'trainees' => array_values(array_map(function (array $e) use ($enrolments, $sessions): array {
                $enrolment = $enrolments[$e['id']];

                return [
                    'enrolment_id' => $e['id'],
                    'person_id' => $e['person_id'],
                    'session_id' => $e['session_id'],
                    'entered_on' => $e['entered_on'],
                    'left_on' => $e['left_on'],
                    'status' => $e['status'],
                    'planned_minutes' => self::minutes($enrolment->planned_hours ?? $sessions[$e['session_id']]->planned_hours),
                ];
            }, $billing['enrolments'])),
        ];
    }

    public static function allows(User $user, string $permission, string $dossierId): bool
    {
        return Billing::allows($user, $permission, $dossierId);
    }

    /** « Convention envoyée » from « Créé »; any later state stays as it is. */
    public function markConventionSent(string $dossierId, CarbonImmutable $on): void
    {
        $dossier = Dossier::query()->find($dossierId);
        if ($dossier !== null && $dossier->status === DossierStatus::Created) {
            $this->transitions->sendConvention($dossier, $on);
        }
    }

    /** « Convention signée » from « Créé » (both steps) or « Convention envoyée »; later states stay. */
    public function markConventionSigned(string $dossierId, CarbonImmutable $on): void
    {
        $dossier = Dossier::query()->find($dossierId);
        if ($dossier === null) {
            return;
        }
        if ($dossier->status === DossierStatus::Created) {
            $dossier = $this->transitions->sendConvention($dossier, $on);
        }
        if ($dossier->status === DossierStatus::ConventionSent) {
            $this->transitions->signConvention($dossier, $on);
        }
    }

    private static function minutes(?string $hours): ?int
    {
        return $hours === null || ! is_numeric($hours) ? null : BigDecimal::of($hours)->multipliedBy(60)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /** The address typed on the session; « Distanciel » or « Chez le client » when there is none to print. */
    private static function place(TrainingSession $session): ?string
    {
        $text = trim((string) $session->location_text);

        return match ($session->location_kind) {
            SessionLocation::Remote->value => SessionLocation::Remote->label(),
            SessionLocation::Client->value => $text !== '' ? $text : SessionLocation::Client->label(),
            default => $text !== '' ? $text : null,
        };
    }
}
