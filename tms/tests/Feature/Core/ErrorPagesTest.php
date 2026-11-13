<?php

use App\Exceptions\UserFacingHttpException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Route;

it('error pages never show framework english messages', function () {
    // 429: the activation route is throttled (10 per minute) and answers 404 for an unknown token.
    foreach (range(1, 10) as $i) {
        $this->get('/activation/'.str_repeat('a', 128))->assertNotFound();
    }
    $throttled = $this->get('/activation/'.str_repeat('a', 128))->assertStatus(429);
    expect($throttled->getContent())->toContain('Trop de tentatives')
        ->not->toContain('Too Many Attempts');

    // 405: wrong method on an existing route.
    $wrong = $this->actingAs(admin(twoFactor: true))->post('/admin/users')->assertStatus(405);
    expect($wrong->getContent())->toContain('Méthode non autorisée')
        ->not->toContain('Supported methods')->not->toContain('admin/users')->not->toContain('POST');

    // 413
    Route::middleware('web')->get('/_test/413', fn () => throw new PostTooLargeException);
    $big = $this->get('/_test/413')->assertStatus(413);
    expect($big->getContent())->toContain('Fichier trop volumineux');
});

it('shows only the french message of the application own aborts', function () {
    Route::middleware('web')->get('/_test/own', fn () => throw new UserFacingHttpException(422, 'Curseur invalide.'));
    Route::middleware('web')->get('/_test/english', fn () => abort(403, 'This action is unauthorized.'));

    $this->get('/_test/own')->assertStatus(422)->assertSee('Curseur invalide.');
    $this->get('/_test/english')->assertForbidden()->assertDontSee('This action is unauthorized.')->assertSee('droits nécessaires');
});
