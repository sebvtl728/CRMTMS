<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Documents\Texts\DocumentTexts;
use App\Modules\Documents\Texts\DocumentType;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    tenant();
    $this->company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
});

it('declares the blocks of the convention and the contract, the legal ones required', function () {
    expect(array_keys(DocumentTexts::blocks(DocumentType::Convention)))->toBe(['preambule', 'objet', 'nature_duree', 'effectif', 'moyens', 'sanction', 'prix', 'dedit', 'litiges'])
        ->and(array_keys(DocumentTexts::blocks(DocumentType::Contract)))->toBe(['objet', 'nature_duree', 'prerequis', 'moyens', 'sanction', 'prix', 'retractation', 'paiement', 'abandon'])
        ->and(DocumentTexts::blocks(DocumentType::Convention)['dedit']['required'])->toBeTrue()
        ->and(DocumentTexts::blocks(DocumentType::Contract)['retractation']['default'])->toContain('14 jours', 'L6353-5')
        ->and(DocumentTexts::blocks(DocumentType::Contract)['paiement']['default'])->toContain('30 %');
});

it('resolves the company text, else the organisme text, else the default', function () {
    $texts = app(DocumentTexts::class);
    $other = Company::factory()->create();
    $default = DocumentTexts::blocks(DocumentType::Convention)['litiges']['default'];

    expect($texts->resolve(DocumentType::Convention, $this->company->id)['litiges'])->toBe($default);

    $texts->save(DocumentType::Convention, null, 'litiges', 'Tribunal de Nantes.', admin());
    $texts->save(DocumentType::Convention, $this->company->id, 'litiges', 'Tribunal de Saint-Nazaire pour {societe}.', admin());

    expect($texts->resolve(DocumentType::Convention, $this->company->id)['litiges'])->toBe('Tribunal de Saint-Nazaire pour {societe}.')
        ->and($texts->resolve(DocumentType::Convention, $other->id)['litiges'])->toBe('Tribunal de Nantes.')
        ->and($texts->resolve(DocumentType::Convention, null)['litiges'])->toBe('Tribunal de Nantes.');

    $texts->reset(DocumentType::Convention, $this->company->id, 'litiges', admin());
    expect($texts->resolve(DocumentType::Convention, $this->company->id)['litiges'])->toBe('Tribunal de Nantes.');
});

it('refuses an unknown field, HTML, or an empty required block', function (string $block, string $body, string $message) {
    expect(fn () => app(DocumentTexts::class)->save(DocumentType::Convention, null, $block, $body, admin()))
        ->toThrow(ValidationException::class, $message);
})->with([
    ['litiges', 'Voir {xyz}.', 'Champ inconnu : {xyz}.'],
    ['litiges', 'Texte <b>gras</b>.', 'Le texte ne peut pas contenir de balises.'],
    ['dedit', '   ', 'Ce texte est obligatoire.'],
]);

it('fills fields literally, never as HTML', function () {
    expect(DocumentTexts::fill('Formation {formation} pour {stagiaire}, {inconnu}.', ['{formation}' => 'HACCP', '{stagiaire}' => '<script>x</script>']))
        ->toBe('Formation HACCP pour <script>x</script>, {inconnu}.');
});

it('lets only settings.manage see and edit the texts', function () {
    $this->actingAs(commercial())->get('/admin/document-texts')->assertForbidden();

    $this->actingAs(admin(twoFactor: true))->get('/admin/document-texts?type=contract&company='.$this->company->id)->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('admin/document-texts/index')
            ->where('type', 'contract')
            ->where('company_id', $this->company->id)
            ->where('blocks.6.key', 'retractation')
            ->where('blocks.6.required', true)
            ->where('blocks.6.overridden', false));

    $this->put('/admin/document-texts/contract/abandon', ['company_id' => $this->company->id, 'body' => 'En cas d\'abandon de {stagiaire}…'])
        ->assertSessionHasNoErrors();
    $this->put('/admin/document-texts/contract/abandon', ['company_id' => null, 'body' => 'Voir {xyz}.'])->assertSessionHasErrors('body');
    $this->put('/admin/document-texts/contract/nope', ['company_id' => null, 'body' => 'x'])->assertNotFound();
    $this->delete('/admin/document-texts/contract/abandon', ['company_id' => $this->company->id])->assertSessionHasNoErrors();
});
