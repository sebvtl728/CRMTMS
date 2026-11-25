<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BookletVisitController extends Controller
{
    public function store(Request $request, Candidature $bookletCandidature): RedirectResponse
    {
        BookletVisits::create($bookletCandidature, $request->only(['visited_on', 'points', 'trainer_user_id']), $request->user());

        return back()->with('success', 'Visite enregistrée.');
    }

    public function update(Request $request, Candidature $bookletCandidature, string $visit): RedirectResponse
    {
        BookletVisits::update($this->visit($bookletCandidature, $visit), $request->only(['visited_on', 'points', 'trainer_user_id']), $request->user());

        return back()->with('success', 'Visite modifiée.');
    }

    public function destroy(Request $request, Candidature $bookletCandidature, string $visit): RedirectResponse
    {
        BookletVisits::delete($this->visit($bookletCandidature, $visit), $request->user());

        return back()->with('success', 'Visite supprimée.');
    }

    private function visit(Candidature $candidature, string $id): BookletVisit
    {
        return BookletVisit::query()->where('candidature_id', $candidature->id)->whereKey($id)->firstOrFail();
    }
}
