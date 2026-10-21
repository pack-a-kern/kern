<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\EnhanceImageConsentTest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Kern\Documents\Consent\DocumentAiConsentGate;
use Kern\Documents\Testing\FileFixtures;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `POST .../documents/enhance` sends a photograph to the AI provider, so it asks the subject's consent
 * first — the same gate the extraction path uses. It once did not: a provider being configured was
 * taken to mean every taxpayer had agreed, and a subject that never consented had its photographs
 * sent anyway. "Nothing sent" is asserted on the HTTP client itself, not inferred from the status.
 */
beforeEach(function (): void {
    config([
        'ai.providers.supports_enhancement' => true,
        'ai.providers.defaults.enabled' => true,
        'ai.providers.defaults.provider' => 'openai',
        'ai.providers.defaults.base_url' => 'https://model.test',
        'ai.providers.defaults.model' => 'test-model',
        'ai.providers.defaults.api_key' => 'k',
    ]);

    Http::preventStrayRequests();
    Http::fake(['https://model.test/*' => Http::response(['choices' => []])]);
});

/** @return array{0: User, 1: string} */
function enhanceSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject->id];
}

function photograph(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('bon.png', FileFixtures::pngWithDimensions(4, 4));
}

it('refuses a subject that never consented, and sends nothing', function (): void {
    [$user, $subjectId] = enhanceSubject();

    $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/documents/enhance", ['file' => photograph()], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJson(['reason' => 'AI_CONSENT_REQUIRED']);

    Http::assertNothingSent();
});

it('refuses a subject that revoked its consent, and sends nothing', function (): void {
    [$user, $subjectId] = enhanceSubject();
    $gate = app(DocumentAiConsentGate::class);
    $gate->grant($subjectId);
    $gate->revoke($subjectId);

    $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/documents/enhance", ['file' => photograph()], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJson(['reason' => 'AI_CONSENT_REQUIRED']);

    Http::assertNothingSent();
});

it('sends the photograph once the subject has consented', function (): void {
    [$user, $subjectId] = enhanceSubject();
    app(DocumentAiConsentGate::class)->grant($subjectId);

    $this->actingAs($user)
        ->post("/api/v1/subjects/{$subjectId}/documents/enhance", ['file' => photograph()], ['Accept' => 'application/json']);

    // Whatever the provider made of an empty reply, the request went out — which is what consent allows.
    Http::assertSentCount(1);
});
