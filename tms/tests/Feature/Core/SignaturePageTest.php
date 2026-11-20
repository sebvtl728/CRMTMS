<?php

use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakeImportDisk();
    tenant();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response("%PDF-1.7 signé\n", 200, ['Content-Type' => 'application/pdf'])]);
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
    $path = TenantStorage::path('documents', '2026/a-signer.pdf');
    Storage::disk(TenantStorage::DISK)->put($path, "%PDF-1.7 à signer\n");
    ['token' => $this->token] = app(Signatures::class)->request('document', '01a1f000-0000-7000-8000-000000000001', $path, hash('sha256', "%PDF-1.7 à signer\n"),
        'Claire Martin', 'claire.martin@hotel-parc.test', null, 'Convention de formation DOC-2026-00001', admin());
    auth()->logout();
});

it('lets the signer read, receive a code and sign without an account', function () {
    $this->get("/signature/{$this->token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('public/signature')
        ->where('state', 'open')->where('title', 'Convention de formation DOC-2026-00001')->where('email', 'c***@hotel-parc.test'));
    $this->get("/signature/{$this->token}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->post("/signature/{$this->token}/code")->assertSessionHas('status');
    preg_match('/\b(\d{6})\b/', Mail::sent(SignatureMail::class)->last()->text, $m);

    $this->post("/signature/{$this->token}/sign", ['code' => $m[1]])->assertSessionHasErrors('accept');
    $this->post("/signature/{$this->token}/sign", ['code' => $m[1], 'accept' => '1'])->assertSessionHasErrors(['drawing' => 'Dessinez votre signature dans le cadre.']);
    $this->post("/signature/{$this->token}/sign", ['code' => '000000', 'accept' => '1', 'drawing' => sigDrawing()])->assertSessionHasErrors(['code' => 'Code incorrect.']);
    $this->post("/signature/{$this->token}/sign", ['code' => $m[1], 'accept' => '1', 'drawing' => sigDrawing()], ['User-Agent' => 'Firefox 140'])
        ->assertRedirect("/signature/{$this->token}")->assertSessionHasNoErrors();

    $this->get("/signature/{$this->token}")->assertInertia(fn (Assert $p) => $p->where('state', 'signed')->where('signed_at', fn ($v) => str_starts_with($v, '19/11/2026')));
    expect($this->get("/signature/{$this->token}/pdf")->assertOk()->getContent())->toBe("%PDF-1.7 signé\n");
});

it('shows the same page for any invalid link', function () {
    $this->get('/signature/'.str_repeat('a', 40))->assertNotFound()->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
    $this->post('/signature/'.str_repeat('a', 40).'/code')->assertNotFound();
    $this->get('/signature/court')->assertNotFound();
});
