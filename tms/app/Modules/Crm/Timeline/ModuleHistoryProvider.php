<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Public\FicheHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The entries other modules declare through Crm\Public\FicheHistory
 * (dossiers, inscriptions…), on the history of an entreprise or a person;
 * none without a signed-in viewer.
 */
final class ModuleHistoryProvider implements TimelineProvider
{
    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        $user = Auth::user();
        $type = match (true) {
            $subject instanceof Organisation => 'organisation',
            $subject instanceof Person => 'person',
            default => null,
        };
        if (! $user instanceof User || $type === null) {
            return collect();
        }

        $entries = collect();
        foreach (FicheHistory::entries($type, (string) $subject->getKey(), $user, $before, $limit) as $entryType => $rows) {
            foreach ($rows as $row) {
                $entries->push(new TimelineEntry($entryType, $row['at'], $row['title'], $row['body'] ?? null, $row['author'] ?? null, $row['meta']));
            }
        }

        return $entries;
    }
}
