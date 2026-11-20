<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function auditDump(): string
{
    return json_encode(AuditLog::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);
}

const PERSONAL_MASK = '[donnée personnelle]';

it('records a name change as the field name with the personal-data mask, never the value', function () {
    $person = makePerson('Dupont', ['first_name' => 'Marie']);

    $person->update(['last_name' => 'Martin-Secret']);

    $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', $person->id)->sole();
    expect($log->old_values)->toBe(['last_name' => PERSONAL_MASK])
        ->and($log->new_values)->toBe(['last_name' => PERSONAL_MASK])
        ->and(auditDump())->not->toContain('Martin-Secret')->not->toContain('Dupont')->not->toContain('Marie');
});

it('masks every personal field on creation and deletion but keeps business fields readable', function () {
    $person = makePerson('Durand', [
        'first_name' => 'Paul', 'email' => 'paul@example.org', 'email_secondary' => 'p2@example.org', 'phone' => '0612345678', 'mobile' => '0712345678',
        'address_line1' => '3 rue Secrète', 'address_line2' => 'Bât B', 'postal_code' => '69001', 'city' => 'Lyon',
        'birth_date' => '1980-02-03', 'project' => 'Devenir sommelier', 'future_needs' => 'Un BTS en 2027', 'status' => 'client', 'is_individual' => true,
    ]);
    $person->delete();

    $created = AuditLog::query()->where('event', 'created')->where('auditable_id', $person->id)->sole();
    $deleted = AuditLog::query()->where('event', 'deleted')->where('auditable_id', $person->id)->sole();

    foreach (Person::PERSONAL_FIELDS as $field) {
        expect($created->new_values[$field])->toBe(PERSONAL_MASK, $field)
            ->and($deleted->old_values[$field])->toBe(PERSONAL_MASK, $field);
    }
    expect($created->new_values['status'])->toBe('client')
        ->and($created->new_values['is_individual'])->toBeTrue();

    $dump = auditDump();
    foreach (['paul@example.org', '3 rue Secrète', 'sommelier', '1980-02-03', '+33612345678', 'Durand', 'Lyon'] as $leak) {
        expect($dump)->not->toContain($leak);
    }
});

it('does not invent a value for unset personal fields', function () {
    $person = makePerson('Seul');

    $created = AuditLog::query()->where('event', 'created')->where('auditable_id', $person->id)->sole();

    expect($created->new_values['last_name'])->toBe(PERSONAL_MASK)
        ->and($created->new_values)->not->toHaveKey('email');
});

it('masks the work contact of an affiliation', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $affiliation = affiliate(makePerson('Roux'), $org, ['job_title' => 'Directrice', 'work_email' => 'roux@hotel.fr', 'work_phone' => '0472001122']);
    $affiliation->update(['work_email' => 'autre@hotel.fr', 'job_title' => 'Gérante']);

    $logs = AuditLog::query()->where('auditable_id', $affiliation->id)->orderBy('created_at')->get();

    expect($logs->last()->new_values)->toEqualCanonicalizing(['work_email' => PERSONAL_MASK, 'job_title' => 'Gérante'])
        ->and(json_encode($logs->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('roux@hotel.fr')->not->toContain('autre@hotel.fr')->not->toContain('+33472001122');
});

it('audits through the web screens without leaking either', function () {
    $this->actingAs(commercial())->post('/crm/people', ['last_name' => 'Webleak', 'first_name' => 'Zoé', 'email' => 'zoe@leak.test', 'is_individual' => true])->assertRedirect();

    $dump = auditDump();
    expect($dump)->not->toContain('Webleak')->not->toContain('zoe@leak.test')->toContain(PERSONAL_MASK);
});

it('does not change the audit of other models', function () {
    $org = Organisation::query()->create(['name' => 'Visible SA', 'email' => 'contact@visible.fr']);
    $org->update(['name' => 'Visible SAS']);

    $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', $org->id)->sole();
    expect($log->new_values)->toBe(['name' => 'Visible SAS'])->and($log->old_values)->toBe(['name' => 'Visible SA']);
});
