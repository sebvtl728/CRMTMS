<?php

namespace App\Modules\Sessions\Resources;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Sessions\Planning\SeanceOutlook;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rooms of the organisme. A room used somewhere (a séance, task 3,
 * declared with registerUsage) is never deleted: it is deactivated.
 */
final class Rooms
{
    public const IN_USE = 'Cette salle est utilisée : désactivez-la plutôt que de la supprimer.';

    /** @var array<string, Closure(string): bool> */
    private array $usages = [];

    /**
     * Active rooms, by name (case-insensitive).
     *
     * @return Collection<int, Room>
     */
    public static function active(): Collection
    {
        return Room::query()->where('active', true)->orderByRaw('lower(name)')->orderBy('id')->get();
    }

    /**
     * Every room out of the trash, by name.
     *
     * @return Collection<int, Room>
     */
    public static function all(): Collection
    {
        return Room::query()->orderByRaw('lower(name)')->orderBy('id')->get();
    }

    /**
     * @param  array{name: string, capacity: int|null, address: string|null, active: bool}  $data
     */
    public static function save(?Room $room, array $data): Room
    {
        return DB::transaction(function () use ($room, $data): Room {
            $room ??= new Room;
            $room->fill($data);
            $shown = $room->exists && $room->isDirty(['name', 'address']);
            $room->save();
            if ($shown) {
                // The place of the Outlook copies of its séances to come.
                SeanceOutlook::touchRoom($room->id);
            }

            return $room;
        });
    }

    /**
     * Soft delete, refused (409) for a room in use.
     */
    public static function delete(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $locked = Room::query()->whereKey($room->getKey())->lockForUpdate()->firstOrFail();
            if (self::inUse($locked->id)) {
                throw new UserFacingHttpException(409, self::IN_USE);
            }
            $locked->delete();
        });
    }

    /**
     * Declares what uses a room (the séances of task 3).
     *
     * @param  Closure(string): bool  $used  id of the room => whether it is used
     */
    public static function registerUsage(string $key, Closure $used): void
    {
        self::registry()->usages[$key] = $used;
    }

    public static function inUse(string $roomId): bool
    {
        foreach (self::registry()->usages as $used) {
            if ($used($roomId)) {
                return true;
            }
        }

        return false;
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        return $app->make(self::class);
    }
}
