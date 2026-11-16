<?php

namespace App\Modules\Sessions\History;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheHistory;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Throwable;

/**
 * History of the module, read from the journal (audit_logs: every creation
 * and change of state is there, with its author and its instant; no table
 * of its own), never a personal value:
 *  - on a CRM fiche (Crm\Public\FicheHistory): the dossiers of an
 *    entreprise or a person (contractant, or contact for a person) —
 *    creation and states, within the scope of sessions.dossiers.view; the
 *    enrolments of a person — enrolment, waiting list, confirmation,
 *    abandon, cancellation, within the scope of sessions.sessions.view;
 *  - on the page of a dossier (dossierPage): its creation and states, its
 *    enrolments and its fundings (added, changed, removed).
 * Trashed dossiers and sessions are left out of the fiches.
 *
 * @phpstan-type Entry array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}
 */
final class SessionsHistory
{
    /** Entries of a dossier page (most recent first). */
    public const PAGE_LIMIT = 200;

    private const ENROLMENT_EVENTS = [
        'confirmed' => 'Inscription confirmée',
        'enrolled' => 'Place obtenue (sortie de la liste d\'attente)',
        'waitlisted' => 'Mise en liste d\'attente',
        'abandoned' => 'Abandon',
        'cancelled' => 'Inscription annulée',
    ];

    public static function registerOnFiches(): void
    {
        FicheHistory::register('dossier', fn (FicheHistory $request): array => self::ficheDossiers($request));
        FicheHistory::register('enrolment', fn (FicheHistory $request): array => self::ficheEnrolments($request));
    }

    /**
     * @return list<Entry>
     */
    public static function ficheDossiers(FicheHistory $request): array
    {
        $viewer = $request->viewer;
        if (! $viewer instanceof User || ! $viewer->can(Permissions::DOSSIERS_VIEW)) {
            return [];
        }
        $dossiers = SessionsVisibility::dossiers(Dossier::query(), $viewer)
            ->where(fn (Builder $q) => $request->ficheType === 'organisation'
                ? $q->where('dossiers.account_organisation_id', $request->ficheId)
                : $q->where('dossiers.account_person_id', $request->ficheId)->orWhere('dossiers.contact_person_id', $request->ficheId));

        $logs = self::logs(Dossier::MORPH_ALIAS, $dossiers->selectRaw('dossiers.id::text'))
            ->where(fn ($q) => $q->where('event', 'created')
                ->orWhere(fn ($u) => $u->where('event', 'updated')->whereNotNull('new_values->status')))
            ->tap(fn ($q) => $request->after($q))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($request->limit)->get();
        $numbers = Dossier::query()->whereKey($logs->pluck('auditable_id')->unique()->values()->all())->pluck('number', 'id');
        $reasons = self::reasons($logs, 'cancelled_reason_id');

        return $logs->map(function (AuditLog $log) use ($numbers, $reasons): array {
            $number = $numbers[$log->auditable_id] ?? '—';

            return [
                'at' => CarbonImmutable::instance($log->created_at),
                'title' => $log->event === 'created' ? "Dossier {$number} créé" : "Dossier {$number} : ".self::dossierState($log),
                'body' => self::reasonBody($log, 'cancelled_reason_id', $reasons),
                'author' => $log->user?->name,
                'meta' => ['id' => $log->id, 'dossier_id' => $log->auditable_id, 'href' => route('dossiers.show', (string) $log->auditable_id, false)],
            ];
        })->values()->all();
    }

    /**
     * @return list<Entry>
     */
    public static function ficheEnrolments(FicheHistory $request): array
    {
        $viewer = $request->viewer;
        if ($request->ficheType !== 'person' || ! $viewer instanceof User || ! $viewer->can(Permissions::SESSIONS_VIEW)) {
            return [];
        }
        $sessions = SessionsVisibility::sessions(TrainingSession::query(), $viewer)->select('training_sessions.id');
        $enrolments = Enrolment::query()->where('enrolments.person_id', $request->ficheId)
            ->whereIn('enrolments.training_session_id', $sessions)->selectRaw('enrolments.id::text');

        $logs = self::enrolmentLogs($enrolments)
            ->tap(fn ($q) => $request->after($q))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($request->limit)->get();
        $describe = self::enrolmentDescriber($logs);

        return $logs->map(function (AuditLog $log) use ($describe): array {
            $entry = $describe($log);

            return [
                'at' => $entry['at'],
                'title' => $entry['title'],
                'body' => $entry['body'],
                'author' => $entry['author'],
                'meta' => ['id' => $log->id, 'enrolment_id' => $log->auditable_id, 'href' => $entry['href']],
            ];
        })->values()->all();
    }

