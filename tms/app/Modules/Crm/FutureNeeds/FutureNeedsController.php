<?php

namespace App\Modules\Crm\FutureNeeds;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Besoins futurs » of a fiche (lot 1e): same right as editing the fiche,
 * never on a trashed one; the date and author follow the text (emptied: none).
 */
class FutureNeedsController extends Controller
{
    public const MAX = 2000;

    public function organisation(Request $request, Organisation $organisation): RedirectResponse
    {
        return $this->update($request, $organisation);
    }

    public function person(Request $request, Person $person): RedirectResponse
    {
        return $this->update($request, $person);
    }

    /**
     * The block of the fiche page, null-safe.
     *
     * @return array{text: string|null, updated_on: string|null, updated_by: string|null}
     */
    public static function present(Organisation|Person $fiche): array
    {
        return [
            'text' => $fiche->future_needs,
            'updated_on' => $fiche->future_needs_updated_at?->setTimezone('Europe/Paris')->format('d/m/Y'),
            'updated_by' => $fiche->futureNeedsAuthor()->value('name'),
        ];
    }

    private function update(Request $request, Organisation|Person $fiche): RedirectResponse
    {
        Gate::authorize('update', $fiche);
        abort_if($fiche->trashed(), 404);
        $text = trim((string) $request->validate(
            ['future_needs' => ['nullable', 'string', 'max:'.self::MAX]],
            ['future_needs.max' => 'Les besoins futurs tiennent en 2000 caractères au plus.', 'future_needs.string' => 'Saisissez un texte.'],
        )['future_needs']);

        $fiche->forceFill($text === ''
            ? ['future_needs' => null, 'future_needs_updated_at' => null, 'future_needs_updated_by' => null]
            : ['future_needs' => $text, 'future_needs_updated_at' => CarbonImmutable::now(), 'future_needs_updated_by' => $request->user()?->id])->save();

        return back()->with('status', 'Besoins futurs enregistrés.');
    }
}
