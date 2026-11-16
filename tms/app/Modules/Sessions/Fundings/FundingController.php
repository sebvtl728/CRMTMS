<?php

namespace App\Modules\Sessions\Fundings;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Dossiers\Dossier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * « Financements » of the dossier page: add, change, delete. Rights: those
 * of the dossier (sessions.dossiers.update and its scope, DossierPolicy);
 * a funding of another dossier is a 404. Validation and writing:
 * FundingWriter.
 */
class FundingController extends Controller
{
    public function __construct(private readonly FundingWriter $writer) {}

    public function store(Request $request, Dossier $dossier): RedirectResponse
    {
        Gate::authorize('update', $dossier);

        $this->writer->create($dossier, $request->all());

        return to_route('dossiers.show', $dossier)->with('status', 'Financement ajouté.');
    }

    public function update(Request $request, Dossier $dossier, string $funding): RedirectResponse
    {
        Gate::authorize('update', $dossier);

        $this->writer->update(self::find($dossier, $funding), $request->all());

        return to_route('dossiers.show', $dossier)->with('status', 'Financement enregistré.');
    }

    public function destroy(Dossier $dossier, string $funding): RedirectResponse
    {
        Gate::authorize('update', $dossier);

        $this->writer->delete(self::find($dossier, $funding));

        return to_route('dossiers.show', $dossier)->with('status', 'Financement supprimé.');
    }

    private static function find(Dossier $dossier, string $id): Funding
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(Funding::class, [$id]);
        }

        return Funding::query()->where('dossier_id', $dossier->id)->findOrFail($id);
    }
}
