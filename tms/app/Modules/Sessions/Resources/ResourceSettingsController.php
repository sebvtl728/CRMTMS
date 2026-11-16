<?php

namespace App\Modules\Sessions\Resources;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Salles et fermetures » (sessions.rooms.manage): the rooms and the
 * closure periods of the organisme. {room} and {closurePeriod} are records
 * of the current organisme (RLS), 404 otherwise.
 */
class ResourceSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/sessions-settings', [
            'rooms' => Rooms::all()->map(fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'capacity' => $room->capacity,
                'address' => $room->address,
                'active' => $room->active,
            ])->values()->all(),
            'closures' => ClosurePeriods::all()->map(fn (ClosurePeriod $period): array => [
                'id' => $period->id,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'label' => $period->label,
            ])->values()->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        $room = Rooms::save(null, $this->room($request));

        return back()->with('status', "Salle « {$room->name} » ajoutée.");
    }

    public function updateRoom(Request $request, Room $room): RedirectResponse
    {
        Rooms::save($room, $this->room($request));

        return back()->with('status', "Salle « {$room->name} » enregistrée.");
    }

    public function destroyRoom(Room $room): RedirectResponse
    {
        Rooms::delete($room);

        return back()->with('status', "Salle « {$room->name} » supprimée.");
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        ClosurePeriods::save(null, $this->closure($request));

        return back()->with('status', 'Période de fermeture ajoutée.');
    }

    public function updateClosure(Request $request, ClosurePeriod $closurePeriod): RedirectResponse
    {
        ClosurePeriods::save($closurePeriod, $this->closure($request));

        return back()->with('status', 'Période de fermeture enregistrée.');
    }

    public function destroyClosure(ClosurePeriod $closurePeriod): RedirectResponse
    {
        ClosurePeriods::delete($closurePeriod);

        return back()->with('status', 'Période de fermeture supprimée.');
    }

    /**
     * @return array{name: string, capacity: int|null, address: string|null, active: bool}
     */
    private function room(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'address' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Indiquez le nom de la salle.',
            'name.string' => 'Indiquez le nom de la salle.',
            'name.max' => 'Le nom ne doit pas dépasser 120 caractères.',
            'capacity.integer' => 'La capacité doit être un nombre entier supérieur à 0.',
            'capacity.min' => 'La capacité doit être un nombre entier supérieur à 0.',
            'capacity.max' => 'La capacité ne doit pas dépasser 10 000 places.',
            'address.string' => "L'adresse doit être un texte.",
            'address.max' => "L'adresse ne doit pas dépasser 500 caractères.",
            'active.boolean' => 'Indiquez si la salle est active.',
        ]);

        return [
            'name' => (string) $data['name'],
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
            'address' => isset($data['address']) ? (string) $data['address'] : null,
            'active' => $request->boolean('active', true),
        ];
    }

    /**
     * @return array{starts_on: string, ends_on: string, label: string}
     */
    private function closure(Request $request): array
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'label' => ['required', 'string', 'max:120'],
        ], [
            'starts_on.required' => 'Indiquez la date de début.',
            'starts_on.date_format' => 'Indiquez une date de début valide.',
            'ends_on.required' => 'Indiquez la date de fin.',
            'ends_on.date_format' => 'Indiquez une date de fin valide.',
            'ends_on.after_or_equal' => 'La fin doit être le même jour que le début ou après.',
            'label.required' => "Indiquez l'intitulé de la fermeture.",
            'label.string' => "Indiquez l'intitulé de la fermeture.",
            'label.max' => "L'intitulé ne doit pas dépasser 120 caractères.",
        ]);

        return ['starts_on' => (string) $data['starts_on'], 'ends_on' => (string) $data['ends_on'], 'label' => (string) $data['label']];
    }
}
