<?php

use App\Modules\Questionnaires\Surveys\SurveyCampaign;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use Illuminate\Support\Str;

function surveyCampaignRow(array $overrides = []): SurveyCampaign
{
    return SurveyCampaign::query()->create([
        'training_session_id' => (string) Str::uuid7(), 'kind' => 'hot', 'audience' => 'trainee', 'title' => 'À chaud',
        'questions' => [], 'opened_at' => now(), 'closes_on' => now()->addDays(30)->toDateString(), ...$overrides,
    ]);
}

it('keeps the invitations of an organisme out of another one', function () {
    $tenant = inTenant();
    [$plain, $hash] = SurveyInvitation::newToken();
    surveyCampaignRow()->invitations()->create(['person_id' => (string) Str::uuid7(), 'token_hash' => $hash, 'email_status' => 'none']);
    expect(SurveyInvitation::findByToken($plain))->not->toBeNull();

    inTenant(tenantOnHost('autre.example.test'));

    expect(SurveyInvitation::findByToken($plain))->toBeNull()
        ->and(SurveyCampaign::query()->count())->toBe(0);
});

it('finds nothing for a malformed or unknown token', function () {
    inTenant();

    expect(SurveyInvitation::findByToken('abc'))->toBeNull()
        ->and(SurveyInvitation::findByToken(str_repeat('a', 64)))->toBeNull()
        ->and(SurveyCampaign::findByQrToken(str_repeat('Z', 64)))->toBeNull();
});

it('finds a campaign by its QR token and closes it after its last day', function () {
    inTenant();
    $plain = bin2hex(random_bytes(32));
    $campaign = surveyCampaignRow(['qr_token_hash' => hash('sha256', $plain), 'closes_on' => now('Europe/Paris')->toDateString()]);

    expect(SurveyCampaign::findByQrToken($plain)?->id)->toBe($campaign->id)
        ->and($campaign->isOpen())->toBeTrue();
    $this->travel(1)->days();
    expect($campaign->fresh()->isOpen())->toBeFalse();
});

it('keeps one campaign per session, type and public', function () {
    inTenant();
    $campaign = surveyCampaignRow();

    expect(fn () => surveyCampaignRow(['training_session_id' => $campaign->training_session_id]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
