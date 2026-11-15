<?php

namespace App\Modules\Crm\Revenue;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\QuoteTotals;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes the revenue targets (crm.revenue.manage); each change is journaled
 * by the model.
 */
final class RevenueTargets
{
    /**
     * @param  int|null  $amountCents  null or 0 deletes the target of that period
     */
    public function set(string $dasId, Period $period, ?int $amountCents): void
    {
        $user = Auth::user();
        abort_unless($user !== null && $user->can(Permissions::REVENUE_MANAGE), 403);

        if ($amountCents !== null && ($amountCents < 0 || $amountCents > QuoteTotals::MAX_CENTS)) {
            throw ValidationException::withMessages(['amount' => 'Le montant de l\'objectif est invalide.']);
        }
        if (! ReferenceItem::query()->where('list', ReferenceList::Das)->whereKey($dasId)->exists()) {
            throw ValidationException::withMessages(['das_id' => 'Ce DAS n\'existe pas.']);
        }

        DB::transaction(function () use ($dasId, $period, $amountCents): void {
            $target = RevenueTarget::query()
                ->where('das_id', $dasId)
                ->where('period_type', $period->type)
                ->whereDate('period_start', $period->start->toDateString())
                ->lockForUpdate()
                ->first();

            if ($amountCents === null || $amountCents === 0) {
                $target?->delete();

                return;
            }

            $target ??= new RevenueTarget([
                'das_id' => $dasId,
                'period_type' => $period->type,
                'period_start' => $period->start->toDateString(),
            ]);
            $target->amount = QuoteTotals::decimal($amountCents);
            $target->save();
        });
    }
}
