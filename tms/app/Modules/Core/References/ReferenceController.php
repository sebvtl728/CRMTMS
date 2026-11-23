<?php

namespace App\Modules\Core\References;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration of the reference lists (permission references.manage).
 */
class ReferenceController extends Controller
{
    public const SYSTEM_MESSAGE = 'Valeur système : désactivation impossible';

    public const IN_USE_MESSAGE = 'Cette valeur est utilisée : elle ne peut pas être supprimée. Désactivez-la pour la retirer des listes de choix en conservant l\'historique.';

    public function index(Request $request): Response
    {
        $items = ReferenceItem::query()->orderBy('position')->orderBy('label')->get()->groupBy(fn (ReferenceItem $i) => $i->list->value);

        $lists = [];
        foreach (ReferenceList::cases() as $list) {
            $lists[$list->value] = [
                'key' => $list->value,
                'label' => $list->label(),
                'has_probability' => $list->hasProbability(),
                'has_planning' => $list->hasPlanning(),
                'has_quote_stage' => $list->hasQuoteStage(),
                'has_force_majeure' => $list->hasForceMajeure(),
                'has_reached' => $list->hasReached(),
                'has_default_for' => $list->hasDefaultFor(),
                'has_external_billing' => $list->hasExternalBilling(),
                'has_funding_type' => $list->hasFundingType(),
                'has_opco' => $list->hasOpco(),
                'has_client' => $list->hasClient(),
                'items' => ($items[$list->value] ?? collect())->map(fn (ReferenceItem $i) => [
                    'id' => $i->id,
                    'code' => $i->code,
                    'label' => $i->label,
                    'position' => $i->position,
                    'is_system' => $i->is_system,
                    'is_active' => $i->is_active,
                    'in_use' => $i->isInUse(),
                    'meta' => (object) ($i->meta ?? []),
                ])->values()->all(),
            ];
        }

        return Inertia::render('admin/references/index', [
            'status' => $request->session()->get('status'),
            'lists' => $lists,
        ]);
    }

    public function store(Request $request, ReferenceList $list): RedirectResponse
    {
        $data = $this->validated($request, $list);

        DB::transaction(function () use ($list, $data): void {
            $item = ReferenceItem::create([
                'list' => $list,
                'code' => ReferenceItem::uniqueCode($list, $data['label']),
                'label' => $data['label'],
                'position' => (int) ReferenceItem::withTrashed()->where('list', $list)->max('position') + 1,
                'meta' => $this->metaFrom($list, $data, null),
            ]);
            $this->keepOneQuoteStage($item);
            $this->keepOneDefaultFor($item);
        });

        return $this->back('Valeur ajoutée.');
    }

    public function update(Request $request, ReferenceList $list, ReferenceItem $item): RedirectResponse
    {
        $this->assertBelongs($list, $item);
        $data = $this->validated($request, $list, $item);

        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $item->is_active;
        if ($item->is_system && ! $active) {
            $this->refuse(self::SYSTEM_MESSAGE);
        }

        $meta = $this->metaFrom($list, $data, $item);

        DB::transaction(function () use ($item, $data, $active, $meta): void {
            // The code is never editable: it is not read from the request at all.
            $item->update([
                'label' => $data['label'],
                'is_active' => $active,
                'meta' => $meta,
            ]);
            $this->keepOneQuoteStage($item);
            $this->keepOneDefaultFor($item);
        });

        return $this->back('Valeur enregistrée.');
    }

    public function destroy(ReferenceList $list, ReferenceItem $item): RedirectResponse
    {
        $this->assertBelongs($list, $item);

        if ($item->is_system) {
            $this->refuse(self::SYSTEM_MESSAGE);
        }
        if ($item->isInUse()) {
            $this->refuse(self::IN_USE_MESSAGE);
        }

        $item->delete();

        return $this->back('Valeur supprimée.');
    }

