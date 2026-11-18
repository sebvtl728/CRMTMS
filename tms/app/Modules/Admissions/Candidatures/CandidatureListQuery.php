<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Alternance\ContractType;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The single query of the candidature list and its export: the view scope,
 * the trash left out, a tab and the filters.
 *
 * Tabs (onglet): « candidats » (default: not admitted yet, or admitted
 * without a live dossier; rejected and withdrawn only when the stage filter
 * asks for them), « apprenants » (a live dossier, no contract),
 * « alternants » (a live dossier and a contract), « tous ».
 */
final class CandidatureListQuery
{
    public const TABS = ['candidats', 'apprenants', 'alternants', 'tous'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{onglet: string, etape: ?string, formation: ?string, das: ?string, responsable: ?string, contrat: ?string, q: ?string}
     */
    public static function filters(array $input): array
    {
        $text = fn (string $key) => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;
        $uuid = fn (string $key) => ($v = $text($key)) !== null && Str::isUuid($v) ? $v : null;
        $tab = $text('onglet');
        $stage = $text('etape');
        $contract = $text('contrat');

        return [
            'onglet' => in_array($tab, self::TABS, true) ? $tab : 'candidats',
            'etape' => $stage !== null && CandidatureStatus::tryFrom($stage) !== null ? $stage : null,
            'formation' => $uuid('formation'),
            'das' => $uuid('das'),
            'responsable' => $uuid('responsable'),
            'contrat' => $contract !== null && ContractType::tryFrom($contract) !== null ? $contract : null,
            'q' => $text('q'),
        ];
    }

    /**
     * @param  array{onglet: string, etape: ?string, formation: ?string, das: ?string, responsable: ?string, contrat: ?string, q: ?string}  $filters
     * @return Builder<Candidature>
     */
    public static function build(User $user, array $filters): Builder
    {
        $query = AdmissionsVisibility::candidatures(Candidature::query(), $user);
        $live = Admission::liveDossierIds();
        $hasContract = fn (Builder $q) => $q->whereIn('candidatures.id', AlternanceContract::query()->select('candidature_id'));

        match ($filters['onglet']) {
            'candidats' => $query
                ->when($filters['etape'] === null, fn (Builder $q) => $q->whereNotIn('candidatures.status', [CandidatureStatus::Rejected->value, CandidatureStatus::Withdrawn->value]))
                ->where(fn (Builder $q) => $q->whereNull('candidatures.dossier_id')->orWhereNotIn('candidatures.dossier_id', $live)),
            'apprenants' => $query->whereIn('candidatures.dossier_id', $live)
                ->whereNotIn('candidatures.id', AlternanceContract::query()->select('candidature_id')),
            'alternants' => $hasContract($query->whereIn('candidatures.dossier_id', $live)),
            default => $query,
        };

        $query->when($filters['etape'], fn (Builder $q, string $v) => $q->where('candidatures.status', $v))
            ->when($filters['formation'], fn (Builder $q, string $v) => $q->where('candidatures.training_id', $v))
            ->when($filters['das'], fn (Builder $q, string $v) => $q->where('candidatures.das_id', $v))
            ->when($filters['responsable'], fn (Builder $q, string $v) => $q->where('candidatures.responsible_user_id', $v))
            ->when($filters['contrat'], fn (Builder $q, string $v) => $q->whereIn('candidatures.id', AlternanceContract::query()->where('contract_type', $v)->select('candidature_id')))
            ->when($filters['q'], function (Builder $q, string $v): void {
                $people = app(Trainees::class)->matchingIds($v);
                $q->where(fn (Builder $w) => $w->where('candidatures.number', 'ilike', '%'.addcslashes($v, '\\%_').'%')->orWhereIn('candidatures.person_id', $people));
            });

        return $query->orderByDesc('candidatures.created_at')->orderByDesc('candidatures.id');
    }

    /**
     * Number of candidatures of each tab under the same filters.
     *
     * @param  array{onglet: string, etape: ?string, formation: ?string, das: ?string, responsable: ?string, contrat: ?string, q: ?string}  $filters
     * @return array<string, int>
     */
    public static function counts(User $user, array $filters): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = self::build($user, ['onglet' => $tab] + $filters)->reorder()->count();
        }

        return $counts;
    }
}
