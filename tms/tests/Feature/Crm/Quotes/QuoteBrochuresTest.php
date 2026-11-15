<?php

use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochureVersion;
use App\Modules\Catalogue\Training;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    fakeQuoteGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/**
 * A brochure with one version written on the private disk, attached to
 * trainings and/or DAS (labels).
 *
 * @param  list<Training>  $trainings
 * @param  list<string>  $das
 */
function quoteBrochure(string $title, string $content = "%PDF-1.4 plaquette\n", array $trainings = [], array $das = [], ?int $size = null): BrochureVersion
{
    $brochure = new Brochure;
    $brochure->forceFill(['title' => $title])->save();

    return quoteBrochureVersion($brochure, $content, $size, true, $trainings, $das);
}

/**
 * @param  list<Training>  $trainings
 * @param  list<string>  $das
 */
function quoteBrochureVersion(Brochure $brochure, string $content, ?int $size = null, bool $current = true, array $trainings = [], array $das = []): BrochureVersion
{
    $path = TenantStorage::path('brochures', $brochure->id.'/'.Str::uuid()->toString().'.pdf');
    Storage::disk(TenantStorage::DISK)->put($path, $content);
    $version = $brochure->versions()->create([
        'path' => $path, 'original_name' => Str::slug($brochure->title).'.pdf', 'size' => $size ?? strlen($content), 'sha256' => hash('sha256', $content),
    ]);
    if ($current) {
        $brochure->forceFill(['current_version_id' => $version->id])->save();
    }
    foreach ($trainings as $training) {
        DB::table('brochure_trainings')->insertOrIgnore(['tenant_id' => $brochure->tenant_id, 'brochure_id' => $brochure->id, 'training_id' => $training->id]);
    }
    foreach ($das as $label) {
        DB::table('brochure_das')->insertOrIgnore(['tenant_id' => $brochure->tenant_id, 'brochure_id' => $brochure->id, 'das_id' => crmItem(ReferenceList::Das, $label)->id]);
    }

    return $version;
}

function quoteTraining(string $title = 'HACCP'): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
}

function trainingQuote(Training $training, int $lines = 1): Quote
{
    $quote = sendableQuote();
    $quote->lines()->delete();
    foreach (range(0, $lines - 1) as $i) {
        $quote->lines()->create([
            'position' => $i, 'training_id' => $training->id, 'das_id' => $training->das_id, 'label' => $training->title, 'quantity' => '1.00',
            'unit' => 'stagiaire', 'unit_price' => '1000.00', 'discount_percent' => '0.00', 'vat_rate' => '20.00', 'total_ht' => '1000.00',
        ]);
    }

    return $quote->refresh();
}

/** Number of files attached to a sent mailable (built first). */
function attachedCount(object $mail): int
{
    (fn () => $this->prepareMailableForDelivery())->call($mail);

    return count($mail->rawAttachments);
}

it('joins the chosen brochures to the e-mail and records them for the reminder', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $training = quoteTraining();
    $haccp = quoteBrochure('HACCP', "%PDF-1.4 haccp\n", [$training]);
    $quote = trainingQuote($training);

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$haccp->id]]))->assertSessionHasNoErrors();

    $mail = Mail::sent(QuoteMail::class)->sole();
    $mail->assertHasAttachedData("%PDF-1.4 haccp\n", 'haccp.pdf', ['mime' => 'application/pdf']);
    expect(attachedCount($mail))->toBe(2)
        ->and(DB::table('quote_brochures')->where('quote_id', $quote->id)->pluck('brochure_version_id')->all())->toBe([$haccp->id]);
});

it('prefills the window with the brochures of the lines, once each, and lets the user search others', function () {
    $this->actingAs(commercial());
    $training = quoteTraining();
    $other = Training::query()->create(['title' => 'Sécurité', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    // Attached to the training AND to its DAS, the training twice in the quote: once.
    $haccp = quoteBrochure('HACCP', trainings: [$training], das: ['Hôtellerie-restauration']);
    quoteBrochure('Catalogue général', trainings: [], das: []);
    $quote = trainingQuote($training, 2);

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->has('brochure_choices', 1)
        ->where('brochure_choices.0.version_id', $haccp->id)
        ->where('brochure_choices.0.title', 'HACCP'));

    $this->getJson('/crm/quote-brochures?q=catalogue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Catalogue général');
    expect($other->title)->toBe('Sécurité');
});

it('joins a brochure once even when it is sent twice in the request', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $training = quoteTraining();
    $haccp = quoteBrochure('HACCP', trainings: [$training], das: ['Hôtellerie-restauration']);
    $quote = trainingQuote($training, 2);

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$haccp->id, $haccp->id]]))->assertSessionHasNoErrors();

    expect(attachedCount(Mail::sent(QuoteMail::class)->sole()))->toBe(2)
        ->and(DB::table('quote_brochures')->where('quote_id', $quote->id)->count())->toBe(1);
});

