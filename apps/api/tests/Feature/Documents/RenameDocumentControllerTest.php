<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\RenameDocumentControllerTest;

use Kern\Audit\Models\AuditEvent;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `PATCH /api/v1/subjects/{subject}/documents/{document}` and the naming rules around it.
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

it('shows a hash-derived name until someone renames the document', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->part(['original_filename_ciphertext' => 'IMG_0042.jpg', 'mime_type' => 'image/jpeg'])->create(['tax_subject_id' => $subject->id, 'sha256' => str_repeat('a1b2c3d4', 8)]);

    $response = $this->actingAs($user, 'web')->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}");

    // The label is derived from the content, never from what the phone called it.
    $response->assertOk()->assertJson([
        'filename' => 'a1b2c3d4a1b2c3d4.jpg',
        'original_filename' => 'IMG_0042.jpg',
    ]);
});

it('renames a document and records the change without recording the name', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}", [
            'display_name' => 'chirie-popescu-2026-01.pdf',
        ])
        ->assertOk()
        ->assertJson(['filename' => 'chirie-popescu-2026-01.pdf']);

    $event = AuditEvent::query()->where('event_type', 'DocumentRenamed')->sole();
    expect($event->resource_id)->toBe($document->id);

    // The name is encrypted at rest precisely because it can carry a counterparty; an audit chain
    // that hashed it in clear would undo that.
    expect(json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('Popescu')
        ->and(json_encode($event->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('chirie');
});

it('stores the chosen name encrypted, never as readable text in the row', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}", [
            'display_name' => 'decizie-anaf-cass-2023.pdf',
        ])
        ->assertOk();

    $raw = (array) \Illuminate\Support\Facades\DB::table('documents')->where('id', $document->id)->sole();

    expect(json_encode($raw, JSON_THROW_ON_ERROR))->not->toContain('decizie-anaf-cass-2023');
});

it('sanitises a submitted name rather than trusting it', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    // Never used as a path, but it is rendered and exported, so separators and control characters
    // have no business surviving.
    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}", [
            'display_name' => "../../etc/passwd\x00.pdf",
        ])
        ->assertOk()
        ->assertJson(['filename' => 'passwd.pdf']);
});

it('returns the document to its hash-derived name when the name is cleared', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->part(['mime_type' => 'application/pdf'])->create(['tax_subject_id' => $subject->id, 'sha256' => str_repeat('f', 64)]);

    $url = "/api/v1/subjects/{$subject->id}/documents/{$document->id}";

    $this->actingAs($user, 'web')->patchJson($url, ['display_name' => 'ceva.pdf'])->assertOk();
    $this->actingAs($user, 'web')
        ->patchJson($url, ['display_name' => null])
        ->assertOk()
        ->assertJson(['filename' => 'ffffffffffffffff.pdf']);
});

it('does not let one subject rename another subject\'s document', function (): void {
    [$user, $subject] = memberAndSubject();
    $other = Document::factory()->create();

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/subjects/{$subject->id}/documents/{$other->id}", ['display_name' => 'mine.pdf'])
        ->assertStatus(404);
});
