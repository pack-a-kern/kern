<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\UploadDocumentControllerTest;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentUploaded;
use App\Domain\Documents\Jobs\ScanQuarantinedPart;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\ObjectStore\EncryptedObjectStore;
use Kern\Platform\Tenancy\TenantScope;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\Fakes\UnwritableDisk;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * `POST /api/v1/subjects/{subject}/documents` — `BLUEPRINT.md` §14.1's upload-ticket step end to
 * end through real HTTP, real Eloquent, and a fake (but real-`Filesystem`-backed) quarantine disk.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('quarantine');
    Storage::fake('s3');

    Bus::fake([ScanQuarantinedPart::class]);
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

it('accepts a valid PDF, quarantines it, and dispatches the scan job', function (): void {
    Event::fake([DocumentUploaded::class]);
    [$user, $subject] = memberAndSubject();

    $file = UploadedFile::fake()->createWithContent('invoice.pdf', FileFixtures::minimalPdf());

    $response = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'INVOICE',
    ]);

    $response->assertStatus(202);
    $documentId = jsonString($response, 'id');
    expect(jsonString($response, 'status'))->toBe('QUARANTINE');

    $document = Document::query()->findOrFail($documentId);
    expect($document->tax_subject_id)->toBe($subject->id)
        ->and($document->part_count)->toBe(1)
        ->and($document->firstPart()?->mime_type)->toBe('application/pdf')
        ->and($document->status)->toBe(DocumentStatus::QUARANTINE);

    $part = $document->parts->sole();
    $stream = app(QuarantineObjectStore::class)->get($part->objectKey());
    expect(stream_get_contents($stream))->toBe(FileFixtures::minimalPdf());
    fclose($stream);

    // Never reachable through the real store before scanning.
    expect(Storage::disk('s3')->allFiles())->toBe([]);

    Bus::assertDispatched(ScanQuarantinedPart::class, fn (ScanQuarantinedPart $job): bool => $job->documentPartId === $part->id);

    Event::assertDispatched(DocumentUploaded::class, function (DocumentUploaded $event) use ($document): bool {
        $payload = $event->broadcastWith();

        return $payload['document_id'] === $document->id && $payload['status'] === 'QUARANTINE';
    });
});

it('rejects a file whose magic bytes contradict its declared extension, creating no document row', function (): void {
    [$user, $subject] = memberAndSubject();

    $file = UploadedFile::fake()->createWithContent('sneaky.pdf', FileFixtures::pngWithDimensions(10, 10));

    $response = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'INVOICE',
    ]);

    $response->assertStatus(422)->assertJson(['reason' => 'EXTENSION_MISMATCH']);
    expect(Document::query()->count())->toBe(0);
    expect(Storage::disk('quarantine')->allFiles())->toBe([]);
});

it('rejects a file type it does not recognize, creating no document row', function (): void {
    [$user, $subject] = memberAndSubject();

    $file = UploadedFile::fake()->createWithContent('notes.pdf', 'just plain text content');

    $response = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'OTHER',
    ]);

    $response->assertStatus(422)->assertJson(['reason' => 'UNSUPPORTED_FILE_TYPE']);
    expect(Document::query()->count())->toBe(0);
});

it('rejects an oversized image using the configured limit', function (): void {
    config(['documents.max_image_bytes' => 10]);
    [$user, $subject] = memberAndSubject();

    $file = UploadedFile::fake()->createWithContent('receipt.png', FileFixtures::pngWithDimensions(10, 10) . str_repeat('x', 200));

    $response = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'RECEIPT',
    ]);

    $response->assertStatus(422)->assertJson(['reason' => 'FILE_TOO_LARGE']);
    expect(Document::query()->count())->toBe(0);
});

it('rejects an image exceeding the configured pixel-count ceiling', function (): void {
    config(['documents.max_image_pixels' => 100]);
    [$user, $subject] = memberAndSubject();

    $file = UploadedFile::fake()->createWithContent('receipt.png', FileFixtures::pngWithDimensions(50, 50));

    $response = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'RECEIPT',
    ]);

    $response->assertStatus(422)->assertJson(['reason' => 'IMAGE_PIXEL_LIMIT_EXCEEDED']);
    expect(Document::query()->count())->toBe(0);
});

it('refuses an upload for a subject the authenticated user has no membership for', function (): void {
    [, $subject] = memberAndSubject();
    $outsider = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent('invoice.pdf', FileFixtures::minimalPdf());

    $response = $this->actingAs($outsider, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => $file,
        'type' => 'INVOICE',
    ]);

    $response->assertStatus(403);
    // No TenantContext was ever bound during that rejected request (authorization failed before
    // it could be) — an unscoped, explicit system-level count is the correct way to check "no
    // document exists anywhere," matching Kern\Platform\Tenancy\ScopedToTenant's own
    // documented escape hatch for genuinely cross-tenant checks.
    expect(Document::withoutGlobalScope(TenantScope::class)->count())->toBe(0);
});

