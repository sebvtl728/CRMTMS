<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Timeline;
use App\Modules\Crm\Timeline\TimelineCursor;
use App\Modules\Crm\Timeline\TimelineEntry;
use App\Modules\Crm\Timeline\TimelineProvider;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * « Historique » of an entreprise, a person (account or recipient) or an
 * opportunity: « Devis DEV-2026-0012 créé / envoyé / relancé / accepté /
 * refusé / expiré », « Devis signé ajouté (DEV-2026-0012) ». Read from the journal of the quotes (their creation and
 * their status changes): every transition is there, with its author and its
 * instant, without a table of its own. Deleted quotes and quotes of a
 * deleted opportunity are left out; a Responsable DAS only sees those of
 * his DAS; none without a signed-in viewer.
 */
final class QuotesProvider implements TimelineProvider
{
    public const TYPE = 'quote';

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return collect();
        }

        $quotes = match (true) {
            $subject instanceof Organisation => Quote::query()->where('quotes.organisation_id', $subject->getKey()),
            $subject instanceof Person => Quote::query()->where(fn (Builder $q) => $q
                ->where('quotes.person_id', $subject->getKey())->orWhere('quotes.contact_person_id', $subject->getKey())),
            $subject instanceof Opportunity => Quote::query()->where('quotes.opportunity_id', $subject->getKey()),
            default => null,
        };
        if ($quotes === null) {
            return collect();
        }
        $quotes = CrmVisibility::quotes($quotes, $user)
            ->whereIn('quotes.opportunity_id', Opportunity::query()->select('opportunities.id'));

        $logs = AuditLog::query()->with('user:id,name')
            ->where('auditable_type', (new Quote)->getMorphClass())
            ->whereIn('auditable_id', $quotes->selectRaw('quotes.id::text'))
            ->where(fn ($q) => $q->whereIn('event', ['created', 'quote.signed_added'])
                ->orWhere(fn ($u) => $u->where('event', 'updated')->whereNotNull('new_values->status')))
            ->tap(fn ($q) => Timeline::after($q, $before, self::TYPE))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $numbers = Quote::query()->whereIn('id', $logs->pluck('auditable_id')->unique()->values()->all())->pluck('number', 'id');

        return $logs->map(function (AuditLog $log) use ($numbers): TimelineEntry {
            if ($log->event === 'quote.signed_added') {
                return new TimelineEntry(
                    self::TYPE,
                    CarbonImmutable::instance($log->created_at),
                    'Devis signé ajouté ('.($numbers[$log->auditable_id] ?? '—').')',
                    null,
                    $log->user?->name,
                    ['id' => $log->id, 'quote_id' => $log->auditable_id, 'event' => 'signed'],
                );
            }
            $status = $log->event === 'created' ? QuoteStatus::Draft : (QuoteStatus::tryFrom((string) ($log->new_values['status'] ?? '')) ?? QuoteStatus::Draft);
            $number = $numbers[$log->auditable_id] ?? '—';

            return new TimelineEntry(
                self::TYPE,
                CarbonImmutable::instance($log->created_at),
                "Devis {$number} {$status->event()}",
                null,
                $log->user?->name,
                ['id' => $log->id, 'quote_id' => $log->auditable_id, 'event' => $log->event === 'created' ? 'created' : $status->value],
            );
        });
    }
}
