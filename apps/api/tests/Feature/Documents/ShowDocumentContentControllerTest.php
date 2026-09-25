<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ShowDocumentContentControllerTest;

use Illuminate\Support\Facades\Storage;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `GET .../documents/{document}/content` — the third of the three gaps
 * `docs/handoff/030-pwa-frontend.md`'s Key decision #2 and `docs/handoff/022-document-
 * pipeline.md`'s "What is NOT done" both flagged as missing: decrypting and streaming a
 * document's original bytes back to its owner.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('s3');
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

function storeOriginalBytes(Document $document, string $content): void
{
    $store = app(EncryptedObjectStore::class);
    // The first part's key: `/content` serves the document's first file, which is the whole
    // document for everything that has one.
    $part = $document->parts->first();

    if ($part === null) {
        throw new RuntimeException('The document has no part to store bytes for.');
    }

    $key = $part->objectKey();

    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Failed opening a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    try {
        $store->put($key, $stream);
    } finally {
        fclose($stream);
    }
}

it('streams the decrypted original bytes for a ready document', function (): void {
    [$user, $subject] = memberAndSubject();
    $content = 'REAL-DOCUMENT-BYTES-' . bin2hex(random_bytes(8));
    $document = Document::factory()->part(['mime_type' => 'application/pdf', 'original_filename_ciphertext' => 'factura.pdf'])->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);
    storeOriginalBytes($document, $content);

    $response = $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/content")
        ->assertOk();

    expect($response->streamedContent())->toBe($content);
    $response->assertHeader('Content-Type', 'application/pdf');
});

it('serves content for a scanned-but-not-yet-processed document too', function (): void {
    [$user, $subject] = memberAndSubject();
    $content = 'SCANNED-STAGE-BYTES';
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::SCANNED,
    ]);
    storeOriginalBytes($document, $content);

    $response = $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/content")
        ->assertOk();

    expect($response->streamedContent())->toBe($content);
});

it('refuses content for a document still in quarantine', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::QUARANTINE,
    ]);

    $response = $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/content")
        ->assertStatus(409);

    expect($response->json('reason'))->toBe('CONTENT_NOT_AVAILABLE');
});

it('refuses content for a rejected document', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::REJECTED,
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/content")
        ->assertStatus(409);
});

it('404s for a document belonging to a different subject', function (): void {
    [$userA, $subjectA] = memberAndSubject();
    [, $subjectB] = memberAndSubject();
    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB->id, 'status' => DocumentStatus::READY]);
    storeOriginalBytes($documentB, 'subject-b-secret-bytes');

    $this->actingAs($userA, 'web')
        ->getJson("/api/v1/subjects/{$subjectA->id}/documents/{$documentB->id}/content")
        ->assertNotFound();
});

it('refuses access for a user with no membership on the subject', function (): void {
    [, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);
    storeOriginalBytes($document, 'some-bytes');
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/content")
        ->assertStatus(403);
});
