<?php

namespace App\Modules\Core\Backups;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Backup state exchanged with the host through files in the storage volume (`storage/ops`, outside
 * `storage/app`): the host writes `backup-status.json` and `backup-running.json`, the application
 * writes `backup-request.json`. Nothing here is tenant data.
 */
final class BackupStatus
{
    /** A success older than this is a warning. */
    public const STALE_AFTER_HOURS = 36;

    /** A running marker older than this is a leftover of a crash. */
    public const RUNNING_STALE_HOURS = 6;

    public const ERROR_NOT_READY = "Impossible de demander une sauvegarde : le serveur n'est pas prêt (dossier ops absent).";

    public static function directory(): string
    {
        return rtrim((string) (config('tms.ops_path') ?: storage_path('ops')), '/');
    }

    /**
     * The last backup, null when the file is missing, unreadable or invalid.
     *
     * @return array{finished_at: CarbonImmutable, success: bool, snapshot_id: ?string, error: ?string, version: ?string, size_bytes: ?int, remote: array{configured: bool, success: ?bool}, snapshots: list<array{id: string, time: CarbonImmutable, tags: list<string>}>}|null
     */
    public static function read(): ?array
    {
        $data = self::json('backup-status.json');
        if ($data === null || ! is_string($data['finished_at'] ?? null) || ! is_bool($data['success'] ?? null)) {
            return null;
        }
        $finished = self::date($data['finished_at']);
        if ($finished === null) {
            return null;
        }

        $remote = is_array($data['remote'] ?? null) ? $data['remote'] : [];
        $snapshots = [];
        foreach (is_array($data['snapshots'] ?? null) ? $data['snapshots'] : [] as $snapshot) {
            $time = is_array($snapshot) && is_string($snapshot['time'] ?? null) ? self::date($snapshot['time']) : null;
            if ($time === null || ! is_string($snapshot['id'] ?? null)) {
                continue;
            }
            $snapshots[] = [
                'id' => $snapshot['id'],
                'time' => $time,
                'tags' => array_values(array_filter(is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [], 'is_string')),
            ];
        }

        return [
            'finished_at' => $finished,
            'success' => $data['success'],
            'snapshot_id' => is_string($data['snapshot_id'] ?? null) ? $data['snapshot_id'] : null,
            'error' => is_string($data['error'] ?? null) ? $data['error'] : null,
            'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
            'size_bytes' => is_int($data['size_bytes'] ?? null) ? $data['size_bytes'] : null,
            'remote' => [
                'configured' => ($remote['configured'] ?? false) === true,
                'success' => is_bool($remote['success'] ?? null) ? $remote['success'] : null,
            ],
            'snapshots' => array_slice($snapshots, 0, 60),
        ];
    }

    /**
     * Last backup older than 36 hours, or unknown.
     *
     * @param  array{finished_at: CarbonImmutable}|null  $status  already read status (default: read it)
     */
    public static function stale(?array $status = null): bool
    {
        $status ??= self::read();

        return $status === null || $status['finished_at']->lt(CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS));
    }

    /** French banner text, null when the last backup succeeded recently. */
    public static function alert(): ?string
    {
        $status = self::read();
        if ($status === null) {
            return 'Aucune sauvegarde connue : vérifiez que les sauvegardes automatiques fonctionnent.';
        }
        if (! $status['success']) {
            return 'La dernière sauvegarde a échoué'.($status['error'] !== null ? ' : '.$status['error'] : '.');
        }
        if (self::stale($status)) {
            return 'La dernière sauvegarde réussie date de plus de '.self::STALE_AFTER_HOURS.' heures.';
        }

        return null;
    }

    /** A backup is running (fresh marker written by the host). */
    public static function running(): bool
    {
        $data = self::json('backup-running.json');
        $started = $data !== null && is_string($data['started_at'] ?? null) ? self::date($data['started_at']) : null;

        return $started !== null && $started->gt(CarbonImmutable::now()->subHours(self::RUNNING_STALE_HOURS));
    }

    /** A request is waiting for the host. */
    public static function requestPending(): bool
    {
        return is_file(self::directory().'/backup-request.json');
    }

    /** Write the request atomically; false when the folder is missing or not writable. */
    public static function writeRequest(string $userId): bool
    {
        $dir = self::directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }
        if (! is_writable($dir)) {
            return false;
        }

        $temporary = tempnam($dir, '.request-');
        if ($temporary === false) {
            return false;
        }
        $json = json_encode(['requested_at' => CarbonImmutable::now()->toIso8601String(), 'requested_by_id' => $userId], JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $json) === false) {
            @unlink($temporary);

            return false;
        }
        @chmod($temporary, 0664);
        if (! @rename($temporary, $dir.'/backup-request.json')) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private static function json(string $name): ?array
    {
        $path = self::directory().'/'.$name;
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    private static function date(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
