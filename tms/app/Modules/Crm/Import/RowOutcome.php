<?php

namespace App\Modules\Crm\Import;

use Illuminate\Database\Eloquent\Model;

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

    /** @var list<array{type: string, id: string}> */
    public array $createdRefs = [];

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
            $this->createdRefs[] = ['type' => $model->getMorphClass(), 'id' => (string) $model->getKey()];
        }
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(fn (array $m) => $m['text'], $this->messages);
    }
}
