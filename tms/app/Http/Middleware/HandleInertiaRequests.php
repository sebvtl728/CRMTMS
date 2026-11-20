<?php

namespace App\Http\Middleware;

use App\Modules\Admissions\Candidatures\IdentifiedBadge;
use App\Modules\Connectors\Calendar\CalendarSources;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Backups\BackupStatus;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\TodoQuery;
use App\Modules\Crm\Permissions as CrmPermissions;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user() instanceof User ? $request->user() : null;
        // One trainer lookup per request (menu « Mon planning », « Mon agenda »).
        $trainer = $user === null ? null : Trainers::forUser($user);

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            // Organisme identity (sidebar, tab title): signed-in users only, never before login.
            'brand' => fn () => $user === null ? null : app(Brand::class)->current(),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'role' => $user->role()?->label(),
                    'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                ],
                // Names only: the menu and pages adapt, the server stays the authority.
                'permissions' => $user === null ? [] : array_values(array_filter(Permissions::all(), fn (string $p) => $user->can($p))),
                // Every right on « Ses séances » only: the menu is « Mon planning » alone.
                'trainer_only' => $user !== null && Scopes::trainerOnly($user),
                // Linked to a trainer: « Mon planning » in the menu.
                'my_planning' => $trainer !== null,
                // « Mon agenda » in the settings: CRM actions or a calendar source (a trainer's séances);
                // the sources are asked only for a user who is neither of the CRM nor a trainer.
                'calendar_feed' => $user !== null && ($trainer !== null || $user->can(CrmPermissions::ACTIVITIES_VIEW) || CalendarSources::serves($user)),
            ],
            // Menu badge of « À faire »: overdue + today, cached 60 s (lazy: skipped by partial reloads).
            'todo_count' => fn () => $user !== null && $user->can(CrmPermissions::ACTIVITIES_VIEW) ? TodoQuery::badge($user) : 0,
            // Menu counter of « Candidats »: candidatures still « Identifié » in a DAS responsible's DAS (lazy, cached 60 s).
            'candidates_badge' => fn () => $user !== null ? IdentifiedBadge::count($user) : 0,
            'backups_enabled' => config('tms.mode') !== 'multi',
            // Banner of the dashboard: last backup failed, too old or unknown (administrators, self-hosted only).
            'backup_alert' => fn () => $user !== null && config('tms.mode') !== 'multi' && $user->can(Permissions::SETTINGS_MANAGE) ? BackupStatus::alert() : null,
        ]);
    }
}
