<?php

namespace App\Modules\Finance\Plans;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves the billing plan of a dossier from its « Facturation » block:
 * 404 out of the scope of finance.invoices.view on the dossier (or in the
 * trash), 403 out of the scope of finance.invoices.manage.
 */
class BillingPlanController extends Controller
{
    public function __construct(private readonly BillingPlans $plans) {}

    public function update(Request $request, string $dossierId): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && Billing::allows($user, Permissions::VIEW, $dossierId), 404);
        abort_unless(Billing::allows($user, Permissions::MANAGE, $dossierId), 403);

        $this->plans->save($dossierId, $request->only(['rhythm', 'basis', 'unjustified_absences', 'schedule']));

        return back()->with('status', 'Réglages de facturation enregistrés.');
    }
}
