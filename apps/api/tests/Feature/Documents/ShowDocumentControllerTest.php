<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ShowDocumentControllerTest;

use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}` — the authorised-HTTPS re-fetch `BLUEPRINT.md`
 * §16 expects the PWA frontend to call after a Reverb progress event, and never document content.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: TaxSubject} */
function memberAndSubject(): array
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

    return [$user, $subject];
}

it('returns document status metadata, never content', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    $response = $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}");

    $response->assertOk()->assertJson([
        'id' => $document->id,
        'status' => $document->status->value,
        'mime_type' => $document->firstPart()?->mime_type,
    ]);
    expect($response->json())->not->toHaveKey('original_filename_ciphertext')
        ->and($response->json())->not->toHaveKey('object_path');
});

it('returns 404 for a document belonging to a different subject', function (): void {
    [$userA, $subjectA] = memberAndSubject();
    [, $subjectB] = memberAndSubject();

    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB->id]);

    $response = $this->actingAs($userA, 'web')->getJson("/api/v1/subjects/{$subjectA->id}/documents/{$documentB->id}");

    $response->assertNotFound();
});

it('refuses access for a user with no membership on the subject at all', function (): void {
    [, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}");

    $response->assertStatus(403);
});
