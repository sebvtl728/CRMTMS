<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\People\Person;
use App\Modules\Documents\Automation\ConvocationAutomation;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-12-01 07:15', 'Europe/Paris'));
});

/**
 * A confirmed session whose first séance is on $start, with Léa (e-mail) and Tom (none).
 *
 * @return array{session: TrainingSession, lea: Enrolment, tom: Enrolment}
 */
function cvSession(string $start, string $status = 'confirmed'): array
{
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => $start, 'ends_on' => $start, 'status' => $status]);
    plSeance(plModule($session), $start, '09:00', '12:30');
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.durand@example.test']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));

    return ['session' => $session, 'lea' => $lea, 'tom' => $tom];
}

function cvRun(): int
{
    return app(ConvocationAutomation::class)->run(CarbonImmutable::now('Europe/Paris'));
}

it('issues and sends the missing convocations seven days before the start', function () {
    $x = cvSession('2026-12-08');

    expect(cvRun())->toBe(1);
    $docs = Document::query()->where('type', DocumentType::Convocation->value)->get();
    expect($docs)->toHaveCount(2)
        ->and($docs->every(fn (Document $d) => $d->issued_by === null))->toBeTrue()
        ->and($docs->firstWhere('enrolment_id', $x['lea']->id)->status)->toBe(DocumentStatus::Sent)
        ->and($docs->firstWhere('enrolment_id', $x['tom']->id)->status)->toBe(DocumentStatus::Issued);
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('lea.durand@example.test'));
});

it('sends a late confirmed session the next morning, never the day before', function () {
    cvSession('2026-12-04');
    cvSession('2026-12-02');

    expect(cvRun())->toBe(1);
    Mail::assertSentCount(1);
});

it('never sends twice, nor re-issues a cancelled convocation', function () {
    $x = cvSession('2026-12-08');
    cvRun();
    expect(cvRun())->toBe(0);
    Mail::assertSentCount(1);

    $y = cvSession('2026-12-06');
    $doc = app(DocumentIssuer::class)->issueForEnrolment(admin(), DocumentType::Convocation, $y['lea']->id);
    app(DocumentIssuer::class)->cancel(admin(), $doc, 'Erreur de date');
    cvRun();
    expect(Document::query()->where('enrolment_id', $y['lea']->id)->count())->toBe(1);
});

it('skips planned or cancelled sessions and cancelled enrolments', function () {
    cvSession('2026-12-08', 'planned');
    $x = cvSession('2026-12-07');
    $x['lea']->forceFill(['status' => EnrolmentStatus::Cancelled->value])->save();
    $x['tom']->forceFill(['status' => EnrolmentStatus::Cancelled->value])->save();

    expect(cvRun())->toBe(0)->and(Document::query()->count())->toBe(0);
});

it('does nothing when the setting is 0', function () {
    cvSession('2026-12-08');
    AutomationSettings::save(['convocation_days' => 0]);

    expect(cvRun())->toBe(0)->and(Document::query()->count())->toBe(0);
});

it('never convenes an abandoned trainee nor a person in the trash', function () {
    $x = cvSession('2026-12-08');
    $x['lea']->forceFill(['status' => EnrolmentStatus::Abandoned->value, 'entered_on' => null, 'left_on' => '2026-11-30',
        'abandon_reason_id' => crmItem(ReferenceList::AbandonReason, 'Maladie')->id])->save();
    Person::query()->whereKey($x['tom']->person_id)->firstOrFail()->delete();

    expect(cvRun())->toBe(0)->and(Document::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('issues without sending for a malformed address and goes on with the others', function () {
    $x = cvSession('2026-12-08');
    Person::query()->whereKey($x['lea']->person_id)->update(['email' => 'lea@']);
    $y = cvSession('2026-12-07');

    expect(cvRun())->toBe(1);
    expect(Document::query()->where('enrolment_id', $x['lea']->id)->sole()->status)->toBe(DocumentStatus::Issued)
        ->and(Document::query()->where('enrolment_id', $y['lea']->id)->sole()->status)->toBe(DocumentStatus::Sent);
});
