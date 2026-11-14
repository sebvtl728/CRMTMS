<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The trainings of the catalogue an opportunity is about, and the amount
 * they propose: Σ price of the training for the account (entreprise or
 * particulier) × quantity. A training without that price counts 0 and is
 * named in « missing ». The proposal is never written: the user applies it
 * to the amount of the opportunity if he wants to.
 *
 * Trainings are read through Catalogue\Trainings only. An inactive training
 * stays on the opportunity that already has it, and cannot be added.
 */
final class OpportunityTrainings
{
    public const MAX_LINES = 50;

    /** Keeps Σ price × quantity within an integer (price ≤ 10¹² cents). */
    public const MAX_QUANTITY = '9999.99';

    /**
     * Replaces the lines of the opportunity (order kept), each change journaled.
     *
     * @param  list<array{training_id: string, quantity: string}>  $lines  validated (see rules())
     */
    public static function sync(Opportunity $opportunity, array $lines): void
    {
        DB::transaction(function () use ($opportunity, $lines): void {
            $existing = OpportunityTraining::query()->where('opportunity_id', $opportunity->id)->get()->keyBy('training_id');
            $kept = array_column($lines, 'training_id');

            foreach ($existing as $trainingId => $line) {
                if (! in_array($trainingId, $kept, true)) {
                    $line->delete();
                }
            }

            foreach ($lines as $position => $data) {
                $line = $existing->get($data['training_id']) ?? new OpportunityTraining(['opportunity_id' => $opportunity->id, 'training_id' => $data['training_id']]);
                $line->fill(['quantity' => $data['quantity'], 'position' => $position])->save();
            }
        });
    }

    /**
     * Amount proposed by the saved lines, in cents.
     */
    public static function estimate(Opportunity $opportunity): int
    {
        return self::proposal($opportunity)['amount_cents'];
    }

    /**
     * The proposal of the saved lines, or of $lines (typed on the form, not saved).
     *
     * @param  list<array{training_id: string, quantity: string}>|null  $lines
     * @return array{amount_cents: int, amount_display: string, missing: list<string>}
     */
    public static function proposal(Opportunity $opportunity, ?array $lines = null): array
    {
        $lines ??= self::stored($opportunity);
        $trainings = app(Trainings::class);
        $total = 0;
        $missing = [];

        foreach ($lines as $line) {
            $training = $trainings->find($line['training_id']);
            if ($training === null) {
                continue;
            }
            $cents = self::lineCents($training, $line['quantity'], $opportunity->isIndividual());
            if ($cents === null) {
                $missing[] = $training->title;
            }
            $total += $cents ?? 0;
        }

        return ['amount_cents' => $total, 'amount_display' => self::display($total), 'missing' => $missing];
    }

    /**
     * The lines as the fiche shows them, in order.
     *
     * @return list<array{id: string, training_id: string, code: ?string, title: string, das: ?string, quantity: string, price_cents: ?int, amount_cents: ?int, is_active: bool}>
     */
    public static function lines(Opportunity $opportunity): array
    {
        $trainings = app(Trainings::class);
        $rows = [];

        foreach (self::query($opportunity)->get() as $line) {
            $training = $trainings->find($line->training_id);
            if ($training === null) {
                continue;
            }
            $rows[] = [
                'id' => $line->id,
                'training_id' => $training->id,
                'code' => $training->code,
                'title' => $training->title,
                'das' => $training->das_label,
                'quantity' => $line->quantity,
                'price_cents' => self::price($training, $opportunity->isIndividual()),
                'amount_cents' => self::lineCents($training, $line->quantity, $opportunity->isIndividual()),
                'is_active' => $training->is_active,
            ];
        }

        return $rows;
    }

    /**
     * The saved lines as the form edits them.
     *
     * @return list<array{training_id: string, quantity: string}>
     */
    public static function stored(Opportunity $opportunity): array
    {
        return self::query($opportunity)->get()
            ->map(fn (OpportunityTraining $l) => ['training_id' => $l->training_id, 'quantity' => $l->quantity])->values()->all();
    }

    /**
     * Trainings offered on the form: the active ones, plus the inactive ones
     * $current already has (« (inactive) »), by title; with their DAS (to
     * prefill the opportunity's) and their price for the account.
     *
     * @return list<array{value: string, label: string, das_id: string, price_cents: ?int, is_active: bool}>
     */
    public static function options(?Opportunity $current, bool $individual): array
    {
        $trainings = app(Trainings::class);
        $all = $trainings->options();
        $ids = array_map(fn (TrainingData $t) => $t->id, $all);
        $inactive = false;
        foreach ($current === null ? [] : self::stored($current) as $line) {
            $kept = in_array($line['training_id'], $ids, true) ? null : $trainings->find($line['training_id']);
            if ($kept !== null) {
                $all[] = $kept;
                $inactive = true;
            }
        }
        if ($inactive) {
            usort($all, fn (TrainingData $a, TrainingData $b) => strnatcasecmp($a->title, $b->title));
        }

        return array_map(fn (TrainingData $t) => [
            'value' => $t->id,
            'label' => self::label($t),
            'das_id' => $t->das_id,
            'price_cents' => self::price($t, $individual),
            'is_active' => $t->is_active,
        ], $all);
    }

