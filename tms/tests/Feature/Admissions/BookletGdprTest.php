<?php

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Booklet\BookletLink;
use App\Modules\Admissions\Booklet\BookletLinks;
use App\Modules\Admissions\Booklet\BookletMonth;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Booklet\BookletVisits;
use App\Modules\Admissions\Crm\CandidatureLinks;
use App\Modules\Admissions\Gdpr\CandidatureAnonymisation;
use App\Modules\Admissions\Gdpr\PersonCandidaturesExport;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Storage::fake('local');
    Queue::fake();
    Company::factory()->default()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-15 10:00', 'Europe/Paris'));
    $this->c = bookletApprentice();
    $this->path = TenantStorage::path('booklet_signatures', 'x.png');
    Storage::disk(TenantStorage::DISK)->put($this->path, 'png');
    $this->link = app(BookletLinks::class)->send($this->c, '2027-01', 'tutor', admin());
    BookletMonths::page($this->c, '2027-01', 'company')->forceFill([
        'apprentice_entries' => ['w1' => 'Mise en place du buffet'], 'observations' => ['w1' => 'Très investie'],
        'apprentice_signed_at' => now(), 'apprentice_signature_path' => $this->path, 'apprentice_signer_name' => 'Léa DURAND', 'apprentice_ip' => '10.0.0.1',
        'tutor_signed_at' => now(), 'tutor_signer_name' => 'Paul MARTIN', 'tutor_link_id' => $this->link->id, 'tutor_ip' => '10.0.0.2',
    ])->save();
    BookletVisits::create($this->c, ['visited_on' => '2027-02-10', 'points' => 'hygiène'], admin());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('exports the booklet with the candidature', function () {
    $export = PersonCandidaturesExport::for($this->c->person_id)['candidatures'][0]['livret'];
    expect($export['mois'][0]['travaux_apprenti']['w1'])->toBe('Mise en place du buffet')
        ->and($export['visites'][0]['points'])->toBe('hygiène');
});

it('erases texts, drawings, names and e-mails when the apprentice is anonymised', function () {
    app(CandidatureAnonymisation::class)->anonymise($this->c->fresh());

    $page = BookletMonth::query()->sole();
    expect($page->apprentice_entries)->toBeNull()->and($page->observations)->toBeNull()
        ->and($page->apprentice_signer_name)->toBeNull()->and($page->apprentice_ip)->toBeNull()
        ->and($page->apprentice_signed_at)->not->toBeNull()
        ->and(BookletVisits::list($this->c)[0]['points'])->toBe('')
        ->and($this->link->fresh()->email)->toBeNull()
        ->and($this->link->fresh()->closed_at)->not->toBeNull();
    Storage::disk(TenantStorage::DISK)->assertMissing($this->path);
});

it('erases the name and e-mails of an anonymised tutor, and moves his links on a merge', function () {
    $tutorId = AlternanceContract::query()->value('tutor_person_id');
    app(CandidatureAnonymisation::class)->forPeople([$tutorId]);

    expect(BookletMonth::query()->sole()->tutor_signer_name)->toBeNull()
        ->and(BookletMonth::query()->sole()->apprentice_signer_name)->toBe('Léa DURAND')
        ->and(BookletLink::query()->sole()->email)->toBeNull();

    $other = adPerson('Martin', ['first_name' => 'Paul', 'birth_date' => null]);
    CandidatureLinks::merge('person', $other->id, $tutorId);
    expect(BookletLink::query()->sole()->person_id)->toBe($other->id);
});
