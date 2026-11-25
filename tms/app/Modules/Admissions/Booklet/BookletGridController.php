<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The centre side of the competence grid (the company side is the tutor's,
 * by his monthly link).
 */
final class BookletGridController extends Controller
{
    public function update(Request $request, Candidature $bookletCandidature, BookletGrid $grid): RedirectResponse
    {
        $level = $request->input('level');
        $grid->set($bookletCandidature, (string) $request->input('key'), BookletGrid::CENTRE, is_string($level) && $level !== '' ? $level : null, $request->user());

        return back();
    }
}
