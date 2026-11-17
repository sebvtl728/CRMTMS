<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Backups\BackupStatus;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    $this->ops = sys_get_temp_dir().'/tms-ops-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->ops);
    config(['tms.ops_path' => $this->ops]);
});

afterEach(fn () => File::deleteDirectory($this->ops));

function writeStatus(array $overrides = []): void
{
    file_put_contents(config('tms.ops_path').'/backup-status.json', json_encode(array_merge([
        'finished_at' => now()->subHours(2)->toIso8601String(),
        'success' => true,
        'snapshot_id' => 'abcdef1234567890',
        'error' => null,
        'version' => '0.8.2',
        'size_bytes' => 5 * 1024 * 1024,
        'remote' => ['configured' => true, 'success' => true],
        'snapshots' => [['id' => 'abcdef1234567890', 'time' => '2026-11-17T01:30:00+00:00', 'tags' => ['manuel', 'version:0.8.2']]],
    ], $overrides)));
}

it('is forbidden without settings.manage and requires authentication', function () {
    $this->get('/admin/backups')->assertRedirect('/login');
    $this->actingAs(commercial())->get('/admin/backups')->assertForbidden();
    $this->actingAs(commercial())->post('/admin/backups/request')->assertForbidden();
});

it('does not exist in multi mode', function () {
    config(['tms.mode' => 'multi']);
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get('/admin/backups')->assertNotFound();
    $this->actingAs($admin)->post('/admin/backups/request')->assertNotFound();
});

it('shows the last backup, the snapshots and the remote state', function () {
    writeStatus();

    $this->actingAs(admin(twoFactor: true))->get('/admin/backups')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('admin/backups')
            ->where('last.success', true)
            ->where('last.size', '5 Mo')
            ->where('last.version', '0.8.2')
            ->where('last.snapshot', 'abcdef12')
            ->where('last.remote.configured', true)
            ->where('stale', false)
            ->where('running', false)
            ->where('pending', false)
            ->has('snapshots', 1)
            ->where('snapshots.0.tags', ['manuel', 'version:0.8.2']));
});

it('shows a failure with its message', function () {
    writeStatus(['success' => false, 'error' => 'Le dépôt est verrouillé.']);

    $this->actingAs(admin(twoFactor: true))->get('/admin/backups')
        ->assertInertia(fn (Assert $p) => $p->where('last.success', false)->where('last.error', 'Le dépôt est verrouillé.'));
    expect(BackupStatus::alert())->toBe('La dernière sauvegarde a échoué : Le dépôt est verrouillé.');
});

it('treats a missing or corrupt status file as unknown', function () {
    expect(BackupStatus::read())->toBeNull()->and(BackupStatus::alert())->toStartWith('Aucune sauvegarde connue');

    file_put_contents($this->ops.'/backup-status.json', '{not json');
    expect(BackupStatus::read())->toBeNull();

    file_put_contents($this->ops.'/backup-status.json', '{"success":true}');
    expect(BackupStatus::read())->toBeNull();

    $this->actingAs(admin(twoFactor: true))->get('/admin/backups')
        ->assertInertia(fn (Assert $p) => $p->where('last', null)->where('stale', true));
});

it('warns when the last success is older than 36 hours', function () {
    writeStatus(['finished_at' => now()->subHours(37)->toIso8601String()]);
    expect(BackupStatus::stale())->toBeTrue()->and(BackupStatus::alert())->toContain('36 heures');

    writeStatus(['finished_at' => now()->subHours(35)->toIso8601String()]);
    expect(BackupStatus::stale())->toBeFalse()->and(BackupStatus::alert())->toBeNull();
});

it('shares the banner with settings managers only', function () {
    writeStatus(['success' => false, 'error' => 'Échec.']);

    $this->actingAs(admin(twoFactor: true))->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('backup_alert', 'La dernière sauvegarde a échoué : Échec.')->where('backups_enabled', true));
    $this->actingAs(commercial())->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('backup_alert', null));
});

it('writes the request once and audits it', function () {
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->post('/admin/backups/request')
        ->assertRedirect('/admin/backups')
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Sauvegarde demandée'));

    $path = $this->ops.'/backup-request.json';
    expect($path)->toBeFile();
    $request = json_decode(file_get_contents($path), true);
    expect($request['requested_by_id'])->toBe($admin->getKey())->and($request['requested_at'])->toBeString();
    expect(glob($this->ops.'/.request-*'))->toBe([]);

    $log = AuditLog::query()->where('event', 'backup.requested')->sole();
    expect($log->user_id)->toBe($admin->getKey());

    $first = file_get_contents($path);
    file_put_contents($path, $first.' ');
    $this->actingAs($admin)->post('/admin/backups/request')
        ->assertRedirect('/admin/backups')
        ->assertSessionHas('status', 'Une sauvegarde est déjà demandée.');
    expect(file_get_contents($path))->toBe($first.' ')
        ->and(AuditLog::query()->where('event', 'backup.requested')->count())->toBe(1);

    $this->actingAs($admin)->get('/admin/backups')->assertInertia(fn (Assert $p) => $p->where('pending', true));
});