it('leaves no document row behind when the bytes cannot be stored', function (): void {
    [$user, $subject] = memberAndSubject();

    // A disk that accepts nothing — a missing bucket, a full volume, MinIO down. This reproduces
    // exactly the failure a missing `kern-quarantine` bucket produced: `EncryptedObjectStore`
    // raises "Failed writing object … to the object store disk."
    //
    // Before the row and the bytes were committed together, that left a `documents` row with
    // nothing behind it — the upload answered 500 while the document appeared in the inbox
    // forever, permanently QUARANTINE, with no content to scan and no way to advance it.
    $this->app->instance(QuarantineObjectStore::class, new QuarantineObjectStore(
        new EncryptedObjectStore(new UnwritableDisk(), $this->app->make(SubjectDekService::class), 4 * 1024 * 1024),
    ));

    // A genuinely valid PDF, so the file-security validator passes and the *storage* failure is
    // what this test is actually exercising.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents", [
            'type' => 'RECEIPT',
            'file' => UploadedFile::fake()->createWithContent('bon.pdf', FileFixtures::minimalPdf()),
        ])
        ->assertStatus(500);

    expect(Document::withoutGlobalScope(TenantScope::class)->count())->toBe(0);

    // And nothing downstream was told about a document that does not exist.
    Bus::assertNotDispatched(ScanQuarantinedPart::class);
});

it('records the upload in the subject audit chain, attributed to the uploader', function (): void {
    [$user, $subject] = memberAndSubject();

    $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('factura.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ])->assertStatus(202);

    $event = DB::table('audit_events')
        ->where('event_type', 'DocumentUploaded')
        ->sole();

    // Ingestion used to leave no audit trace at all — only the review half of the pipeline was
    // recorded, in a system whose whole premise is provenance.
    expect($event->subject_id)->toBe($subject->id)
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->resource_type)->toBe('Document');
});

it('keeps the filename out of the audit payload it hashes', function (): void {
    [$user, $subject] = memberAndSubject();

    $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('cnp-1234567890123.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ])->assertStatus(202);

    // The filename is encrypted at rest; an audit chain that carried it in clear — or hashed a
    // payload containing it — would quietly undo that. The hash is over type, MIME, size and
    // content digest only, so an identical file uploaded under a different name hashes identically.
    $row = DB::table('audit_events')->where('event_type', 'DocumentUploaded')->sole();
    $serialised = json_encode($row, JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain('cnp-1234567890123');
});

/*
 * Duplicate detection, on content rather than on name.
 *
 * `documents.sha256` had been recorded since the table was created and never read — so uploading
 * the same file twice quietly produced two documents, two scans, and two AI readings of identical
 * bytes, with nothing telling the person they already had it.
 */

it('refuses a byte-identical re-upload and points at the document already held', function (): void {
    [$user, $subject] = memberAndSubject();

    $first = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('factura.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ]);
    $first->assertStatus(202);
    $existingId = jsonString($first, 'id');

    // Same bytes, different name — the name is not what makes two files the same file.
    $second = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('factura-copie.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ]);

    $second->assertStatus(409)->assertJson([
        'reason' => 'DUPLICATE_DOCUMENT',
        'existing_document_id' => $existingId,
    ]);

    // Refused before anything was written: one row, one object, one scan.
    expect(Document::query()->where('tax_subject_id', $subject->id)->count())->toBe(1);
});

it('does not treat another subject\'s identical file as a duplicate', function (): void {
    [$user, $subject] = memberAndSubject();
    [$otherUser, $otherSubject] = memberAndSubject();

    $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('factura.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ])->assertStatus(202);

    // Two taxpayers can hold the same document — a shared invoice, the same ANAF decision. The
    // check is scoped to the subject, never global, which a cross-tenant hash lookup would be.
    $this->actingAs($otherUser, 'web')->post("/api/v1/subjects/{$otherSubject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('factura.pdf', FileFixtures::minimalPdf()),
        'type' => 'INVOICE',
    ])->assertStatus(202);

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('lets a deleted document be uploaded again', function (): void {
    [$user, $subject] = memberAndSubject();

    $first = $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('bon.pdf', FileFixtures::minimalPdf()),
        'type' => 'RECEIPT',
    ]);
    $first->assertStatus(202);

    $this->actingAs($user, 'web')
        ->deleteJson("/api/v1/subjects/{$subject->id}/documents/" . jsonString($first, 'id'))
        ->assertStatus(204);

    // A soft-deleted row must not become an invisible tombstone that refuses the file forever.
    $this->actingAs($user, 'web')->post("/api/v1/subjects/{$subject->id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('bon.pdf', FileFixtures::minimalPdf()),
        'type' => 'RECEIPT',
    ])->assertStatus(202);
});
