<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ShowDocumentDerivativeContentControllerTest;

use Illuminate\Support\Facades\Storage;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentDerivative;
use Kern\Documents\ObjectStore\DocumentObjectKind;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\ObjectKey;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * `GET .../documents/{document}/derivatives/{kind}/content` — what the installation made *from* a
 * document, as opposed to the upload itself.
 *
 * Until this existed, a statement PDF converted to CSV produced a file only the importer could read:
 * written, encrypted, recorded, and reachable by nothing.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
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

/** Writes the bytes and the row that records them, the way the statement converter does. */
function storeDerivative(Document $document, DocumentObjectKind $kind, string $content): void
{
    $store = app(EncryptedObjectStore::class);
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, $kind);
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

    DocumentDerivative::query()->create([
        'document_id' => $document->id,
        'kind' => $kind,
        'object_path' => $key->value,
        'sha256' => hash('sha256', $content),
        'encryption_version' => 1,
    ]);
}

function readyDocument(TaxSubject $subject): Document
{
    return Document::factory()
        ->part(['mime_type' => 'application/pdf', 'original_filename_ciphertext' => 'extras.pdf'])
        ->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::READY]);
}

it('streams the decrypted derivative to the document owner', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = readyDocument($subject);
    $csv = "data,suma\n2026-03-01,-64.08\n";

    storeDerivative($document, DocumentObjectKind::CONVERTED_CSV, $csv);

    $response = $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/derivatives/converted_csv/content")
        ->assertOk();

    expect($response->streamedContent())->toBe($csv);
    $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

it('refuses a kind this document has no row for', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = readyDocument($subject);

    storeDerivative($document, DocumentObjectKind::CONVERTED_CSV, 'a,b');

    // Seven of the eight kinds are never written. Asking for one is a 404 about this document, not
    // an error about the kind — which is why the row is what decides, rather than the store.
    $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/derivatives/ocr/content")
        ->assertNotFound();
});

it('refuses a kind that is not in the closed set', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = readyDocument($subject);

    // The guarantee the enum exists for: a caller cannot name a path segment.
    $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/derivatives/..%2Fsecrets/content")
        ->assertNotFound();
});

it('does not serve one subject a derivative of another subject', function (): void {
    [$user, $subject] = memberAndSubject();
    [, $other] = memberAndSubject();
    $document = readyDocument($other);

    storeDerivative($document, DocumentObjectKind::CONVERTED_CSV, 'somebody else,data');

    // Not found rather than forbidden: the tenant scope means another subject's document does not
    // exist from here, and answering "forbidden" would confirm that it does.
    $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/derivatives/converted_csv/content")
        ->assertNotFound();
});

it('says a quarantined document has nothing to serve yet', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()
        ->part(['mime_type' => 'application/pdf', 'original_filename_ciphertext' => 'extras.pdf'])
        ->create(['tax_subject_id' => $subject->id, 'status' => DocumentStatus::QUARANTINE]);

    storeDerivative($document, DocumentObjectKind::CONVERTED_CSV, 'a,b');

    $this->actingAs($user, 'web')
        ->get("/api/v1/subjects/{$subject->id}/documents/{$document->id}/derivatives/converted_csv/content")
        ->assertStatus(409)
        ->assertJsonPath('reason', 'CONTENT_NOT_AVAILABLE');
});

it('lists what was made from a document beside what it is made of', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = readyDocument($subject);

    storeDerivative($document, DocumentObjectKind::CONVERTED_CSV, 'a,b');

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('derivatives.0.kind', 'converted_csv')
        ->assertJsonCount(1, 'derivatives')
        ->assertJsonCount(1, 'parts');
});

it('reports no derivatives for a document nothing was made from', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = readyDocument($subject);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}")
        ->assertOk()
        ->assertJsonCount(0, 'derivatives');
});
