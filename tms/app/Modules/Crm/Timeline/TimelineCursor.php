<?php

namespace App\Modules\Crm\Timeline;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Position in a fiche's « Historique »: the last entry shown. The feed is
 * ordered by (at desc, type desc, id desc), so two entries of different
 * sources at the same instant are never lost nor repeated across pages.
 * Sent to the browser as an opaque base64url JSON string; a string that does
 * not decode to a valid cursor is ignored (first page).
 */
final readonly class TimelineCursor
{
    public function __construct(
        public CarbonImmutable $at,
        public string $type,
        public string $id,
    ) {}

    public static function of(TimelineEntry $entry): self
    {
        return new self($entry->at, $entry->type, (string) ($entry->meta['id'] ?? ''));
    }

    public function encode(): string
    {
        $json = json_encode([
            'at' => $this->at->toIso8601ZuluString('microsecond'),
            'type' => $this->type,
            'id' => $this->id,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The cursor carried by $value, or null when it is not one.
     */
    public static function decode(string $value): ?self
    {
        if ($value === '' || strlen($value) > 512 || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            return null;
        }

        $at = $data['at'] ?? null;
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;
        if (! is_string($at) || ! is_string($type) || ! is_string($id)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) !== 1 || ! Str::isUuid($id)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/', $at) !== 1) {
            return null;
        }

        try {
            $instant = CarbonImmutable::parse($at, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return new self($instant, $type, strtolower($id));
    }
}
