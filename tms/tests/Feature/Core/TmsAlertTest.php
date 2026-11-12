<?php

use App\Modules\Core\Platform\AlertMail;
use Illuminate\Support\Facades\Mail;

it('sends the alert to TMS_ALERT_EMAIL in French', function () {
    Mail::fake();
    config(['tms.alert_email' => 'ops@example.test']);

    $this->artisan('tms:alert', ['message' => 'Sauvegarde échouée', '--subject' => 'Sauvegarde'])
        ->expectsOutputToContain('ops@example.test')
        ->assertSuccessful();

    Mail::assertSent(AlertMail::class, function (AlertMail $mail) {
        return $mail->hasTo('ops@example.test')
            && $mail->hasSubject('Sauvegarde')
            && str_contains($mail->render(), 'Bonjour')
            && str_contains($mail->render(), 'Sauvegarde échouée');
    });
});

it('accepts an explicit recipient', function () {
    Mail::fake();
    config(['tms.alert_email' => null]);

    $this->artisan('tms:alert', ['message' => 'x', '--to' => 'a@example.test'])->assertSuccessful();

    Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->hasTo('a@example.test'));
});

it('fails without a valid recipient', function () {
    Mail::fake();
    config(['tms.alert_email' => null]);

    $this->artisan('tms:alert', ['message' => 'x'])->assertFailed();

    Mail::assertNothingSent();
});
