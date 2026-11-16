<?php

namespace App\Modules\Sessions\Trainers;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Permissions;
use Illuminate\Support\Str;

/**
 * Rows of the trainers list and the « Formateur » block of a person fiche.
 */
final class TrainerRows
{
    /**
     * Every trainer out of the trash, by name.
     *
     * @return list<array{id: string, label: string, email: string|null, status: string, status_label: string, active: bool, user: array{name: string, email: string}|null}>
     */
    public static function list(): array
    {
        $trainers = Trainer::query()->get();
        $fiches = app(Fiches::class);
        $ids = $trainers->pluck('person_id')->map(fn ($id) => (string) $id)->all();
        $labels = $fiches->personLabels($ids);
        $emails = $fiches->personEmails($ids);
        $users = User::query()->whereIn('id', $trainers->pluck('user_id')->filter()->all())->get(['id', 'name', 'email'])->keyBy('id');

        return $trainers->map(fn (Trainer $t): array => [
            'id' => $t->id,
            'label' => $labels[$t->person_id]['label'] ?? 'Personne introuvable',
            'email' => $emails[$t->person_id] ?? null,
            'status' => $t->status,
            'status_label' => $t->statusLabel(),
            'active' => $t->active,
            'user' => $t->user_id !== null && $users->has($t->user_id) ? ['name' => $users[$t->user_id]->name, 'email' => $users[$t->user_id]->email] : null,
        ])->sortBy(fn (array $row) => Str::lower(Str::ascii($row['label'])).' '.$row['id'])->values()->all();
    }

    /**
     * Accounts « Ajouter un formateur » offers: active users of the
     * organisme not linked to a trainer, those whose séances right is on
     * « Ses séances » (the Formateur role, or any role like it) first.
     *
     * @return list<array{value: string, label: string, trainer_role: bool}>
     */
    public static function userOptions(): array
    {
        $linked = Trainer::query()->whereNotNull('user_id')->pluck('user_id')->all();
        $users = User::query()->whereNotIn('id', $linked)->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name', 'email']);
        $trainers = array_flip(Scopes::usersWith(Permissions::SEANCES_VIEW, AccessScope::Trainer, $users->pluck('id')->all()));

        return $users
            ->map(fn (User $u): array => [
                'value' => $u->id,
                'label' => "{$u->name} ({$u->email})",
                'trainer_role' => isset($trainers[$u->id]),
            ])
            ->sortBy(fn (array $o) => ($o['trainer_role'] ? '0' : '1').Str::lower(Str::ascii($o['label'])))
            ->values()->all();
    }

    /**
     * « Formateur » block of a person fiche (Crm\Public\FicheBlocks): the
     * trainer's status, account and coming unavailabilities, for whoever
     * manages the trainers; none when the person is no trainer.
     *
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>}|null
     */
    public static function personBlock(string $personId, User $viewer): ?array
    {
        if (! $viewer->can(Permissions::TRAINERS_MANAGE)) {
            return null;
        }
        $trainer = Trainer::query()->where('person_id', $personId)->first();
        if ($trainer === null) {
            return null;
        }
        $coming = $trainer->unavailabilities()->where('ends_at', '>', now())->orderBy('starts_at')->limit(10)->get();

        return [
            'title' => 'Formateur',
            'empty' => 'Aucune information.',
            'rows' => [
                [
                    'id' => $trainer->id,
                    'title' => 'Formateur '.Str::lower($trainer->statusLabel()),
                    'subtitle' => $trainer->user_id === null ? 'Sans compte TMS' : 'Compte TMS lié',
                    'meta' => null,
                    'badge' => $trainer->active ? null : 'Inactif',
                    'href' => route('trainers.show', $trainer->id, false),
                ],
                ...$coming->map(fn (TrainerUnavailability $u): array => [
                    'id' => $u->id,
                    'title' => 'Indisponible',
                    'subtitle' => $u->label(),
                    'meta' => $u->reason,
                    'badge' => null,
                    'href' => null,
                ])->all(),
            ],
        ];
    }
}