    /**
     * History of a dossier page: the dossier, its enrolments (trainee named)
     * and its fundings, most recent first.
     *
     * @return list<array{at: string, title: string, body: string|null, author: string|null, type: string}>
     */
    public static function dossierPage(Dossier $dossier): array
    {
        $dossierLogs = self::logs(Dossier::MORPH_ALIAS, [$dossier->id])
            ->whereIn('event', ['created', 'updated', 'deleted', 'restored'])
            ->where(fn ($q) => $q->where('event', '<>', 'updated')->orWhereNotNull('new_values->status'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE_LIMIT)->get();
        $reasons = self::reasons($dossierLogs, 'cancelled_reason_id');
        $entries = $dossierLogs->map(fn (AuditLog $log): array => [
            'type' => 'dossier',
            'at' => CarbonImmutable::instance($log->created_at),
            'id' => $log->id,
            'title' => match ($log->event) {
                'created' => 'Dossier créé',
                'deleted' => 'Dossier mis à la corbeille',
                'restored' => 'Dossier restauré',
                default => 'État du dossier : '.self::dossierState($log),
            },
            'body' => self::reasonBody($log, 'cancelled_reason_id', $reasons),
            'author' => $log->user?->name,
        ]);

        $enrolmentIds = Enrolment::query()->where('dossier_id', $dossier->id)->selectRaw('enrolments.id::text');
        $enrolmentLogs = self::enrolmentLogs($enrolmentIds)->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE_LIMIT)->get();
        $describe = self::enrolmentDescriber($enrolmentLogs);
        $trainees = Enrolment::query()->whereKey($enrolmentLogs->pluck('auditable_id')->unique()->values()->all())->pluck('person_id', 'id');
        $names = app(Fiches::class)->personLabels($trainees->filter()->unique()->values()->all());
        $entries = $entries->concat($enrolmentLogs->map(function (AuditLog $log) use ($describe, $trainees, $names): array {
            $entry = $describe($log);
            $name = $names[$trainees[$log->auditable_id] ?? '']['label'] ?? 'Stagiaire';

            return ['type' => 'enrolment', 'at' => $entry['at'], 'id' => $log->id, 'title' => "{$name} — {$entry['title']}", 'body' => $entry['body'], 'author' => $entry['author']];
        }));

        $entries = $entries->concat(self::fundingEntries($dossier));

        return $entries->sort(fn (array $a, array $b) => strcmp($b['at']->utc()->format('Y-m-d H:i:s.u'), $a['at']->utc()->format('Y-m-d H:i:s.u'))
            ?: strcmp($b['type'], $a['type']) ?: strcmp((string) $b['id'], (string) $a['id']))
            ->take(self::PAGE_LIMIT)
            ->map(fn (array $e): array => [
                'type' => $e['type'],
                'at' => $e['at']->toIso8601ZuluString('microsecond'),
                'title' => $e['title'],
                'body' => $e['body'],
                'author' => $e['author'],
            ])->values()->all();
    }

