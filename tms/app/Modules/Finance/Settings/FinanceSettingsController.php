<?php

namespace App\Modules\Finance\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Plans\BillingPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance › Réglages: the invoicing defaults of the organisme
 * (settings.manage, like the other rules of the organisme).
 */
class FinanceSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('finance/settings', [
            'settings' => ['unjustified_absences' => FinanceSettings::absencesDefault()],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $message = 'Choisissez « Non facturées » ou « Facturées au contractant ».';
        $validated = $request->validate(
            ['unjustified_absences' => ['required', Rule::in(BillingPlans::ABSENCES)]],
            ['unjustified_absences.required' => $message, 'unjustified_absences.in' => $message],
        );

        FinanceSettings::saveAbsences($validated['unjustified_absences']);

        return to_route('finance.settings.edit')->with('status', 'Réglages de facturation enregistrés.');
    }
}
