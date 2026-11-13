<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Crm\Timeline\Note;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

it('registers the four CRM checks', function () {
    expect(LinkedData::keys())->toContain('crm.affiliations', 'crm.notes', 'crm.attachments', 'crm.consents');
});

it('answers false for a fiche with nothing linked, true once a note hangs on it', function () {
    $org = Organisation::query()->create(['name' => 'Garage Martin']);
    expect(LinkedData::any($org))->toBeFalse();

    $note = new Note(['body' => 'Appel']);
    $note->notable()->associate($org);
    $note->save();

    expect(LinkedData::any($org))->toBeTrue()
        // The ids passed as exceptions are not counted.
        ->and(LinkedData::any($org, [$note->id]))->toBeFalse();
});

it('lets another module block the undo of an import through the registry', function () {
    $batch = runImport("Raison sociale\nGarage Martin\nCafé des Arts\n", ImportTarget::Organisations);
    $garage = Organisation::query()->where('name', 'Garage Martin')->sole();
    $seen = [];
    LinkedData::register('test.fake_module', function (Organisation|Person $fiche, array $exceptIds) use ($garage, &$seen): bool {
        $seen[] = $fiche->getKey();

        return $fiche->getKey() === $garage->id;
    });

    $this->travel(1)->minutes();
    UndoImport::dispatch($batch->id);

    expect(Organisation::query()->pluck('name')->all())->toBe(['Garage Martin'])
        ->and(Organisation::onlyTrashed()->pluck('name')->all())->toBe(['Café des Arts'])
        ->and(rowMessages($batch, 2))->toBe([UndoImport::KEPT_LINKED])
        ->and($seen)->toContain($garage->id);
});

it('starts each test with only the registered checks (test-resettable)', function () {
    expect(LinkedData::keys())->not->toContain('test.fake_module');

    LinkedData::register('test.other', fn () => true);
    LinkedData::forget('test.other');

    expect(LinkedData::keys())->not->toContain('test.other');
});