    /**
     * Fundings of the dossier: added, state or other changes, removed (a
     * removed funding is found by the dossier named in the journal entry of
     * its creation).
     *
     * @return list<array{type: string, at: CarbonImmutable, id: string, title: string, body: string|null, author: string|null}>
     */
    private static function fundingEntries(Dossier $dossier): array
    {
        // Its fundings, removed ones included (named by the journal of their creation).
        $ids = array_values(array_unique([
            ...Funding::query()->where('dossier_id', $dossier->id)->pluck('id')->all(),
            ...AuditLog::query()->where('auditable_type', Funding::MORPH_ALIAS)->where('event', 'created')
                ->where('new_values->dossier_id', $dossier->id)->pluck('auditable_id')->filter()->all(),
        ]));
        $logs = AuditLog::query()->with('user:id,name')
            ->where('auditable_type', Funding::MORPH_ALIAS)->whereIn('auditable_id', $ids)
            ->whereIn('event', ['created', 'updated', 'deleted'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE_LIMIT)->get();

        // The funder of each funding: its row, or (removed) the journal of its creation or removal.
        $funderOf = [];
        foreach ($logs as $log) {
            $values = [...($log->old_values ?? []), ...($log->new_values ?? [])];
            if (is_string($values['funder_organisation_id'] ?? null)) {
                $funderOf[$log->auditable_id] = ['organisation', $values['funder_organisation_id']];
            } elseif (is_string($values['funder_person_id'] ?? null)) {
                $funderOf[$log->auditable_id] = ['person', $values['funder_person_id']];
            }
        }
        foreach (Funding::query()->whereKey($ids)->get() as $funding) {
            $funderOf[$funding->id] = [$funding->funderType(), $funding->funderId()];
        }
        $fiches = app(Fiches::class);
        $byKind = ['organisation' => [], 'person' => []];
        foreach ($funderOf as [$kind, $id]) {
            $byKind[$kind][$id] = $id;
        }
        $organisations = $fiches->organisationLabels(array_values($byKind['organisation']));
        $people = $fiches->personLabels(array_values($byKind['person']));
        $types = ReferenceItem::query()->whereKey($logs->map(fn (AuditLog $l) => $l->new_values['funding_type_id'] ?? null)->filter(fn ($v) => is_string($v) && Str::isUuid($v))->unique()->values()->all())->pluck('label', 'id');

        return $logs->map(function (AuditLog $log) use ($funderOf, $organisations, $people, $types): array {
            [$kind, $id] = $funderOf[$log->auditable_id] ?? ['organisation', ''];
            $funder = ($kind === 'organisation' ? $organisations : $people)[$id]['label'] ?? 'financeur';
            $new = $log->new_values ?? [];
            $title = match ($log->event) {
                'created' => 'Financement ajouté : '.$funder
                    .(isset($types[$new['funding_type_id'] ?? '']) ? ' ('.$types[$new['funding_type_id']].')' : '')
                    .(is_string($new['amount'] ?? null) || is_numeric($new['amount'] ?? null) ? ', '.self::amount((string) $new['amount']) : ''),
                'deleted' => 'Financement supprimé : '.$funder,
                default => array_key_exists('status', $new)
                    ? "Financement {$funder} : ".(FundingStatus::tryFrom((string) $new['status'])?->label() ?? (string) $new['status'])
                    : "Financement {$funder} modifié".(array_key_exists('amount', $new) ? ' (montant : '.self::amount((string) $new['amount']).')' : ''),
            };

            return ['type' => 'funding', 'at' => CarbonImmutable::instance($log->created_at), 'id' => $log->id, 'title' => $title, 'body' => null, 'author' => $log->user?->name];
        })->values()->all();
    }

    /**
     * Journal entries of $type for the ids (a list, or a subquery selecting
     * them as text).
     *
     * @template TModel of Model
     *
     * @param  list<string>|Builder<TModel>  $ids
     * @return Builder<AuditLog>
     */
    private static function logs(string $type, array|Builder $ids): Builder
    {
        return AuditLog::query()->with('user:id,name')->where('auditable_type', $type)->whereIn('auditable_id', $ids);
    }

    /**
     * Creation and changes of state of enrolments.
     *
     * @param  Builder<Enrolment>  $ids
     * @return Builder<AuditLog>
     */
    private static function enrolmentLogs(Builder $ids): Builder
    {
        return self::logs(Enrolment::MORPH_ALIAS, $ids)
            ->where(fn ($q) => $q->where('event', 'created')
                ->orWhere(fn ($u) => $u->where('event', 'updated')
                    ->where(fn ($c) => $c->whereNotNull('new_values->status')->orWhereNotNull('new_values->planned_hours'))));
    }

    /**
     * Title, body (abandon: day and reason), author and link of each
     * enrolment entry: sessions, trainings and reasons read once.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return Closure(AuditLog): array{at: CarbonImmutable, title: string, body: string|null, author: string|null, href: string|null}
     */
    private static function enrolmentDescriber(Collection $logs): Closure
    {
        $sessionOf = Enrolment::query()->whereKey($logs->pluck('auditable_id')->unique()->values()->all())->pluck('training_session_id', 'id');
        $sessions = TrainingSession::withTrashed()->whereKey($sessionOf->unique()->values()->all())->get(['id', 'number', 'title', 'training_id'])->keyBy('id');
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());
        $reasons = self::reasons($logs, 'abandon_reason_id');

        return function (AuditLog $log) use ($sessionOf, $sessions, $trainings, $reasons): array {
            $session = $sessions[$sessionOf[$log->auditable_id] ?? ''] ?? null;
            // « session S-2026-0001 (<intitulé> or <formation>) ».
            $name = $session === null ? null : ($session->title ?? $trainings[$session->training_id]->title ?? null);
            $where = $session === null ? 'session' : "session {$session->number}".($name === null ? '' : " ({$name})");
            $new = $log->new_values ?? [];
            $status = (string) ($new['status'] ?? '');
            $hours = $log->event === 'updated' && $status === '' && array_key_exists('planned_hours', $new);
            $title = match (true) {
                $log->event === 'created' => ($status === EnrolmentStatus::Waitlisted->value ? 'Inscription en liste d\'attente à la ' : 'Inscription à la ').$where,
                $hours => 'Heures prévues modifiées — '.$where,
                default => (self::ENROLMENT_EVENTS[$status] ?? 'Inscription modifiée').' — '.$where,
            };
            $body = $hours ? self::hoursBody($log->old_values['planned_hours'] ?? null, $new['planned_hours'] ?? null) : null;
            if ($log->event === 'updated' && $status === EnrolmentStatus::Abandoned->value) {
                $parts = [];
                if (is_string($new['left_on'] ?? null)) {
                    $parts[] = 'Sortie le '.self::day($new['left_on']);
                }
                $reason = self::reasonBody($log, 'abandon_reason_id', $reasons);
                if ($reason !== null) {
                    $parts[] = $reason;
                }
                $body = $parts === [] ? null : implode(' · ', $parts);
            }

            return [
                'at' => CarbonImmutable::instance($log->created_at),
                'title' => $title,
                'body' => $body,
                'author' => $log->user?->name,
                'href' => $session === null ? null : route('sessions.show', $session->id, false),
            ];
        };
    }

