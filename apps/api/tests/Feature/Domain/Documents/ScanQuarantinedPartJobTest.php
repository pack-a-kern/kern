<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ScanQuarantinedPartJobTest;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentScanned;
use App\Domain\Documents\Jobs\ProcessScannedDocument;
use App\Domain\Documents\Jobs\ScanQuarantinedPart;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentScan;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Documents\Scanning\ClamdVirusScanner;
use App\Domain\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use App\Domain\Documents\Scanning\ScanOutcome;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Support\KeyManagement\SubjectDekService;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\Exceptions\ObjectNotFoundException;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fakes\FakeVirusScanner;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\Fixtures\Documents\FileFixtures;

/**
 * `BLUEPRINT.md` §14.1/§14.2 ClamAV stage, and `docs/AGENTS.md` §21.4's mandatory test: an infected
 * document is rejected and its bytes never leave quarantine into the real object store; an
 * unreachable scanner blocks a document rather than passing it. `App\Domain\Documents\Scanning\
 * VirusScanner` is faked here to make both outcomes deterministic and fast — the real ClamAV
 * daemon's own EICAR detection is proven separately (live, env-gated) below, and the wire-protocol
 * client (`ClamdVirusScanner`) has its own fail-closed unit tests.
 */
beforeEach(function (): void {
    Bus::fake([ProcessScannedDocument::class]);

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );

    Storage::fake('quarantine');
    Storage::fake('s3');
});

function putInQuarantine(Document $document, string $content): ObjectKey
{
    $part = $document->parts->first();

    if ($part === null) {
        throw new RuntimeException('The document has no part to quarantine.');
    }

    $key = $part->objectKey();
    $stream = fopen('php://temp', 'w+b');

    if ($stream === false) {
        throw new RuntimeException('Unable to open a memory stream.');
    }

    fwrite($stream, $content);
    rewind($stream);

    app(QuarantineObjectStore::class)->put($key, $stream);
    fclose($stream);

    return $key;
}

function partId(Document $document): string
{
    $part = $document->parts->first();

    if ($part === null) {
        throw new RuntimeException('The document has no part to scan.');
    }

    return $part->id;
}

it('rejects an infected document, deletes it from quarantine, and never promotes it to the real store', function (): void {
    Event::fake([DocumentScanned::class]);

    $document = Document::factory()->create();
    $key = putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::INFECTED));

    ScanQuarantinedPart::dispatchSync(partId($document));

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->rejection_reason)->toBe(RejectionReason::INFECTED);

    expect(fn () => app(QuarantineObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);
    expect(fn () => app(EncryptedObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);

    Event::assertDispatched(DocumentScanned::class, function (DocumentScanned $event): bool {
        $payload = $event->broadcastWith();

        return $payload['status'] === 'REJECTED' && $payload['rejection_reason'] === 'INFECTED';
    });

    Bus::assertNotDispatched(ProcessScannedDocument::class);
});

it('promotes a clean document out of quarantine into the real store and continues the pipeline', function (): void {
    Event::fake([DocumentScanned::class]);

    $content = FileFixtures::minimalPdf();
    $document = Document::factory()->create();
    $key = putInQuarantine($document, $content);

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::CLEAN));

    ScanQuarantinedPart::dispatchSync(partId($document));

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::SCANNED);

    expect(fn () => app(QuarantineObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);

    $stream = app(EncryptedObjectStore::class)->get($key);
    expect(stream_get_contents($stream))->toBe($content);
    fclose($stream);

    Event::assertDispatched(DocumentScanned::class, function (DocumentScanned $event): bool {
        $payload = $event->broadcastWith();

        return $payload['status'] === 'SCANNED' && $payload['rejection_reason'] === null;
    });

    Bus::assertDispatched(ProcessScannedDocument::class);
});

it('blocks the document — never promoting it — when the scanner is unreachable', function (): void {
    Event::fake([DocumentScanned::class]);

    $content = FileFixtures::minimalPdf();
    $document = Document::factory()->create();
    $key = putInQuarantine($document, $content);

    $this->app->instance(VirusScanner::class, FakeVirusScanner::unavailable());

    expect(fn () => ScanQuarantinedPart::dispatchSync(partId($document)))
        ->toThrow(VirusScannerUnavailableException::class);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::QUARANTINE);

    // Still sitting in quarantine, untouched — never deleted, never promoted.
    $stream = app(QuarantineObjectStore::class)->get($key);
    expect(stream_get_contents($stream))->toBe($content);
    fclose($stream);

    expect(fn () => app(EncryptedObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);

    Event::assertNotDispatched(DocumentScanned::class);
    Bus::assertNotDispatched(ProcessScannedDocument::class);
});

