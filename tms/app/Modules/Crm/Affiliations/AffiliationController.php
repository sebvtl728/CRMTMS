<?php

namespace App\Modules\Crm\Affiliations;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Affiliations (rattachements) of people to an entreprise, managed from the
 * « Interlocuteurs » tab of the entreprise page.
 */
class AffiliationController extends Controller
{
    private const PERSON_FIELDS = ['civility', 'first_name', 'last_name'];

    public function store(AffiliationRequest $request, Organisation $organisation): RedirectResponse
    {
        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        $validated = $request->validated();
        $personId = $validated['person_id'] ?? null;

        if ($personId === null) {
            Gate::authorize('create', Person::class);
        }

        try {
            DB::transaction(function () use ($validated, $personId, $organisation): void {
                if ($personId === null) {
                    $person = (new Person)->fill([
                        ...array_intersect_key($validated, array_flip(self::PERSON_FIELDS)),
                        'status' => $organisation->status->value,
                    ]);
                    $person->save();
                    $personId = $person->getKey();
                }

                (new Affiliation)->fill([
                    ...array_diff_key($validated, array_flip([...self::PERSON_FIELDS, 'person_id'])),
                    'person_id' => $personId,
                    'organisation_id' => $organisation->getKey(),
                ])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race against another attachment of the same person.
            throw ValidationException::withMessages(['person_id' => AffiliationRequest::ALREADY_ATTACHED]);
        }

        return to_route('crm.organisations.show', $organisation)->with('status', 'Interlocuteur ajouté.');
    }

    public function update(AffiliationRequest $request, Affiliation $affiliation): RedirectResponse
    {
        $organisation = $this->organisation($affiliation);

        try {
            $affiliation->fill($request->validated())->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ended_on' => AffiliationRequest::ALREADY_ACTIVE]);
        }

        return to_route('crm.organisations.show', $organisation)->with('status', 'Rattachement enregistré.');
    }

    public function destroy(Affiliation $affiliation): RedirectResponse
    {
        $organisation = $this->organisation($affiliation);

        $affiliation->delete();

        return to_route('crm.organisations.show', $organisation)->with('status', 'Rattachement supprimé.');
    }

    /**
     * The entreprise of the affiliation, after checking the user may change it.
     */
    private function organisation(Affiliation $affiliation): Organisation
    {
        $organisation = Organisation::withTrashed()->findOrFail($affiliation->organisation_id);

        Gate::authorize('update', $organisation);
        abort_if($organisation->trashed(), 404);

        return $organisation;
    }
}
