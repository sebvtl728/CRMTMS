<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches an invoice (spec 2026-11-20 facture libre): an invoice of a
 * dossier follows the scope of the permission on its dossier
 * (Billing::allows); a free invoice has no dossier, so only a scope
 * « Tout » reaches it.
 */
final class InvoiceAccess
{
    public static function allows(User $user, string $permission, Invoice $invoice, bool $withTrashed = false): bool
    {
        if ($invoice->dossier_id === null) {
            return self::all($user, $permission);
        }

        return Billing::allows($user, $permission, $invoice->dossier_id, withTrashed: $withTrashed);
    }

    /** $permission held on « Tout » (free invoices). */
    public static function all(User $user, string $permission): bool
    {
        return Scopes::of($user, $permission) === AccessScope::All;
    }

    /**
     * Narrows $query (invoices) to those $user reaches with $permission.
     *
     * @param  Builder<Invoice>  $query
     * @param  array{with_trashed?: bool}  $options
     * @return Builder<Invoice>
     */
    public static function scope(Builder $query, User $user, string $permission, array $options = []): Builder
    {
        $free = self::all($user, $permission);

        return $query->where(fn (Builder $q) => $q
            ->whereIn('dossier_id', Billing::dossiersFor($user, ['with_trashed' => $options['with_trashed'] ?? false], $permission)->select('dossiers.id'))
            ->when($free, fn (Builder $q) => $q->orWhere('origin', Invoice::ORIGIN_FREE)));
    }
}