it('refuses a request while a backup runs, ignoring a stale marker', function () {
    $admin = admin(twoFactor: true);
    file_put_contents($this->ops.'/backup-running.json', json_encode(['started_at' => now()->subHours(7)->toIso8601String(), 'tag' => 'auto']));
    expect(BackupStatus::running())->toBeFalse();

    file_put_contents($this->ops.'/backup-running.json', json_encode(['started_at' => now()->subMinutes(5)->toIso8601String(), 'tag' => 'auto']));
    expect(BackupStatus::running())->toBeTrue();

    $this->actingAs($admin)->post('/admin/backups/request')->assertSessionHas('status', 'Une sauvegarde est déjà demandée.');
    expect($this->ops.'/backup-request.json')->not->toBeFile();
});

it('shows a French error when the ops folder is not usable', function () {
    config(['tms.ops_path' => '/proc/tms-no-such-dir/ops']);

    $this->actingAs(admin(twoFactor: true))->post('/admin/backups/request')
        ->assertRedirect('/admin/backups')
        ->assertSessionHasErrors(['request' => BackupStatus::ERROR_NOT_READY]);
    expect(AuditLog::query()->where('event', 'backup.requested')->count())->toBe(0);
});

it('treats a request nobody picked up for 10 minutes as stale and accepts a new one', function () {
    $admin = admin(twoFactor: true);
    $path = $this->ops.'/backup-request.json';
    file_put_contents($path, json_encode(['requested_at' => now()->subMinutes(11)->toIso8601String(), 'requested_by_id' => 'x']));
    touch($path, now()->subMinutes(11)->getTimestamp());

    expect(BackupStatus::requestPending())->toBeFalse()->and(BackupStatus::requestStale())->toBeTrue();
    $this->actingAs($admin)->get('/admin/backups')
        ->assertInertia(fn (Assert $p) => $p->where('pending', false)->where('request_stale', true));

    $this->actingAs($admin)->post('/admin/backups/request')
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Sauvegarde demandée'));
    expect(json_decode(file_get_contents($path), true)['requested_by_id'])->toBe($admin->getKey())
        ->and(BackupStatus::requestPending())->toBeTrue()
        ->and(BackupStatus::requestStale())->toBeFalse();
    $this->actingAs($admin)->get('/admin/backups')
        ->assertInertia(fn (Assert $p) => $p->where('pending', true)->where('request_stale', false));

    // Nine minutes: still waiting for the host.
    touch($path, now()->subMinutes(9)->getTimestamp());
    expect(BackupStatus::requestPending())->toBeTrue()->and(BackupStatus::requestStale())->toBeFalse();
});

it('never creates the ops folder itself: the host does', function () {
    $missing = $this->ops.'/absent/ops';
    config(['tms.ops_path' => $missing]);

    $this->actingAs(admin(twoFactor: true))->post('/admin/backups/request')
        ->assertSessionHasErrors(['request' => BackupStatus::ERROR_NOT_READY]);
    expect($missing)->not->toBeDirectory()->and($this->ops.'/absent')->not->toBeDirectory();
});

it('rejects a finished_at more than 5 minutes in the future', function () {
    writeStatus(['finished_at' => now()->addMinutes(6)->toIso8601String()]);
    expect(BackupStatus::read())->toBeNull()->and(BackupStatus::alert())->toStartWith('Aucune sauvegarde connue');

    writeStatus(['finished_at' => now()->addMinutes(4)->toIso8601String()]);
    expect(BackupStatus::read())->not->toBeNull();
});

it('caps the error at 500 characters and ignores a negative size', function () {
    writeStatus(['success' => false, 'error' => str_repeat('é', 800), 'size_bytes' => -1]);
    $status = BackupStatus::read();

    expect(mb_strlen($status['error']))->toBe(500)
        ->and($status['error'])->toBe(str_repeat('é', 500))
        ->and($status['size_bytes'])->toBeNull();
    $this->actingAs(admin(twoFactor: true))->get('/admin/backups')
        ->assertInertia(fn (Assert $p) => $p->where('last.size', null));

    writeStatus(['size_bytes' => 0]);
    expect(BackupStatus::read()['size_bytes'])->toBe(0);
});
