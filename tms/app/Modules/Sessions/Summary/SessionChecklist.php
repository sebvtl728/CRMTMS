<?php

namespace App\Modules\Sessions\Summary;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\AttendanceClosure;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Months;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Fundings\FundingBalance;
use App\Modules\Sessions\Modules\DatingMode;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Conflicts;
use App\Modules\Sessions\Planning\PlacementPeriod;
use App\Modules\Sessions\Planning\PlacementPeriodHost;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Public\SessionCards;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The progress cards of the « Synthèse » of a session (spec § 4.2, § 6.1–2),
 * for one session or a whole page (the kanban's badge): every kind of data
 * is read once for all the sessions (grouped by session id), never per
 * session. The cards follow the user's rights: fundings with
 * sessions.dossiers.view on the session, attendance and closures with
 * sessions.attendance.manage or .close (the « Suivi » step). A cancelled
 * session has no card.
 *
 * Days are Paris days: « upcoming » = planned séance dated today or later,
 * « past » = dated before today.
 */
final class SessionChecklist
{
    /**
     * @return list<Card>
     */
    public static function for(TrainingSession $session, User $user): array
    {
        return self::forMany(collect([$session]), $user)[$session->id];
    }

    /**
     * @param  Collection<int, TrainingSession>  $sessions
     * @return array<string, list<Card>> session id => cards in the spec's order
     */
    public static function forMany(Collection $sessions, User $user, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? TrainingSession::today())->setTimezone('Europe/Paris')->startOfDay();
        $day = $today->toDateString();
        $out = [];
        $live = $sessions->reject(fn (TrainingSession $s) => $s->isCancelled())->values();
        foreach ($sessions as $session) {
            $out[$session->id] = [];
        }
        if ($live->isEmpty()) {
            return $out;
        }
        $ids = $live->pluck('id')->all();

        $fundingsShown = self::reached($user, [Permissions::DOSSIERS_VIEW], $live);
        $followShown = self::reached($user, [Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE], $live);

        $modules = SessionModule::query()->whereIn('training_session_id', $ids)
            ->get(['id', 'training_session_id', 'planned_minutes', 'dating_mode'])->groupBy('training_session_id');
        $seances = Seance::query()->whereIn('training_session_id', $ids)->where('status', Seance::PLANNED)
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->get();
        $enrolments = Enrolment::query()->whereIn('training_session_id', $ids)
            ->get(['id', 'training_session_id', 'dossier_id', 'status'])->groupBy('training_session_id');
        $placements = PlacementPeriod::query()->with('hosts')->whereIn('training_session_id', $ids)->get()->groupBy('training_session_id');

        // Alerts of the upcoming séances, all sessions at once.
        $upcoming = $seances->filter(fn (Seance $s) => $s->date->toDateString() >= $day)->values();
        $alerted = [];
        foreach (Conflicts::forMany($upcoming->all()) as $i => $conflicts) {
            if ($conflicts !== []) {
                $alerted[$upcoming[$i]->id] = true;
            }
        }

        // Fundings: the dossiers of the (not cancelled) enrolments, funded against their price.
        $dossierIdsOf = [];
        $complete = [];
        if (in_array(true, $fundingsShown, true)) {
            foreach ($enrolments as $sessionId => $rows) {
                if ($fundingsShown[$sessionId] ?? false) {
                    $dossierIdsOf[$sessionId] = $rows->reject(fn (Enrolment $e) => $e->status === EnrolmentStatus::Cancelled)
                        ->pluck('dossier_id')->unique()->values()->all();
                }
            }
            $dossierIds = collect($dossierIdsOf)->flatten()->unique()->values()->all();
            $dossiers = $dossierIds === [] ? collect() : Dossier::query()->with('lines')->whereKey($dossierIds)
                ->where('status', '<>', DossierStatus::Cancelled->value)->get();
            $funded = FundingBalance::fundedOf($dossiers->pluck('id')->all());
            foreach ($dossiers as $dossier) {
                $complete[$dossier->id] = $funded[$dossier->id] >= $dossier->priceCents();
            }
        }

        // Attendance and closures, read only for the sessions showing them.
        $recorded = [];
        $closed = [];
        $followIds = array_keys(array_filter($followShown));
        if ($followIds !== []) {
            $pastIds = $seances->filter(fn (Seance $s) => $s->date->toDateString() < $day && in_array($s->training_session_id, $followIds, true))
                ->pluck('id')->all();
            $recorded = $pastIds === [] ? [] : array_fill_keys(
                Attendance::query()->whereIn('seance_id', $pastIds)->distinct()->pluck('seance_id')->all(), true);
            foreach (AttendanceClosure::query()->whereIn('training_session_id', $followIds)->whereNotNull('closed_at')
                ->get(['training_session_id', 'month']) as $closure) {
                $closed[$closure->training_session_id][Months::key($closure->month)] = true;
            }
        }

        $seancesOf = $seances->groupBy('training_session_id');
        foreach ($live as $session) {
            $id = $session->id;
            /** @var Collection<int, Seance> $own */
            $own = $seancesOf[$id] ?? collect();
            $next = $own->filter(fn (Seance $s) => $s->date->toDateString() >= $day);
            /** @var Collection<int, Enrolment> $trainees */
            $trainees = $enrolments[$id] ?? collect();

            $cards = [
                self::hours($modules[$id] ?? collect(), $own),
                self::count('trainers', 'Formateurs', 'planning', 'seances',
                    $next->whereNull('trainer_id')->count(),
                    'Toutes les séances à venir ont un formateur.',
                    fn (int $n) => self::plural($n, 'séance à venir', 'séances à venir').' sans formateur.'),
                self::count('rooms', 'Salles ou lieux', 'planning', 'seances',
                    $next->filter(fn (Seance $s) => $s->room_id === null && trim((string) $s->place_text) === '')->count(),
                    'Toutes les séances à venir ont une salle ou un lieu.',
                    fn (int $n) => self::plural($n, 'séance à venir', 'séances à venir').' sans salle ni lieu.'),
                self::count('conflicts', 'Conflits', 'planning', 'seances',
                    $next->filter(fn (Seance $s) => isset($alerted[$s->id]))->count(),
                    'Aucune alerte sur les séances à venir.',
                    fn (int $n) => self::plural($n, 'séance à venir', 'séances à venir').' avec des alertes.',
                    Card::ALERT),
                self::enrolments($session, $trainees),
            ];
            if ($fundingsShown[$id]) {
                $cards[] = self::fundings($dossierIdsOf[$id] ?? [], $complete);
            }
            $cards[] = self::placements($placements[$id] ?? collect(), $trainees);
            if ($followShown[$id]) {
                $attending = $trainees->contains(fn (Enrolment $e) => in_array($e->status->value, Attendances::ATTENDING, true));
                $cards[] = self::count('attendance', 'Présences', 'suivi', 'attendance',
                    $attending ? $own->filter(fn (Seance $s) => $s->date->toDateString() < $day && ! isset($recorded[$s->id]))->count() : 0,
                    'Aucune séance passée sans présences.',
                    fn (int $n) => self::plural($n, 'séance passée', 'séances passées').' sans présences.');
                $cards[] = self::closures($session, $closed[$id] ?? [], $today);
            }
            $out[$id] = $cards;
        }

        // The cards of the other modules (SessionCards: documents…), after Sessions' own.
        foreach (SessionCards::for($ids, $user) as $sessionId => $extra) {
            foreach ($extra as $card) {
                $out[$sessionId][] = new Card($card['key'], $card['title'], $card['value'], $card['state'], $card['text'], $card['step'], $card['anchor']);
            }
        }

        return $out;
    }

    /**
     * Cards to do or in alert (the kanban's badge).
     *
     * @param  list<Card>  $cards
     */
    public static function pending(array $cards): int
    {
        return count(array_filter($cards, fn (Card $c) => $c->isPending()));
    }

    /**
     * Per module one source (spec § 6.1): a séance module counts its planned
     * séances, an interval module its planned hours; compared with Σ of the
     * modules' planned hours.
     *
     * @param  Collection<int, SessionModule>  $modules
     * @param  Collection<int, Seance>  $seances  planned
     */
    private static function hours(Collection $modules, Collection $seances): Card
    {
        $planned = 0;
        $scheduled = 0;
        $undefined = 0;
        foreach ($modules as $module) {
            $planned += $module->planned_minutes ?? 0;
            $undefined += $module->planned_minutes === null ? 1 : 0;
            $scheduled += $module->dating_mode === DatingMode::Seances->value
                ? (int) $seances->where('session_module_id', $module->id)->sum('duration_minutes')
                : ($module->planned_minutes ?? 0);
        }
        $value = SessionModules::label($scheduled).' / '.SessionModules::label($planned);
        [$state, $text] = match (true) {
            $undefined > 0 => [Card::TODO, 'Heures prévues '.SessionModules::UNDEFINED.' pour '.self::plural($undefined, 'module', 'modules').'.'],
            $scheduled < $planned => [Card::TODO, 'Reste '.SessionModules::label($planned - $scheduled).' à planifier.'],
            $scheduled > $planned => [Card::ALERT, SessionModules::label($scheduled - $planned).' planifiées au-delà des heures prévues.'],
            default => [Card::DONE, 'Toutes les heures prévues sont planifiées.'],
        };

        return new Card('hours', 'Heures planifiées', $value, $state, $text, 'planning', 'seances');
    }

    /**
     * Inscrits ≥ 1 and ≤ places; beyond the places or a waiting list: alert.
     *
     * @param  Collection<int, Enrolment>  $enrolments
     */
    private static function enrolments(TrainingSession $session, Collection $enrolments): Card
    {
        $taken = $enrolments->filter(fn (Enrolment $e) => in_array($e->status, EnrolmentStatus::HOLDING_A_PLACE, true))->count();
        $waiting = $enrolments->filter(fn (Enrolment $e) => $e->status === EnrolmentStatus::Waitlisted)->count();
        $capacity = $session->capacity;
        $value = $capacity === null ? (string) $taken : "{$taken} / {$capacity}";
        [$state, $text] = match (true) {
            $capacity !== null && $taken > $capacity => [Card::ALERT, self::plural($taken, 'inscrit', 'inscrits').' pour '.self::plural($capacity, 'place', 'places').'.'],
            $waiting > 0 => [Card::ALERT, self::plural($waiting, 'stagiaire', 'stagiaires').' en liste d\'attente.'],
            $taken === 0 => [Card::TODO, 'Aucun stagiaire inscrit.'],
            default => [Card::DONE, self::plural($taken, 'inscrit', 'inscrits').($capacity === null ? '.' : ' pour '.self::plural($capacity, 'place', 'places').'.')],
        };

        return new Card('enrolments', 'Inscriptions', $value, $state, $text, 'stagiaires', 'enrolments');
    }

    /**
     * @param  list<string>  $dossierIds
     * @param  array<string, bool>  $complete  dossier id (not cancelled) => funded at 100 %
     */
    private static function fundings(array $dossierIds, array $complete): Card
    {
        $dossiers = array_values(array_filter($dossierIds, fn (string $id) => array_key_exists($id, $complete)));
        if ($dossiers === []) {
            return new Card('fundings', 'Financements', '0', Card::NA, 'Aucun dossier sur cette session.', 'stagiaires', 'fundings');
        }

        return self::count('fundings', 'Financements', 'stagiaires', 'fundings',
            count(array_filter($dossiers, fn (string $id) => ! $complete[$id])),
            'Chaque dossier est financé à 100 %.',
            fn (int $n) => self::plural($n, 'dossier', 'dossiers').' à compléter.');
    }

    /**
     * Each trainee (but cancelled and waitlisted ones) has a host entreprise
     * in every placement period; no period: sans objet.
     *
     * @param  Collection<int, PlacementPeriod>  $periods
     * @param  Collection<int, Enrolment>  $enrolments
     */
    private static function placements(Collection $periods, Collection $enrolments): Card
    {
        if ($periods->isEmpty()) {
            return new Card('placements', 'Stages', '0', Card::NA, 'Pas de période de stage.', 'planning', 'placements');
        }
        $trainees = $enrolments->reject(fn (Enrolment $e) => in_array($e->status, [EnrolmentStatus::Cancelled, EnrolmentStatus::Waitlisted], true));
        $missing = $trainees->filter(fn (Enrolment $e) => $periods->contains(
            fn (PlacementPeriod $p) => ! $p->hosts->contains(fn (PlacementPeriodHost $h) => $h->enrolment_id === $e->id && $h->organisation_id !== null),
        ))->count();

        return self::count('placements', 'Stages', 'planning', 'placements', $missing,
            'Chaque stagiaire a une entreprise d\'accueil.',
            fn (int $n) => self::plural($n, 'stagiaire', 'stagiaires').' sans entreprise d\'accueil.');
    }

    /**
     * The months of the session before the current Paris month are closed;
     * none elapsed yet: sans objet.
     *
     * @param  array<string, true>  $closed  « 2026-09 » => true
     */
    private static function closures(TrainingSession $session, array $closed, CarbonImmutable $today): Card
    {
        $current = Months::key($today);
        $elapsed = array_values(array_filter(Months::ofSession($session), fn (string $m) => $m < $current));
        if ($elapsed === []) {
            return new Card('closures', 'Clôture des mois', '0', Card::NA, 'Aucun mois écoulé à clôturer.', 'suivi', 'closures');
        }

        return self::count('closures', 'Clôture des mois', 'suivi', 'closures',
            count(array_filter($elapsed, fn (string $m) => ! isset($closed[$m]))),
            'Tous les mois écoulés sont clôturés.',
            fn (int $n) => "{$n} mois à clôturer.");
    }

    /**
     * A card counting what is left: 0 done, otherwise $pending.
     *
     * @param  callable(int): string  $left
     */
    private static function count(string $key, string $title, string $step, ?string $anchor, int $n, string $done, callable $left, string $pending = Card::TODO): Card
    {
        return new Card($key, $title, (string) $n, $n === 0 ? Card::DONE : $pending, $n === 0 ? $done : $left($n), $step, $anchor);
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n > 1 ? $many : $one);
    }

    /**
     * Sessions that one of $permissions reaches (SessionsVisibility::allows
     * for a whole page: the scopes read once, « Ses séances » in one query).
     *
     * @param  list<string>  $permissions
     * @param  Collection<int, TrainingSession>  $sessions
     * @return array<string, bool> session id => reached
     */
    private static function reached(User $user, array $permissions, Collection $sessions): array
    {
        $reached = $sessions->mapWithKeys(fn (TrainingSession $s) => [$s->id => false])->all();
        foreach ($permissions as $permission) {
            $scope = Scopes::of($user, $permission);
            $trainerOf = $scope === AccessScope::Trainer
                ? array_fill_keys(SessionsVisibility::sessionsOfTrainer(TrainingSession::withTrashed()->whereKey(array_keys($reached)), $user)->pluck('id')->all(), true)
                : [];
            $das = $scope === AccessScope::Das ? UserDas::ids($user) : [];
            foreach ($sessions as $s) {
                $reached[$s->id] = $reached[$s->id] || match ($scope) {
                    null => false,
                    AccessScope::All => true,
                    AccessScope::Own => in_array($user->getKey(), [$s->responsible_user_id, $s->created_by], true),
                    AccessScope::Das => in_array($s->das_id, $das, true),
                    AccessScope::Trainer => isset($trainerOf[$s->id]),
                };
            }
        }

        return $reached;
    }
}
