<?php

namespace App\Modules\Crm\Timeline;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function storeForOrganisation(NoteRequest $request, Organisation $organisation): RedirectResponse
    {
        return $this->store($request, $organisation);
    }

    public function storeForPerson(NoteRequest $request, Person $person): RedirectResponse
    {
        return $this->store($request, $person);
    }

    public function destroy(Note $note): RedirectResponse
    {
        $subject = $note->notable;
        abort_unless($subject instanceof Model, 404);
        Gate::authorize('update', $subject);

        $note->delete();

        return back()->with('status', 'Note supprimée.');
    }

    private function store(NoteRequest $request, Model $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);
        abort_if(method_exists($subject, 'trashed') && $subject->trashed(), 404);

        DB::transaction(function () use ($request, $subject): void {
            $note = new Note(['body' => $request->validated('body')]);
            $note->notable()->associate($subject);
            $note->save();
        });

        return back()->with('status', 'Note ajoutée.');
    }
}