    /**
     * Choices of the « Formation » filter: the active trainings, plus the
     * selected one when it is no longer active.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function filterOptions(?string $selected): array
    {
        $trainings = app(Trainings::class);
        $all = $trainings->options();
        $kept = $selected === null || in_array($selected, array_map(fn (TrainingData $t) => $t->id, $all), true) ? null : $trainings->find($selected);
        if ($kept !== null) {
            $all[] = $kept;
        }

        return array_map(fn (TrainingData $t) => ['value' => $t->id, 'label' => self::label($t)], $all);
    }

    // ------------------------------------------------------------ validation

    /**
     * French typing of the lines (« 2,5 », an empty quantity counts 1).
     */
    public static function normalise(mixed $lines): mixed
    {
        if ($lines === null) {
            return [];
        }
        if (! is_array($lines)) {
            return $lines;
        }

        return array_map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $id = $line['training_id'] ?? null;
            $quantity = Amount::parse($line['quantity'] ?? null);

            return [
                'training_id' => is_string($id) ? trim($id) : $id,
                'quantity' => $quantity === null || $quantity === '' ? '1' : $quantity,
            ];
        }, $lines);
    }

    /**
     * Rules of the « trainings » field of $current (null: a creation).
     *
     * @return array<string, mixed>
     */
    public static function rules(?Opportunity $current): array
    {
        $kept = $current === null ? [] : array_column(self::stored($current), 'training_id');

        return [
            'training_lines' => ['sometimes', 'array', 'max:'.self::MAX_LINES],
            'training_lines.*' => ['array'],
            'training_lines.*.training_id' => ['bail', 'required', 'string', 'distinct', self::available($kept)],
            'training_lines.*.quantity' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'decimal:0,2', 'min:0.01', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'training_lines.array' => 'Cette valeur n\'est pas valide.',
            'training_lines.max' => 'Indiquez au plus '.self::MAX_LINES.' formations.',
            'training_lines.*.array' => 'Cette valeur n\'est pas valide.',
            'training_lines.*.training_id.required' => 'Choisissez une formation.',
            'training_lines.*.training_id.string' => 'Cette formation n\'existe pas.',
            'training_lines.*.training_id.distinct' => 'Cette formation est déjà dans la liste.',
            'training_lines.*.quantity.required' => 'Indiquez une quantité.',
            'training_lines.*.quantity.numeric' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
            'training_lines.*.quantity.regex' => 'Indiquez une quantité, par exemple 1 ou 2,5.',
            'training_lines.*.quantity.decimal' => 'Indiquez au plus deux décimales.',
            'training_lines.*.quantity.min' => 'La quantité doit être supérieure à 0.',
            'training_lines.*.quantity.max' => 'La quantité ne peut pas dépasser 9 999,99.',
        ];
    }

    /**
     * The DAS of the first training of these (normalised) lines, if it exists.
     */
    public static function firstDas(mixed $lines): ?string
    {
        $first = is_array($lines) ? reset($lines) : null;
        $id = is_array($first) ? ($first['training_id'] ?? null) : null;

        return is_string($id) ? app(Trainings::class)->find($id)?->das_id : null;
    }

    /**
     * @param  list<string>  $kept  trainings the opportunity already has
     */
    private static function available(array $kept): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kept): void {
            $training = is_string($value) ? app(Trainings::class)->find($value) : null;
            if ($training === null) {
                $fail('Cette formation n\'existe pas.');
            } elseif (! $training->is_active && ! in_array($training->id, $kept, true)) {
                $fail('Cette formation est désactivée : choisissez-en une autre.');
            }
        };
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return Builder<OpportunityTraining>
     */
    private static function query(Opportunity $opportunity): Builder
    {
        return OpportunityTraining::query()->where('opportunity_id', $opportunity->id)->orderBy('position')->orderBy('id');
    }

    private static function price(TrainingData $training, bool $individual): ?int
    {
        return $individual ? $training->price_individual_cents : $training->price_company_cents;
    }

    /**
     * Price × quantity in cents, rounded half up, without a float; null when
     * the training has no price for the account.
     */
    private static function lineCents(TrainingData $training, string $quantity, bool $individual): ?int
    {
        $price = self::price($training, $individual);
        if ($price === null) {
            return null;
        }

        return intdiv($price * self::hundredths($quantity) + 50, 100);
    }

    /** « 2.5 » → 250 (validated: positive, two decimals at most). */
    private static function hundredths(string $quantity): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $quantity, $m) !== 1) {
            return 0;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** 440000 → « 4 400,00 € ». */
    public static function display(int $cents): string
    {
        return number_format(intdiv($cents, 100), 0, ',', ' ').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).' €';
    }

    private static function label(TrainingData $training): string
    {
        $label = $training->code === null ? $training->title : $training->title.' ('.$training->code.')';

        return $training->is_active ? $label : $label.' (inactive)';
    }
}
