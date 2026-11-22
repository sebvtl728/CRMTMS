<?php

use App\Exceptions\UserFacingHttpException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    tenant();
    Route::middleware(['web', 'auth'])->post('/_test/refused', fn () => throw new UserFacingHttpException(409, 'Ce document est annulé : il ne peut pas être envoyé.'));
    Route::middleware(['web', 'auth'])->get('/_test/refused', fn () => throw new UserFacingHttpException(409, 'Refus en lecture.'));
});

it('turns a business refusal of an Inertia action into a message on the page', function () {
    $this->actingAs(admin(twoFactor: true))->from('/dashboard')
        ->post('/_test/refused', [], ['X-Inertia' => 'true'])
        ->assertRedirect('/dashboard')->assertSessionHas('error', 'Ce document est annulé : il ne peut pas être envoyé.');
});

it('shares the success and error messages with every page', function () {
    $this->actingAs(admin(twoFactor: true))->withSession(['status' => 'Enregistré.'])->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('flash.status', 'Enregistré.')->where('flash.error', null));
});

it('keeps the error page for a plain visit and the JSON answer for fetch', function () {
    $this->actingAs(admin(twoFactor: true))->get('/_test/refused')->assertStatus(409);
    $this->postJson('/_test/refused')->assertStatus(409)->assertJsonPath('message', 'Ce document est annulé : il ne peut pas être envoyé.');
});
