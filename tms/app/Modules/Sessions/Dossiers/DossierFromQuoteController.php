<?php

namespace App\Modules\Sessions\Dossiers;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\Sessions\SessionLocation;
use App\Modules\Sessions\Sessions\SessionPlace;
use App\Modules\Sessions\Sessions\SessionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assistant « Créer le dossier » of an accepted quote: summary, sessions,
 * trainees (one page, three screens), then one request creating it all
 * (DossierFromQuote). The request is JSON: 201 with the dossier's URL, 422
 * keyed by the plan, 409 (DossierExists) with the existing dossier.
 * Rights: sessions.dossiers.update (route), the quote seen through the CRM
 * (404 otherwise); sessions and trainees: sessions.sessions.update.
 */
class DossierFromQuoteController extends Controller
{
    public function __construct(private readonly DossierFromQuote $service) {}

    public function create(Request $request, string $quoteId): Response
    {
        $user = $this->user($request);
        Gate::authorize('create', Dossier::class);
        // 404 first: an unknown or invisible quote says nothing of its dossier.
        $plan = $this->service->prepare($quoteId, $user);
        $existing = DossierFromQuote::existing($quoteId);
        // The button is not offered (DAS of the quote outside « Ses DAS »): the page neither.
        if ($existing === null && $this->service->link($quoteId, $user) === null) {
            throw new UserFacingHttpException(403, DossierFromQuote::OUTSIDE_DAS);
        }

        return Inertia::render('sessions/dossiers/from-quote', [
            'existing' => $existing === null ? null : DossierExists::link($existing, $user),
            'plan' => $existing === null ? $plan : null,
            'options' => [
                'kind' => SessionKind::options(),
                'modality' => SessionListQuery::reference(ReferenceList::TrainingModality),
                'default_modality' => SessionWriter::defaultModalityId(),
                'location' => SessionLocation::options(),
                'location_companies' => SessionPlace::companyOptions(),
                'pricing_mode' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(DossierLine::PRICING_MODES), DossierLine::PRICING_MODES),
            ],
            'can' => ['sessions' => $user->can(Permissions::SESSIONS_UPDATE)],
        ]);
    }

    public function store(Request $request, string $quoteId): JsonResponse
    {
        $user = $this->user($request);
        Gate::authorize('create', Dossier::class);

        $dossier = $this->service->create($quoteId, $request->all(), $user);

        $request->session()->flash('status', "Dossier {$dossier->number} créé.");

        return response()->json(['url' => route('dossiers.show', $dossier)], 201);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
