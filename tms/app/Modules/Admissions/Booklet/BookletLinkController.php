<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** « Envoyer » / « Renvoyer » the monthly link to the apprentice or the tutor. */
final class BookletLinkController extends Controller
{
    public function send(Request $request, Candidature $bookletCandidature, string $month, string $signer, BookletLinks $links): RedirectResponse
    {
        $link = $links->send($bookletCandidature, $month, $signer, $request->user());

        return back()->with('success', 'Lien envoyé à '.$link->email.'.');
    }
}
