<?php

namespace App\Modules\Crm\Brochures;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Brochures;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Envoyer une plaquette » from an entreprise, a person or an opportunity:
 * crm.activities.update (route) on a fiche the user sees (policy), a live
 * one. The brochures themselves come from the shared catalogue.
 */
class BrochureSendingController extends Controller
{
    public function __construct(private readonly BrochureSender $sender) {}

    public function organisation(BrochureSendingRequest $request, Organisation $organisation): RedirectResponse
    {
        Gate::authorize('view', $organisation);

        return $this->store($request, $organisation, null, route('crm.organisations.show', $organisation));
    }

    public function person(BrochureSendingRequest $request, Person $person): RedirectResponse
    {
        Gate::authorize('view', $person);

        return $this->store($request, $person, null, route('crm.people.show', $person));
    }

    public function opportunity(BrochureSendingRequest $request, Opportunity $opportunity): RedirectResponse
    {
        Gate::authorize('view', $opportunity);
        $fiche = $opportunity->organisation ?? $opportunity->person;
        if ($fiche === null || $opportunity->trashed()) {
            throw new UserFacingHttpException(409, 'Cette opportunité est dans la corbeille : on ne peut plus lui envoyer de plaquette.');
        }

        return $this->store($request, $fiche, $opportunity, route('crm.opportunities.show', $opportunity));
    }

    /**
     * Brochures to choose from in the window (current versions, by title).
     */
    public function options(Request $request, Brochures $brochures): JsonResponse
    {
        $q = $request->query('q');

        return response()->json(['data' => $brochures->options(is_string($q) ? $q : null)]);
    }

    private function store(BrochureSendingRequest $request, Organisation|Person $fiche, ?Opportunity $opportunity, string $back): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        if ($fiche->trashed()) {
            throw new UserFacingHttpException(409, 'Cette fiche est dans la corbeille : on ne peut plus lui envoyer de plaquette.');
        }

        $this->sender->send(
            $fiche,
            $opportunity,
            $request->versions(),
            (string) $request->validated('recipient_person_id'),
            (string) $request->validated('subject'),
            (string) $request->validated('message'),
            $user,
        );

        return redirect($back)->with('status', 'Plaquette en cours d\'envoi.');
    }
}
