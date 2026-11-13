<?php

namespace App\Modules\Crm\Search;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * « Dernières fiches consultées » of the dashboard. Stored server-side (not
 * in the session) so the list follows the user across devices and survives a
 * new login; ids only, capped at KEEP per user. The list shows only fiches
 * the user can still open (not deleted, not merged, within his visibility).
 */
final class RecentViews
{
    public const KEEP = 20;

    public const SHOWN = 5;

    public static function record(User $user, Organisation|Person $subject): void
    {
        $now = now();

        RecentView::query()->upsert(
            [['tenant_id' => $subject->tenant_id, 'user_id' => $user->id, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'viewed_at' => $now]],
            ['user_id', 'subject_type', 'subject_id'],
            ['viewed_at'],
        );

        $stale = RecentView::query()->where('user_id', $user->id)->orderByDesc('viewed_at')->orderByDesc('id')->skip(self::KEEP)->take(PHP_INT_MAX)->pluck('id');
        if ($stale->isNotEmpty()) {
            RecentView::query()->whereKey($stale->all())->delete();
        }
    }

    /**
     * @return list<array{type: string, id: string, name: string, subtitle: string|null, url: string}>
     */
    public static function for(User $user): array
    {
        $views = RecentView::query()->where('user_id', $user->id)->orderByDesc('viewed_at')->orderByDesc('id')->limit(self::KEEP)->get();
        if ($views->isEmpty()) {
            return [];
        }

        $ids = fn (string $type) => $views->where('subject_type', $type)->pluck('subject_id')->all();
        $organisations = CrmVisibility::organisations(Organisation::query(), $user)->whereNull('organisations.merged_into_id')
            ->whereKey($ids((new Organisation)->getMorphClass()))->get()->keyBy('id');
        $people = CrmVisibility::people(Person::query(), $user)->whereNull('people.merged_into_id')
            ->whereKey($ids((new Person)->getMorphClass()))->get()->keyBy('id');

        $rows = [];
        foreach ($views as $view) {
            /** @var Organisation|Person|null $subject */
            $subject = $view->subject_type === (new Organisation)->getMorphClass() ? $organisations->get($view->subject_id) : $people->get($view->subject_id);
            if ($subject === null) {
                continue;
            }

            $rows[] = $subject instanceof Organisation
                ? ['type' => 'organisation', 'id' => $subject->id, 'name' => $subject->name, 'subtitle' => $subject->city, 'url' => route('crm.organisations.show', $subject->id, false)]
                : ['type' => 'person', 'id' => $subject->id, 'name' => $subject->fullName(), 'subtitle' => $subject->city, 'url' => route('crm.people.show', $subject->id, false)];

            if (count($rows) === self::SHOWN) {
                break;
            }
        }

        return $rows;
    }
}
