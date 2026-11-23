<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Administration › Livret d'apprentissage (settings.manage). */
class BookletSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('admin/booklet', [
            'settings' => BookletSettings::all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        BookletSettings::save(['team' => $request->input('team', []), 'director_note' => $request->input('director_note')]);

        return back()->with('status', 'Réglages du livret enregistrés.');
    }

    public function storeAnnex(Request $request): RedirectResponse
    {
        BookletSettings::addAnnex((string) $request->input('title', ''), $request->file('file'));

        return back()->with('status', 'Annexe ajoutée.');
    }

    public function destroyAnnex(string $annex): RedirectResponse
    {
        BookletSettings::removeAnnex($annex);

        return back()->with('status', 'Annexe retirée.');
    }
}