    /**
     * Persists a drag-and-drop order: `ids` lists every item of the list
     * (inactive ones included) in their new order.
     */
    public function reorder(Request $request, ReferenceList $list): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['string', 'distinct'],
        ]);

        $items = ReferenceItem::query()->where('list', $list)->get()->keyBy('id');
        $ids = array_values($data['ids']);

        if (count($ids) !== $items->count() || array_diff($ids, $items->keys()->all()) !== []) {
            throw ValidationException::withMessages(['ids' => 'L\'ordre doit contenir exactement les valeurs de la liste.']);
        }

        DB::transaction(function () use ($ids, $items): void {
            foreach ($ids as $position => $id) {
                $items[$id]->update(['position' => $position + 1]);
            }
        });

        return $this->back('Ordre enregistré.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ReferenceList $list, ?ReferenceItem $item = null): array
    {
        return $request->validate([
            'label' => [
                'required', 'string', 'max:255',
                // Two values with the same label would be indistinguishable in the lists.
                Rule::unique('reference_items', 'label')
                    ->where(fn ($q) => $q->where('list', $list->value)->whereNull('deleted_at')) // rows are already limited to the tenant by RLS
                    ->ignore($item?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array'],
            'meta.probability' => [Rule::requiredIf(fn () => $list->hasProbability() && ! ($item !== null && ($item->meta['outcome'] ?? null) !== null)), 'nullable', 'integer', 'between:0,100'],
            'meta.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'meta.planning' => ['nullable', Rule::in(array_keys(ReferenceItem::PLANNING_KINDS))],
            'meta.quote_stage' => ['nullable', 'boolean'],
            'meta.force_majeure' => ['nullable', 'boolean'],
            'meta.reached' => ['nullable', 'boolean'],
            'meta.default_for' => ['nullable', Rule::in(array_keys(ReferenceItem::DEFAULT_FOR))],
            'meta.external_billing' => ['nullable', 'boolean'],
            'meta.opco' => ['nullable', 'boolean'],
            'meta.funding_type_id' => ['nullable', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', app(TenantContext::class)->id())->where('list', ReferenceList::FundingType->value)->whereNull('deleted_at')],
            'meta.client' => ['nullable', 'boolean'],
        ], [
            'label.required' => 'Le libellé est obligatoire.',
            'label.unique' => 'Une valeur portant ce libellé existe déjà dans cette liste.',
            'meta.probability.required' => 'La probabilité (0 à 100) est obligatoire pour une étape.',
            'meta.probability.between' => 'La probabilité doit être comprise entre 0 et 100.',
            'meta.probability.integer' => 'La probabilité doit être un nombre entier.',
            'meta.funding_type_id.uuid' => 'Choisissez un type de financeur de la liste.',
            'meta.funding_type_id.exists' => 'Choisissez un type de financeur de la liste.',
            'meta.color.regex' => 'La couleur doit être au format #RRGGBB.',
            'meta.planning.in' => 'Choisissez Rendez-vous, Appel ou rien pour le planning.',
            'meta.quote_stage.boolean' => 'Cochez ou décochez la case.',
            'meta.force_majeure.boolean' => 'Cochez ou décochez la case.',
            'meta.default_for.in' => 'Choisissez Entreprise contractante, Particulier contractant ou rien.',
            'meta.external_billing.boolean' => 'Cochez ou décochez la case.',
        ]);
    }

    /**
     * Keeps what the form does not own (e.g. a stage outcome), applies the
     * editable keys. Won / lost stages keep their fixed probability.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function metaFrom(ReferenceList $list, array $data, ?ReferenceItem $item): ?array
    {
        $meta = $item === null ? [] : ($item->meta ?? []);
        $input = $data['meta'] ?? [];

        if ($list->hasProbability()) {
            $meta += ['outcome' => null];
            if (($meta['outcome'] ?? null) === null) {
                $meta['probability'] = (int) $input['probability'];
            }
        }
        // Planning kind of an action type: kept unless the form sends it (null clears it).
        if ($list->hasPlanning() && array_key_exists('planning', $input)) {
            $meta['planning'] = $input['planning'];
            if ($meta['planning'] === null) {
                unset($meta['planning']);
            }
        }
        // Stage of a sent quote: kept unless the form sends it; never a closing stage.
        if ($list->hasQuoteStage() && array_key_exists('quote_stage', $input)) {
            if ((bool) $input['quote_stage']) {
                if (($meta['outcome'] ?? null) !== null) {
                    throw ValidationException::withMessages(['meta.quote_stage' => 'Une étape gagnée ou perdue ne peut pas être l\'étape d\'un devis envoyé.']);
                }
                $meta['quote_stage'] = true;
            } else {
                unset($meta['quote_stage']);
            }
        }
        // « Joint » flag of an action result: kept unless the form sends it.
        if ($list->hasReached() && array_key_exists('reached', $input)) {
            if ((bool) $input['reached']) {
                $meta['reached'] = true;
            } else {
                unset($meta['reached']);
            }
        }
        // Force majeure flag of an abandon reason: kept unless the form sends it.
        if ($list->hasForceMajeure() && array_key_exists('force_majeure', $input)) {
            if ((bool) $input['force_majeure']) {
                $meta['force_majeure'] = true;
            } else {
                unset($meta['force_majeure']);
            }
        }
        // Contractant funded by default by a funding type: kept unless the form sends it (null clears it).
        if ($list->hasDefaultFor() && array_key_exists('default_for', $input)) {
            $meta['default_for'] = $input['default_for'];
            if ($meta['default_for'] === null) {
                unset($meta['default_for']);
            }
        }
        // Funding type billed outside the TMS (CPF…): kept unless the form sends it.
        if ($list->hasExternalBilling() && array_key_exists('external_billing', $input)) {
            if ((bool) $input['external_billing']) {
                $meta['external_billing'] = true;
            } else {
                unset($meta['external_billing']);
            }
        }
        // Funding type paid by the OPCO of the collective agreement: kept unless the form sends it.
        if ($list->hasOpco() && array_key_exists('opco', $input)) {
            if ((bool) $input['opco']) {
                $meta['opco'] = true;
            } else {
                unset($meta['opco']);
            }
        }
        // Funding type a funding mode gives the dossier: kept unless the form sends it (null clears it).
        if ($list->hasFundingType() && array_key_exists('funding_type_id', $input)) {
            $meta['funding_type_id'] = $input['funding_type_id'];
            if ($meta['funding_type_id'] === null) {
                unset($meta['funding_type_id']);
            }
        }
        // Organisation type showing the commercial status: kept unless the form sends it.
        if ($list->hasClient() && array_key_exists('client', $input)) {
            if ((bool) $input['client']) {
                $meta['client'] = true;
            } else {
                unset($meta['client']);
            }
        }
        if (array_key_exists('color', $input)) {
            $meta['color'] = $input['color'];
        }
        if (($meta['color'] ?? null) === null) {
            unset($meta['color']);
        }

        return $meta === [] ? null : $meta;
    }

    /**
     * At most one stage of a sent quote: flagging one clears the others.
     */
    private function keepOneQuoteStage(ReferenceItem $item): void
    {
        if (! $item->list->hasQuoteStage() || ($item->meta['quote_stage'] ?? false) !== true) {
            return;
        }

        ReferenceItem::withTrashed()->where('list', $item->list)->whereKeyNot($item->getKey())->get()
            ->filter(fn (ReferenceItem $other) => array_key_exists('quote_stage', $other->meta ?? []))
            ->each(function (ReferenceItem $other): void {
                $meta = $other->meta ?? [];
                unset($meta['quote_stage']);
                $other->update(['meta' => $meta === [] ? null : $meta]);
            });
    }

    /**
     * At most one funding type per kind of contractant: flagging one clears
     * the others of that kind.
     */
    private function keepOneDefaultFor(ReferenceItem $item): void
    {
        $kind = $item->meta['default_for'] ?? null;
        if (! $item->list->hasDefaultFor() || $kind === null) {
            return;
        }

        ReferenceItem::withTrashed()->where('list', $item->list)->whereKeyNot($item->getKey())->get()
            ->filter(fn (ReferenceItem $other) => ($other->meta['default_for'] ?? null) === $kind)
            ->each(function (ReferenceItem $other): void {
                $meta = $other->meta ?? [];
                unset($meta['default_for']);
                $other->update(['meta' => $meta === [] ? null : $meta]);
            });
    }

    private function assertBelongs(ReferenceList $list, ReferenceItem $item): void
    {
        abort_unless($item->list === $list, 404);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['item' => $message]);
    }

    private function back(string $status): RedirectResponse
    {
        return to_route('admin.references.index')->with('status', $status);
    }
}
