<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\MultiPartDocumentTest;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Kern\Documents\Actions\InitiateDocumentUpload;
use Kern\Documents\DataTransferObjects\IncomingFile;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Enums\RejectionReason;
use Kern\Documents\Exceptions\DuplicateDocumentException;
use Kern\Documents\FileSecurity\Exceptions\FileSecurityException;
use Kern\Documents\Jobs\ProcessScannedDocument;
use Kern\Documents\Jobs\ScanQuarantinedPart;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentPart;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Documents\ObjectStore\Exceptions\ObjectNotFoundException;
use Kern\Documents\Quarantine\QuarantineObjectStore;
use Kern\Documents\Scanning\ScanOutcome;
use Kern\Documents\Scanning\VirusScanner;
use Kern\Documents\Testing\FileFixtures;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Tenancy\TenantContext;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;
use Tests\Fakes\FakeVirusScanner;

/**
 * A document is an ordered list of files (`document_parts`).
 *
 * The cases here are the ones the change exists for: several files become one document, scanning is
 * per file, and the document only moves on once every file has passed.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );

    Storage::fake('quarantine');
    Storage::fake('s3');

    // Faking a disk swaps it in the filesystem manager, but an object store resolved earlier in the
    // process still holds the real one — so the bytes go somewhere this test cannot see and every
    // read afterwards reports a missing manifest. Forgetting the singletons makes them pick up the
    // fake when the test resolves them.
    app()->forgetInstance(QuarantineObjectStore::class);
    app()->forgetInstance(EncryptedObjectStore::class);
    app()->forgetInstance(InitiateDocumentUpload::class);
});

/**
 * A subject with an owner, and the tenant context bound to them.
 *
 * `InitiateDocumentUpload`'s duplicate check is a tenant-scoped query, so it needs the same bound
 * context an HTTP request would have — these tests drive the action directly rather than through
 * the controller, so they bind it themselves.
 *
 * @return array{0: User, 1: TaxSubject}
 */
function ownerAndActiveSubject(): array
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

    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return [$user, $subject];
}

/**
 * @param  list<string>  $contents
 */
function upload(TaxSubject $subject, array $contents): Document
{
    $files = [];

    foreach ($contents as $index => $content) {
        $path = tempnam(sys_get_temp_dir(), 'part');
        file_put_contents($path, $content);
        $files[] = new IncomingFile($path, 'page-' . (string) ($index + 1) . '.pdf');
    }

    return app(InitiateDocumentUpload::class)($subject->id, DocumentType::INVOICE, $files);
}

/**
 * A two-page document's parts, in order.
 *
 * @return array{0: DocumentPart, 1: DocumentPart}
 */
function pages(Document $document): array
{
    $parts = $document->parts->all();

    if (! ($parts[0] ?? null) instanceof DocumentPart || ! ($parts[1] ?? null) instanceof DocumentPart) {
        throw new RuntimeException('Expected a document of two pages.');
    }

    return [$parts[0], $parts[1]];
}

/** Distinct bytes per page, so two pages are never accidentally the same file. */
function scannableDocument(): Document
{
    $document = Document::factory()->withParts(2)->create(['status' => DocumentStatus::QUARANTINE]);

    foreach ($document->parts as $part) {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open a memory stream.');
        }

        fwrite($stream, page($part->position));
        rewind($stream);
        app(QuarantineObjectStore::class)->put($part->objectKey(), $stream);
        fclose($stream);
    }

    return $document;
}

function page(int $number): string
{
    return FileFixtures::minimalPdf() . "\n% page " . (string) $number;
}

it('makes one document out of several files, in the order they were given', function (): void {
    Bus::fake([ScanQuarantinedPart::class]);
    [, $subject] = ownerAndActiveSubject();

    $document = upload($subject, [page(1), page(2), page(3)]);

    expect($document->part_count)->toBe(3)
        ->and($document->parts)->toHaveCount(3)
        ->and($document->parts->pluck('position')->all())->toBe([1, 2, 3])
        ->and($document->parts->pluck('original_filename_ciphertext')->all())
        ->toBe(['page-1.pdf', 'page-2.pdf', 'page-3.pdf']);

    // One scan job per file: ClamAV scans bytes, and a document-level scan would look at page 1 and
    // wave the rest through.
    Bus::assertDispatchedTimes(ScanQuarantinedPart::class, 3);
});

it('identifies a document by its parts in order, so the same pages reversed are a different document', function (): void {
    Bus::fake([ScanQuarantinedPart::class]);
    [, $subject] = ownerAndActiveSubject();

    $forwards = upload($subject, [page(1), page(2)]);
    $backwards = upload($subject, [page(2), page(1)]);

    expect($backwards->sha256)->not->toBe($forwards->sha256);

    // And the identical upload is refused, which is the point of hashing them together at all.
    expect(fn () => upload($subject, [page(1), page(2)]))->toThrow(DuplicateDocumentException::class);
});

it('holds the document at QUARANTINE until every one of its files has been scanned', function (): void {
    Bus::fake([ProcessScannedDocument::class]);
    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::CLEAN));

    $document = scannableDocument();
    [$first, $second] = pages($document);

    ScanQuarantinedPart::dispatchSync($first->id);

    // One clean page is not a clean document.
    expect($document->fresh()?->status)->toBe(DocumentStatus::QUARANTINE);
    Bus::assertNotDispatched(ProcessScannedDocument::class);

    ScanQuarantinedPart::dispatchSync($second->id);

    expect($document->fresh()?->status)->toBe(DocumentStatus::SCANNED);
    Bus::assertDispatchedTimes(ProcessScannedDocument::class, 1);
});

it('rejects the whole document when one of its files is infected, and never promotes that file', function (): void {
    Bus::fake([ProcessScannedDocument::class]);

    $document = scannableDocument();
    [$first, $second] = pages($document);

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::CLEAN));
    ScanQuarantinedPart::dispatchSync($first->id);

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::INFECTED));
    ScanQuarantinedPart::dispatchSync($second->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->rejection_reason)->toBe(RejectionReason::INFECTED);

    // The clean page keeps its own verdict and its bytes — a person looking at a refused invoice
    // should be able to see which page was the problem.
    expect($first->fresh()?->status)->toBe(DocumentStatus::SCANNED)
        ->and($second->fresh()?->status)->toBe(DocumentStatus::REJECTED);

    expect(fn () => app(EncryptedObjectStore::class)->get($second->objectKey()))
        ->toThrow(ObjectNotFoundException::class);
    expect(fn () => app(QuarantineObjectStore::class)->get($second->objectKey()))
        ->toThrow(ObjectNotFoundException::class);

    Bus::assertNotDispatched(ProcessScannedDocument::class);
});

it('stores nothing at all when one file of an upload fails validation', function (): void {
    Bus::fake([ScanQuarantinedPart::class]);
    [, $subject] = ownerAndActiveSubject();

    // A document missing page 2 is worse than no document: the model would read what is left and
    // answer confidently about a document nobody has all of.
    expect(fn () => upload($subject, [page(1), 'MZ not a document at all']))->toThrow(FileSecurityException::class);

    expect(Document::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('quarantine')->allFiles())->toBe([]);
});
