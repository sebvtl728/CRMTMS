<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The trainer's part of a monthly page: centre observations, visa, and
 * « Signé sur papier » for the apprentice's or the tutor's part.
 */
final class BookletMonthController extends Controller
{
    public function observations(Request $request, Candidature $bookletCandidature, string $month, string $place): RedirectResponse
    {
        $observations = $request->input('observations');
        BookletMonths::saveObservations(BookletMonths::page($bookletCandidature, $month, $place), is_array($observations) ? $observations : [], $request->user());

        return back()->with('success', 'Observations enregistrées.');
    }

    public function visa(Request $request, Candidature $bookletCandidature, string $month, string $place): RedirectResponse
    {
        BookletMonths::visa(BookletMonths::page($bookletCandidature, $month, $place), $request->user());

        return back()->with('success', 'Page visée.');
    }

    public function unvisa(Request $request, Candidature $bookletCandidature, string $month, string $place): RedirectResponse
    {
        BookletMonths::unvisa(BookletMonths::page($bookletCandidature, $month, $place), $request->user());

        return back()->with('success', 'Visa retiré.');
    }

    public function paper(Request $request, Candidature $bookletCandidature, string $month, string $signer): RedirectResponse
    {
        BookletMonths::markPaper($bookletCandidature, $month, $signer, $request->user());

        return back()->with('success', 'Noté : signé sur papier.');
    }
}
