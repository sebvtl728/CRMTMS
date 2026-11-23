<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use App\Modules\Catalogue\Trainings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Modules of the training a candidate is exempted from (update right on the candidature). */
class DispensesController extends Controller
{
    public function update(Request $request, Candidature $candidature): RedirectResponse
    {
        CandidatureController::authorizeOn(CandidatureController::user($request), Permissions::CANDIDATURES_UPDATE, $candidature);
        $asked = $request->input('module_ids', []);
        $known = $candidature->training_id === null ? [] : array_column(app(Trainings::class)->modules($candidature->training_id), 'id');
        if (! is_array($asked) || array_diff($asked, $known) !== []) {
            throw ValidationException::withMessages(['module_ids' => 'Module inconnu pour cette formation.']);
        }
        $ids = array_values(array_intersect($known, $asked));
        $candidature->forceFill(['dispensed_module_ids' => $ids === [] ? null : $ids])->save();

        return back()->with('status', 'Dispenses enregistrées.');
    }

    /**
     * The modules of the candidature's training with their exemption, for the page.
     *
     * @return list<array{id: string, title: string, duration_hours: ?string, dispensed: bool}>
     */
    public static function modules(Candidature $candidature): array
    {
        if ($candidature->training_id === null) {
            return [];
        }
        $dispensed = $candidature->dispensed_module_ids ?? [];

        return array_map(fn (array $m) => [
            'id' => $m['id'],
            'title' => $m['title'],
            'duration_hours' => $m['duration_hours'],
            'dispensed' => in_array($m['id'], $dispensed, true),
        ], app(Trainings::class)->modules($candidature->training_id));
    }
}
