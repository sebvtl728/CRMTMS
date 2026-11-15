<?php

namespace App\Modules\Crm\Interests;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Formations d'intérêt » block of a fiche: same right as editing the
 * fiche, never on a trashed one (visibility: the route binding).
 */
class FicheTrainingController extends Controller
{
    public function __construct(private readonly FicheTrainings $trainings) {}

    public function organisation(Request $request, Organisation $organisation): RedirectResponse
    {
        return $this->update($request, $organisation);
    }

    public function person(Request $request, Person $person): RedirectResponse
    {
        return $this->update($request, $person);
    }

    private function update(Request $request, Organisation|Person $fiche): RedirectResponse
    {
        Gate::authorize('update', $fiche);
        abort_if($fiche->trashed(), 404);

        $validated = $request->validate(FicheTrainings::rules($fiche), FicheTrainings::messages());
        /** @var list<string> $ids */
        $ids = array_values($validated['training_ids'] ?? []);
        $this->trainings->sync($fiche, $ids);

        return back()->with('status', 'Formations d\'intérêt enregistrées.');
    }
}
