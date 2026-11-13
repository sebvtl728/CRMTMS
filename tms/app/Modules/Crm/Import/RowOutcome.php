<?php

namespace App\Modules\Crm\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Result of one file row (predicted by a simulation, or done by a run).
 */
final class RowOutcome
{
    /** @var list<array{level: string, text: string}> */
    public array $messages = [];

    public ?string $subjectType = null;

    public ?string $subjectId = null;

    public bool $createdSubject = false;

    /** @var list<array{type: string, id: string, at: string|null}> */
    public array $createdRefs = [];

    /** @var list<array{type: string, id: string, at: string|null}> fiches updated by the row */
    public array $touchedRefs = [];

    /** Simulation: the fiche to update was changed by someone since its import. */
    public bool $modifiedSinceImport = false;

    public function __construct(public string $status = ImportRow::CREATED) {}

    public static function error(string ...$messages): self
    {
        $outcome = new self(ImportRow::ERROR);
        foreach ($messages as $message) {
            $outcome->add('error', $message);
        }

        return $outcome;
    }

    public function add(string $level, string $text): self
    {
        $this->messages[] = ['level' => $level, 'text' => $text];

        return $this;
    }

    public function subject(?Model $model, bool $created = false): self
    {
        if ($model !== null && $model->exists) {
            $this->subjectType = $model->getMorphClass();
            $this->subjectId = (string) $model->getKey();
        }
        $this->createdSubject = $created && $model !== null;

        return $this;
    }

    public function created(Model $model): void
    {
        if ($model->exists) {
            $this->createdRefs[] = self::ref($model);
        }
    }

    public function touched(Model $model): void
    {
        if ($model->exists) {
            $this->touchedRefs[] = self::ref($model);
        }
    }

    /**
     * The fiche and the updated_at the import left it with (stored precision).
     *
     * @return array{type: string, id: string, at: string|null}
     */
    public static function ref(Model $model): array
    {
        return ['type' => $model->getMorphClass(), 'id' => (string) $model->getKey(), 'at' => self::stamp($model)];
    }

    public static function stamp(Model $model): ?string
    {
        $at = $model->getAttribute('updated_at');

        if (! $at instanceof \DateTimeInterface) {
            return null;
        }
        // The precision the column keeps: seconds, or microseconds (notes).
        $format = str_contains($model->getDateFormat(), '.u') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';

        return Carbon::instance($at)->setTimezone(config('app.timezone'))->format($format);
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(fn (array $m) => $m['text'], $this->messages);
    }
}
