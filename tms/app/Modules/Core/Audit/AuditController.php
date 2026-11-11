<?php

namespace App\Modules\Core\Audit;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only journal of the current organisme (permission audit.view).
 */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'uuid'],
            'event' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', now()->parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', now()->parse($v)->endOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'event' => $log->event,
                'user' => $log->user?->name,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip' => $log->ip,
            ]);

        return Inertia::render('admin/audit/index', [
            'logs' => $logs,
            'filters' => [
                'user' => $filters['user'] ?? '',
                'event' => $filters['event'] ?? '',
                'type' => $filters['type'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'types' => AuditLog::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
        ]);
    }
}
