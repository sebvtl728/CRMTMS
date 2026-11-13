<?php

namespace App\Modules\Crm\Duplicates;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Organisations\OrganisationStatus;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Side-by-side merge screen of two fiches and its confirmation.
 * Route middleware: crm.merge. Both fiches must be visible and active.
 */
class MergeController extends Controller
{
    public function __construct(private readonly Merger $merger) {}

    public function show(Request $request, string $type, string $keep, string $drop): Response
    {
        $user = $this->user($request);
        $k = $this->find($type, $keep, $user);
        $d = $this->find($type, $drop, $user);
        abort_if($k === null || $d === null || $k->getKey() === $d->getKey(), 404);

        $fields = collect($type === 'person' ? Merger::PERSON_FIELDS : Merger::ORGANISATION_FIELDS)
            ->map(function (string $label, string $name) use ($k, $d) {
                $keepValue = $this->display($name, $k->getAttribute($name));
                $dropValue = $this->display($name, $d->getAttribute($name));

                return [
                    'name' => $name,
                    'label' => $label,
                    'keep' => $keepValue,
                    'drop' => $dropValue,
                    'same' => $keepValue === $dropValue,
                    'default' => Merger::defaultChoice($k->getAttribute($name), $d->getAttribute($name)),
                ];
            })->values()->all();

        return Inertia::render('crm/duplicates/merge', [
            'type' => $type,
            'keep' => $this->summary($k),
            'drop' => $this->summary($d),
            'fields' => $fields,
            'counts' => ['keep' => $this->counts($k), 'drop' => $this->counts($d)],
        ]);
    }

    public function store(MergeRequest $request, string $type): RedirectResponse
    {
        $user = $this->user($request);
        $keep = $this->find($type, $request->string('keep')->toString(), $user);
        $drop = $this->find($type, $request->string('drop')->toString(), $user);

        if ($keep === null) {
            throw ValidationException::withMessages(['keep' => 'Fiche à conserver introuvable ou déjà fusionnée.']);
        }
        if ($drop === null) {
            throw ValidationException::withMessages(['drop' => 'Fiche à absorber introuvable ou déjà fusionnée.']);
        }

        if ($keep instanceof Organisation && $drop instanceof Organisation) {
            $this->merger->organisations($keep, $drop, $request->choices());

            return to_route('crm.organisations.show', $keep)->with('status', 'Fiches fusionnées.');
        }

        assert($keep instanceof Person && $drop instanceof Person);
        $this->merger->people($keep, $drop, $request->choices());

        return to_route('crm.people.show', $keep)->with('status', 'Fiches fusionnées.');
    }

    /**
     * An active (not trashed, hence not merged) fiche of $type visible to $user.
     */
    private function find(string $type, string $id, User $user): Organisation|Person|null
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return $type === 'person'
            ? CrmVisibility::people(Person::query(), $user)->find($id)
            : CrmVisibility::organisations(Organisation::query(), $user)->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Organisation|Person $fiche): array
    {
        return [
            'id' => $fiche->getKey(),
            'name' => $fiche instanceof Person ? $fiche->fullName() : $fiche->name,
            'created_at' => $fiche->created_at?->toIso8601String(),
            'das' => $fiche->das()->pluck('reference_items.label')->all(),
        ];
    }

    /**
     * @return array{notes: int, attachments: int, affiliations: int}
     */
    private function counts(Organisation|Person $fiche): array
    {
        $morph = fn (string $model) => $model::query()->where('notable_type', $fiche->getMorphClass())->where('notable_id', $fiche->getKey())->count();

        return [
            'notes' => $morph(Note::class),
            'attachments' => $morph(Attachment::class),
            'affiliations' => Affiliation::query()->where($fiche instanceof Person ? 'person_id' : 'organisation_id', $fiche->getKey())->whereNull('ended_on')->count(),
        ];
    }

    /**
     * Human value of a field (labels instead of ids), null when empty.
     */
    private function display(string $field, mixed $value): ?string
    {
        if (Merger::isEmpty($value) && $field !== 'is_individual') {
            return null;
        }

        return match (true) {
            $field === 'is_individual' => $value ? 'Oui' : 'Non',
            $value instanceof OrganisationStatus => $value->label(),
            $value instanceof Carbon => $value->format('d/m/Y'),
            $field === 'responsible_user_id' => User::query()->find($value)->name ?? '—',
            str_ends_with($field, '_id') => ReferenceItem::query()->find($value)->label ?? '—',
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
