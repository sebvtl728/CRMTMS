<?php

namespace App\Modules\Admissions\History;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * History of the candidatures, read in audit_logs (no table of its own): on
 * the CRM fiche of the person (Crm\Public\FicheHistory, type « candidature »)
 * and on the candidature page. Opening and changes of stage only; never a
 * personal value (they are journaled without value anyway).
 */
final class CandidatureHistory
{
    public static function registerOnFiches(): void
    {
        FicheHistory::register('candidature', fn (FicheHistory $request): array => self::onFiche($request));
    }

    /**
     * @return list<array{at: CarbonImmutable, title: string, body?: string|null, author?: string|null, meta: array<string, mixed>}>
     */
    public static function onFiche(FicheHistory $request): array
    {
        $viewer = $request->viewer;
        if ($request->ficheType !== 'person' || ! $viewer instanceof User || ! $viewer->can(Permissions::CANDIDATURES_VIEW)) {
            return [];
        }
        $ids = AdmissionsVisibility::candidatures(Candidature::query(), $viewer)->where('candidatures.person_id', $request->ficheId)->selectRaw('candidatures.id::text');

        $logs = self::logs($ids)->tap(fn ($q) => $request->after($q))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($request->limit)->get();

        return self::entries($logs);
    }

    /**
     * The history of one candidature, most recent first.
     *
     * @return list<array{at: string, title: string, author: string|null}>
     */
    public static function forCandidature(Candidature $candidature): array
    {
        $logs = self::logs([$candidature->id])->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return array_map(fn (array $e) => ['at' => $e['at']->toIso8601String(), 'title' => $e['title'], 'author' => $e['author']], self::entries($logs));
    }

    /**
     * @param  Builder<Candidature>|list<string>  $ids
     * @return Builder<AuditLog>
     */
    private static function logs(mixed $ids): Builder
    {
        return AuditLog::query()->with('user:id,name')->where('auditable_type', Candidature::MORPH_ALIAS)->whereIn('auditable_id', $ids)
            ->where(fn ($q) => $q->where('event', 'created')->orWhere(fn ($u) => $u->where('event', 'updated')->whereNotNull('new_values->status')));
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{at: CarbonImmutable, title: string, body: null, author: string|null, meta: array<string, mixed>}>
     */
    private static function entries(Collection $logs): array
    {
        $numbers = Candidature::withTrashed()->whereKey($logs->pluck('auditable_id')->unique()->values()->all())->pluck('number', 'id');

        return $logs->map(function (AuditLog $log) use ($numbers): array {
            $number = $numbers[$log->auditable_id] ?? '-';
            $status = is_array($log->new_values) ? CandidatureStatus::tryFrom((string) ($log->new_values['status'] ?? '')) : null;

            return [
                'at' => CarbonImmutable::instance($log->created_at),
                'title' => $log->event === 'created' ? "Candidature {$number} ouverte" : "Candidature {$number} : ".($status?->label() ?? '-'),
                'body' => null,
                'author' => $log->user?->name,
                'meta' => ['id' => $log->id, 'candidature_id' => $log->auditable_id, 'href' => "/admissions/candidatures/{$log->auditable_id}"],
            ];
        })->values()->all();
    }
}
