<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ScanQuarantinedDocumentJobTest;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\RejectionReason;
use App\Domain\Documents\Events\DocumentScanned;
use App\Domain\Documents\Jobs\ProcessScannedDocument;
use App\Domain\Documents\Jobs\ScanQuarantinedDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Quarantine\QuarantineObjectStore;
use App\Domain\Documents\Scanning\ClamdVirusScanner;
use App\Domain\Documents\Scanning\Exceptions\VirusScannerUnavailableException;
use App\Domain\Documents\Scanning\ScanOutcome;
use App\Domain\Documents\Scanning\VirusScanner;
use App\Support\KeyManagement\SubjectDekService;
use App\Support\ObjectStore\DocumentObjectKind;
use App\Support\ObjectStore\EncryptedObjectStore;
use App\Support\ObjectStore\Exceptions\ObjectNotFoundException;
use App\Support\ObjectStore\ObjectKey;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
    $key = ObjectKey::forDocument($document->tax_subject_id, $document->id, DocumentObjectKind::ORIGINAL);
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

it('rejects an infected document, deletes it from quarantine, and never promotes it to the real store', function (): void {
    Event::fake([DocumentScanned::class]);

    $document = Document::factory()->create(['mime_type' => 'application/pdf']);
    $key = putInQuarantine($document, FileFixtures::minimalPdf());

    $this->app->instance(VirusScanner::class, new class () implements VirusScanner {
        public function scan($stream): ScanOutcome
        {
            return ScanOutcome::INFECTED;
        }
    });

    ScanQuarantinedDocument::dispatchSync($document->id);

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
    $document = Document::factory()->create(['mime_type' => 'application/pdf']);
    $key = putInQuarantine($document, $content);

    $this->app->instance(VirusScanner::class, new class () implements VirusScanner {
        public function scan($stream): ScanOutcome
        {
            return ScanOutcome::CLEAN;
        }
    });

    ScanQuarantinedDocument::dispatchSync($document->id);

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
    $document = Document::factory()->create(['mime_type' => 'application/pdf']);
    $key = putInQuarantine($document, $content);

    $this->app->instance(VirusScanner::class, new class () implements VirusScanner {
        public function scan($stream): ScanOutcome
        {
            throw VirusScannerUnavailableException::connectionFailed('clamav', 3310);
        }
    });

    expect(fn () => ScanQuarantinedDocument::dispatchSync($document->id))
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

    $document = Document::factory()->create(['mime_type' => 'application/pdf']);
    $key = putInQuarantine($document, FileFixtures::eicarTestString());

    ScanQuarantinedDocument::dispatchSync($document->id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->rejection_reason)->toBe(RejectionReason::INFECTED);

    expect(fn () => app(QuarantineObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);
    expect(fn () => app(EncryptedObjectStore::class)->get($key))->toThrow(ObjectNotFoundException::class);
})->skip(fn (): bool => liveClamavUnavailable(), 'Set CLAMAV_TEST_HOST/CLAMAV_TEST_PORT to run against a live ClamAV daemon.');
