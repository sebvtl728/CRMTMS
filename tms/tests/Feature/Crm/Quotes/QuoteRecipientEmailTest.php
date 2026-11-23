<?php

use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Quotes\QuoteMail;
use App\Modules\Crm\Quotes\QuoteRecipient;

beforeEach(function () {
    App\Modules\Crm\CrmServiceProvider::registerReferenceUsage();
    App\Modules\Core\References\CapFormationReferenceSeeder::run(tenant());
    $this->actingAs(commercial());
});

it('addresses the quote to the work e-mail of the interlocuteur first', function () {
    $quote = sendableQuote(email: 'lea.perso@example.test');
    Affiliation::query()->where('person_id', $quote->contact_person_id)->update(['work_email' => 'lea@bistrot.test']);

    expect(QuoteRecipient::current($quote->fresh())['email'])->toBe('lea@bistrot.test')
        ->and(QuoteMail::defaults($quote->fresh(), QuoteMail::KIND_SEND)['to'])->toBe('lea@bistrot.test');
});

it('falls back on the personal e-mail, then on the entreprise e-mail', function () {
    $quote = sendableQuote(email: null);
    expect(QuoteMail::defaults($quote->fresh(), QuoteMail::KIND_SEND)['to'])->toBe('');

    $quote->organisation->update(['email' => 'contact@bistrot.test']);
    expect(QuoteMail::defaults($quote->fresh(), QuoteMail::KIND_SEND)['to'])->toBe('contact@bistrot.test');

    $quote->contact->update(['email' => 'lea.perso@example.test']);
    expect(QuoteMail::defaults($quote->fresh(), QuoteMail::KIND_SEND)['to'])->toBe('lea.perso@example.test');
});