    /**
     * « 35,00 h → 28,00 h » (« — » for no hours).
     */
    private static function hoursBody(mixed $old, mixed $new): string
    {
        $show = fn (mixed $h): string => is_string($h) || is_int($h) ? SessionRows::hours(Amounts::decimal(Amounts::cents((string) $h))).' h' : '—';

        return $show($old).' → '.$show($new);
    }

    private static function dossierState(AuditLog $log): string
    {
        $status = (string) ($log->new_values['status'] ?? '');

        return DossierStatus::tryFrom($status)?->label() ?? $status;
    }

    /**
     * Labels of the reasons named by the entries ($column of new_values).
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return SupportCollection<string, string>
     */
    private static function reasons(Collection $logs, string $column): SupportCollection
    {
        $ids = $logs->map(fn (AuditLog $l) => $l->new_values[$column] ?? null)->filter(fn ($v) => is_string($v) && Str::isUuid($v))->unique()->values()->all();

        return $ids === [] ? collect() : ReferenceItem::query()->whereKey($ids)->pluck('label', 'id');
    }

    /**
     * @param  SupportCollection<string, string>  $reasons
     */
    private static function reasonBody(AuditLog $log, string $column, SupportCollection $reasons): ?string
    {
        $id = $log->new_values[$column] ?? null;

        return is_string($id) && isset($reasons[$id]) ? 'Motif : '.$reasons[$id] : null;
    }

    private static function day(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return $value;
        }
    }

    private static function amount(string $value): string
    {
        try {
            return Amounts::display(Amounts::cents($value));
        } catch (Throwable) {
            return $value;
        }
    }
}
