<?php

namespace App\Modules\Sessions\Summary;

/**
 * A progress card of the « Synthèse » of a session (spec § 4.2): its title,
 * a value (« 42 h / 70 h », a count), a state, a short sentence and the
 * step of the session page it leads to (with an anchor in that step).
 */
final readonly class Card
{
    public const DONE = 'done';

    public const TODO = 'todo';

    public const ALERT = 'alert';

    public const NA = 'na';

    /**
     * @param  string  $state  done|todo|alert|na
     * @param  string  $step  planning|stagiaires|suivi
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $value,
        public string $state,
        public string $text,
        public string $step,
        public ?string $anchor = null,
    ) {}

    /** To do or in alert. */
    public function isPending(): bool
    {
        return $this->state === self::TODO || $this->state === self::ALERT;
    }

    /**
     * @return array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'value' => $this->value,
            'state' => $this->state,
            'text' => $this->text,
            'step' => $this->step,
            'anchor' => $this->anchor,
        ];
    }
}
