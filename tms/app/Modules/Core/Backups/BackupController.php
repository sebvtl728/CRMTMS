<?php

namespace App\Modules\Core\Backups;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Administration › Sauvegardes » (settings.manage, self-hosted install only): state written by the
 * host, on-demand backup request picked up by the host.
 */
class BackupController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function show(Request $request): Response
    {
        $this->ensureSingleMode();
        $status = BackupStatus::read();

        return Inertia::render('admin/backups', [
            'last' => $status === null ? null : [
                'finished_at' => $status['finished_at']->toIso8601String(),
                'success' => $status['success'],
                'error' => $status['error'],
                'version' => $status['version'],
                'size' => $status['size_bytes'] === null ? null : self::size($status['size_bytes']),
                'snapshot' => $status['snapshot_id'] === null ? null : substr($status['snapshot_id'], 0, 8),
                'remote' => $status['remote'],
            ],
            'stale' => BackupStatus::stale($status),
            'snapshots' => array_map(fn (array $s) => [
                'id' => substr($s['id'], 0, 8),
                'time' => $s['time']->toIso8601String(),
                'tags' => $s['tags'],
            ], $status['snapshots'] ?? []),
            'running' => BackupStatus::running(),
            'pending' => BackupStatus::requestPending(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function request(Request $request): RedirectResponse
    {
        $this->ensureSingleMode();
        if (BackupStatus::running() || BackupStatus::requestPending()) {
            return to_route('admin.backups.show')->with('status', 'Une sauvegarde est déjà demandée.');
        }

        $user = $request->user();
        if (! BackupStatus::writeRequest((string) $user?->getKey())) {
            return to_route('admin.backups.show')->withErrors(['request' => BackupStatus::ERROR_NOT_READY]);
        }
        $this->audit->record('backup.requested');

        return to_route('admin.backups.show')->with('status', 'Sauvegarde demandée : elle démarre dans quelques minutes.');
    }

    private function ensureSingleMode(): void
    {
        abort_if(config('tms.mode') === 'multi', 404);
    }

    /** Human-readable French size (Mo / Go). */
    public static function size(int $bytes): string
    {
        $format = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', "\u{202F}"), '0'), ',');

        return match (true) {
            $bytes >= 1024 ** 3 => $format($bytes / 1024 ** 3).' Go',
            $bytes >= 1024 ** 2 => $format($bytes / 1024 ** 2).' Mo',
            $bytes >= 1024 => $format($bytes / 1024).' Ko',
            default => $bytes.' octets',
        };
    }
}
