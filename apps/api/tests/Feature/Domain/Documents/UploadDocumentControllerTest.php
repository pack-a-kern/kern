<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\UploadDocumentControllerTest;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Events\DocumentUploaded;
use App\Domain\Documents\Jobs\ScanQuarantinedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TaxSubjectScope;
use App\Support\KeyManagement\SubjectDekService;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
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

    Bus::fake([ScanQuarantinedDocument::class]);
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
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->status)->toBe(DocumentStatus::QUARANTINE);

    $key = ObjectKey::forDocument($subject->id, $document->id, DocumentObjectKind::ORIGINAL);
    $stream = app(QuarantineObjectStore::class)->get($key);
    expect(stream_get_contents($stream))->toBe(FileFixtures::minimalPdf());
    fclose($stream);

    // Never reachable through the real store before scanning.
    expect(Storage::disk('s3')->allFiles())->toBe([]);

    Bus::assertDispatched(ScanQuarantinedDocument::class, fn (ScanQuarantinedDocument $job): bool => $job->documentId === $document->id);

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
    // document exists anywhere," matching App\Domain\Subjects\Tenancy\ScopedToTaxSubject's own
    // documented escape hatch for genuinely cross-tenant checks.
    expect(Document::withoutGlobalScope(TaxSubjectScope::class)->count())->toBe(0);
});
