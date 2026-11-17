<?php

namespace App\Modules\Sessions\Planning;

/**
 * An alert on a séance (planned or being typed): it warns, it never blocks.
 * Types: seance_overlap (another séance of the session), trainer_busy, trainer_unavailable, room_busy, room_capacity,
 * placement, holiday, closure, trainee_busy. The message is French; it
 * never carries the reason of an unavailability.
 */
final readonly class Conflict
{
    public const TYPES = ['holiday', 'closure', 'placement', 'seance_overlap', 'trainer_busy', 'trainer_unavailable', 'room_busy', 'room_capacity', 'trainee_busy'];

    public function __construct(
        public string $type,
        public string $message,
        public ?string $date = null,
    ) {}

    /**
     * @return array{type: string, message: string, date: string|null}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'message' => $this->message, 'date' => $this->date];
    }
}