/**
 * Same gating precedent as `ClamdVirusScannerLiveTest`: skipped unless `CLAMAV_TEST_HOST`/
 * `CLAMAV_TEST_PORT` are set. This is the strongest version of the mandatory infected-file test —
 * the real ClamAV daemon, through the real job, end to end, not a fake scanner standing in for it.
 */
function liveClamavUnavailable(): bool
{
    return getenv('CLAMAV_TEST_HOST') === false || getenv('CLAMAV_TEST_PORT') === false;
}

it('rejects a real EICAR-infected document via a real ClamAV daemon, through the real job, and it never leaves quarantine', function (): void {
    Event::fake([DocumentScanned::class]);

    $host = getenv('CLAMAV_TEST_HOST');
    $port = getenv('CLAMAV_TEST_PORT');
    $this->app->instance(VirusScanner::class, new ClamdVirusScanner(
        $host === false ? '' : $host,
        $port === false ? 0 : (int) $port,
        5.0,
        15.0,
        65536,
    ));

    $document = Document::factory()->create();
    $key = putInQuarantine($document, FileFixtures::eicarTestString());

    ScanQuarantinedPart::dispatchSync(partId($document));

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->rejection_reason)->toBe(RejectionReason::INFECTED);

    expect(fn () => app(QuarantineObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);
    expect(fn () => app(EncryptedObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);
})->skip(fn (): bool => liveClamavUnavailable(), 'Set CLAMAV_TEST_HOST/CLAMAV_TEST_PORT to run against a live ClamAV daemon.');

it('records what scanned the document, and against which definitions', function (): void {
    $document = Document::factory()->create();
    putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::CLEAN));

    ScanQuarantinedPart::dispatchSync(partId($document));

    $scan = DocumentScan::query()->where('document_id', $document->id)->sole();

    // The point of the row: "this document was scanned, then, by that engine, against those
    // definitions" — a question `documents.status` alone could never answer.
    expect($scan->outcome)->toBe(ScanOutcome::CLEAN)
        ->and($scan->scanner)->toBe('clamav')
        ->and($scan->scanner_version)->toBe('1.5.4')
        ->and($scan->definitions_version)->toBe('28118')
        ->and($scan->scanned_at)->not->toBeNull()
        ->and($scan->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('records the scan for an infected document too, not only a clean one', function (): void {
    $document = Document::factory()->create();
    putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::INFECTED));

    ScanQuarantinedPart::dispatchSync(partId($document));

    // A rejection is the outcome most worth being able to defend later.
    expect(DocumentScan::query()->where('document_id', $document->id)->sole()->outcome)
        ->toBe(ScanOutcome::INFECTED);
});

it('still records a scan when the scanner will not report its version', function (): void {
    $document = Document::factory()->create();
    putInQuarantine($document, FileFixtures::minimalPdf());

    // Unknown versions are recorded as null rather than blocking the document — an unattributed
    // scan is worth less than an attributed one and far more than none.
    $this->app->instance(VirusScanner::class, FakeVirusScanner::returningWithoutVersion(ScanOutcome::CLEAN));

    ScanQuarantinedPart::dispatchSync(partId($document));

    $scan = DocumentScan::query()->where('document_id', $document->id)->sole();

    expect($scan->outcome)->toBe(ScanOutcome::CLEAN)
        ->and($scan->scanner_version)->toBeNull()
        ->and($scan->definitions_version)->toBeNull();
});

it('writes no scan row when the scanner never produced a verdict', function (): void {
    $document = Document::factory()->create();
    putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, FakeVirusScanner::unavailable());

    expect(fn () => ScanQuarantinedPart::dispatchSync(partId($document)))
        ->toThrow(VirusScannerUnavailableException::class);

    // The absence of a row is the honest state: nothing scanned this document. A row saying
    // otherwise would be the one lie this table exists to prevent.
    expect(DocumentScan::query()->where('document_id', $document->id)->count())->toBe(0);
});

it('adds the scan to the subject audit chain without naming the malware', function (): void {
    $document = Document::factory()->create();
    putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, FakeVirusScanner::returning(ScanOutcome::INFECTED));

    ScanQuarantinedPart::dispatchSync(partId($document));

    $event = DB::table('audit_events')
        ->where('resource_id', $document->id)
        ->where('event_type', 'DocumentScanned')
        ->sole();

    expect($event->subject_id)->toBe($document->tax_subject_id)
        // No person scanned this. Recording the uploader would read as though they had.
        ->and($event->actor_id)->toBeNull();
});
