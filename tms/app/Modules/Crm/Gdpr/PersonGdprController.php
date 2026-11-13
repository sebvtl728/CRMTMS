<?php

namespace App\Modules\Crm\Gdpr;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GDPR actions on a person's fiche: consents, opposition to prospecting,
 * data export, anonymisation.
 */
class PersonGdprController extends Controller
{
    public function __construct(private readonly ConsentRecorder $consents, private readonly AuditRecorder $audit) {}

    public function storeConsent(Request $request, Person $person): RedirectResponse
    {
        Gate::authorize('update', $person);
        abort_if($person->trashed(), 404);

        $validated = $request->validate([
            'channel' => ['required', Rule::enum(ConsentChannel::class)],
            'granted' => ['required', 'boolean'],
            'source' => ['nullable', 'string', 'max:255'],
        ], [
            'channel.required' => 'Choisissez le canal.',
            'channel.enum' => 'Ce canal n\'existe pas.',
            'granted.required' => 'Indiquez si le consentement est accordé ou retiré.',
            'granted.boolean' => 'Cette valeur n\'est pas valide.',
            'source.max' => 'La source est trop longue (255 caractères au plus).',
        ]);

        $this->consents->record($person, ConsentChannel::from($validated['channel']), (bool) $validated['granted'], $validated['source'] ?? null);

        return back()->with('status', 'Consentement enregistré.');
    }

    public function optOut(Person $person): RedirectResponse
    {
        Gate::authorize('update', $person);
        abort_if($person->trashed(), 404);

        $this->consents->record($person, ConsentChannel::Prospecting, false, ConsentRecorder::OPT_OUT_SOURCE);

        return back()->with('status', 'Opposition à la prospection enregistrée.');
    }

    public function export(Request $request, Person $person): Response
    {
        Gate::authorize('gdpr', $person);
        $viewer = $request->user();

        $json = json_encode(PersonDataExport::for($person, $viewer instanceof User ? $viewer : null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->audit->record('gdpr.exported', $person, [], ['format' => 'json']);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="donnees-personne-'.$person->id.'.json"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function anonymise(Request $request, Person $person, Anonymiser $anonymiser): RedirectResponse
    {
        Gate::authorize('gdpr', $person);
        abort_if($person->anonymised_at !== null || $person->merged_into_id !== null, 404);

        $request->validate(['confirmation' => ['required', 'string', 'max:255']], ['confirmation.required' => 'Tapez le nom de la personne pour confirmer.']);
        if (self::normalise((string) $request->input('confirmation')) !== self::normalise($person->fullName())) {
            throw ValidationException::withMessages(['confirmation' => 'Le nom tapé ne correspond pas à « '.$person->fullName().' ».']);
        }

        try {
            $anonymiser->anonymise($person, 'manual');
        } catch (AnonymisationPostponed $e) {
            throw ValidationException::withMessages(['confirmation' => $e->getMessage()]);
        }

        return to_route('crm.people.show', $person)->with('status', 'Personne anonymisée : ses données personnelles ont été effacées.');
    }

    private static function normalise(string $name): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