it('joins nothing when the box is unticked or the field absent', function (array $extra) {
    Mail::fake();
    $this->actingAs(commercial());
    $training = quoteTraining();
    quoteBrochure('HACCP', trainings: [$training]);
    $quote = trainingQuote($training);

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage($extra))->assertSessionHasNoErrors();

    expect(attachedCount(Mail::sent(QuoteMail::class)->sole()))->toBe(1)
        ->and(DB::table('quote_brochures')->count())->toBe(0);
})->with([[[]], [['brochure_version_ids' => []]]]);

it('refuses an unknown brochure version, in French, and sends nothing', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [(string) Str::uuid()]]))
        ->assertStatus(422)->assertJsonValidationErrors(['brochure_version_ids.0' => 'Cette plaquette n\'existe plus.']);

    Mail::assertNothingSent();
    expect($quote->refresh()->status->value)->toBe('draft');
});

it('refuses more than 20 Mo of attachments and leaves the quote a draft', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $a = quoteBrochure('Grosse A', size: 10 * 1024 * 1024);
    $b = quoteBrochure('Grosse B', size: 10 * 1024 * 1024);
    $quote = sendableQuote();

    // The brochures alone already exceed 20 Mo with the quote PDF.
    $response = $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$a->id, $b->id]]));
    $response->assertStatus(422);
    expect(json_encode($response->json(), JSON_UNESCAPED_UNICODE))->toContain('Les pièces jointes dépassent 20 Mo : retirez une plaquette.');

    Mail::assertNothingSent();
    $quote->refresh();
    expect($quote->status->value)->toBe('draft')->and($quote->pdf_path)->toBeNull()
        ->and(DB::table('quote_brochures')->count())->toBe(0);

    // One of them is fine.
    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$a->id]]))->assertSessionHasNoErrors();
    expect($quote->refresh()->status->value)->toBe('sent');
});

it('refuses when the quote pdf tips the total over 20 Mo', function () {
    Mail::fake();
    $this->actingAs(commercial());
    // 20 Mo exactly: the PDF (a few bytes) pushes it over.
    $a = quoteBrochure('Pile', size: 20 * 1024 * 1024);
    $quote = sendableQuote();

    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$a->id]]))->assertStatus(422);

    Mail::assertNothingSent();
    $quote->refresh();
    expect($quote->status->value)->toBe('draft')->and($quote->pdf_path)->toBeNull();
});

it('re-joins the original version at the reminder after the brochure was replaced and trashed', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $training = quoteTraining();
    $v1 = quoteBrochure('HACCP', "%PDF-1.4 version un\n", [$training]);
    $quote = trainingQuote($training);
    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$v1->id]]))->assertSessionHasNoErrors();

    // Replaced by version 2, then the brochure goes to the trash.
    $brochure = $v1->brochure;
    quoteBrochureVersion($brochure, "%PDF-1.4 version deux\n");
    $brochure->delete();

    $this->post("/crm/quotes/{$quote->id}/remind", quoteMessage())->assertSessionHasNoErrors();

    $mails = Mail::sent(QuoteMail::class);
    expect($mails)->toHaveCount(2);
    $reminder = $mails->last();
    $reminder->assertHasAttachedData("%PDF-1.4 version un\n", 'haccp.pdf', ['mime' => 'application/pdf']);
    expect(attachedCount($reminder))->toBe(2)
        ->and(DB::table('quote_brochures')->where('quote_id', $quote->id)->pluck('brochure_version_id')->all())->toBe([$v1->id]);
});

it('joins the same versions again on « Renvoyer »', function () {
    $sent = [];
    quoteSmtp(function ($m) use (&$sent) {
        $sent[] = $m;
        if (count($sent) === 1) {
            throw new RuntimeException('smtp down');
        }
    });
    $this->actingAs(commercial());
    $training = quoteTraining();
    $v1 = quoteBrochure('HACCP', "%PDF-1.4 un\n", [$training]);
    $quote = trainingQuote($training);

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['brochure_version_ids' => [$v1->id]]))->assertSessionHasNoErrors();
    expect($quote->refresh()->email_status)->toBe('failed');

    $this->post("/crm/quotes/{$quote->id}/resend")->assertSessionHasNoErrors();

    expect($quote->refresh()->email_status)->toBe('sent')
        ->and($sent)->toHaveCount(2)
        ->and(count($sent[1]->getAttachments()))->toBe(2)
        ->and($sent[1]->getAttachments()[1]->getBody())->toBe("%PDF-1.4 un\n");
});

it('keeps the behaviour unchanged without any brochure', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('brochure_choices', []));
    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    expect(attachedCount(Mail::sent(QuoteMail::class)->sole()))->toBe(1);
});
