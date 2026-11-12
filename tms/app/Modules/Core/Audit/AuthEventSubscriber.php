<?php

namespace App\Modules\Core\Audit;

use App\Modules\Core\Users\TwoFactorDisabled;
use App\Modules\Core\Users\TwoFactorEnabled;
use App\Modules\Core\Users\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Authentication events -> audit journal. Failed carries the attempted e-mail
 * only; the password of the credentials is never read.
 */
class AuthEventSubscriber
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            TwoFactorEnabled::class => 'onTwoFactorEnabled',
            TwoFactorDisabled::class => 'onTwoFactorDisabled',
        ];
    }

    public function onLogin(Login $event): void
    {
        $this->forUser('auth.login', $event->user);
    }

    public function onLogout(Logout $event): void
    {
        $this->forUser('auth.logout', $event->user);
    }

    public function onFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $user = $event->user instanceof User ? $event->user : null;

        // Bounded: the attempted value comes from an unauthenticated request and
        // lands in the append-only journal.
        $email = is_string($email) ? mb_substr($email, 0, 254) : null;

        $this->recorder->record('auth.failed', null, [], ['email' => $email], $user);
    }

    public function onTwoFactorEnabled(TwoFactorEnabled $event): void
    {
        $this->forUser('auth.2fa_enabled', $event->user);
    }

    public function onTwoFactorDisabled(TwoFactorDisabled $event): void
    {
        $this->forUser('auth.2fa_disabled', $event->user);
    }

    private function forUser(string $audit, mixed $user): void
    {
        if ($user instanceof User) {
            $this->recorder->record($audit, null, [], [], $user);
        }
    }
}
